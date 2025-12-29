<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages OAuth 2.0 token acquisition and refresh.
 *
 * Features:
 * - Automatic token refresh before expiry
 * - In-memory token caching
 * - Support for client_credentials and refresh_token grants
 * - Secure credential retrieval from vault
 */
final class OAuthTokenManager
{
    /**
     * Cached tokens indexed by config hash.
     *
     * @var array<string, OAuthToken>
     */
    private array $tokenCache = [];

    private ClientInterface $httpClient;

    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly ?LoggerInterface $logger = null,
        ?ClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Get a valid access token for the given OAuth config.
     *
     * Automatically refreshes the token if it's expired or about to expire.
     *
     * @throws VaultException If token cannot be obtained
     */
    public function getAccessToken(OAuthConfig $config): string
    {
        $cacheKey = $this->getCacheKey($config);

        // Check cache
        if (isset($this->tokenCache[$cacheKey])) {
            $cachedToken = $this->tokenCache[$cacheKey];

            if (!$cachedToken->isExpired($config->tokenExpiryBuffer)) {
                return $cachedToken->accessToken;
            }

            // Token expired or about to expire, refresh it
            $this->logger?->debug('OAuth token expired or about to expire, refreshing');
        }

        // Fetch new token
        $token = $this->fetchToken($config);
        $this->tokenCache[$cacheKey] = $token;

        return $token->accessToken;
    }

    /**
     * Clear the token cache for a specific config or all configs.
     */
    public function clearCache(?OAuthConfig $config = null): void
    {
        if ($config !== null) {
            $cacheKey = $this->getCacheKey($config);
            unset($this->tokenCache[$cacheKey]);
        } else {
            $this->tokenCache = [];
        }
    }

    /**
     * Fetch a new token from the OAuth server.
     *
     * @throws VaultException If token request fails
     */
    private function fetchToken(OAuthConfig $config): OAuthToken
    {
        // Get credentials from vault
        $clientId = $this->vaultService->retrieve($config->clientIdSecret);
        if ($clientId === null) {
            throw new SecretNotFoundException($config->clientIdSecret);
        }

        $clientSecret = $this->vaultService->retrieve($config->clientSecretSecret);
        if ($clientSecret === null) {
            sodium_memzero($clientId);

            throw new SecretNotFoundException($config->clientSecretSecret);
        }

        // Build token request
        $params = [
            'grant_type' => $config->grantType,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        // Add scopes if specified
        if (!empty($config->scopes)) {
            $params['scope'] = $config->getScopesString();
        }

        // Add refresh token if using refresh_token grant
        if ($config->grantType === 'refresh_token' && $config->refreshTokenSecret !== null) {
            $refreshToken = $this->vaultService->retrieve($config->refreshTokenSecret);
            if ($refreshToken === null) {
                sodium_memzero($clientId);
                sodium_memzero($clientSecret);

                throw new SecretNotFoundException($config->refreshTokenSecret);
            }
            $params['refresh_token'] = $refreshToken;
        }

        // Add any additional parameters
        $params = array_merge($params, $config->additionalParams);

        try {
            $response = $this->httpClient->request('POST', $config->tokenEndpoint, [
                'form_params' => $params,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            // Clear credentials from memory
            sodium_memzero($clientId);
            sodium_memzero($clientSecret);
            if (isset($refreshToken)) {
                sodium_memzero($refreshToken);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw new VaultException(\sprintf(
                    'OAuth token request failed with status %d',
                    $statusCode,
                ));
            }

            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!isset($body['access_token'])) {
                throw new VaultException('OAuth response missing access_token');
            }

            $expiresIn = $body['expires_in'] ?? 3600;
            $expiresAt = new DateTimeImmutable('+' . $expiresIn . ' seconds');

            // Store new refresh token if provided
            if (isset($body['refresh_token']) && $config->refreshTokenSecret !== null) {
                $this->vaultService->store($config->refreshTokenSecret, $body['refresh_token'], [
                    'source' => 'oauth_refresh',
                ]);
            }

            $this->logger?->info('OAuth token obtained successfully', [
                'expires_in' => $expiresIn,
                'token_type' => $body['token_type'] ?? 'Bearer',
            ]);

            return new OAuthToken(
                accessToken: $body['access_token'],
                tokenType: $body['token_type'] ?? 'Bearer',
                expiresAt: $expiresAt,
                scope: $body['scope'] ?? null,
            );
        } catch (GuzzleException $e) {
            // Clear credentials from memory on error
            sodium_memzero($clientId);
            sodium_memzero($clientSecret);
            if (isset($refreshToken)) {
                sodium_memzero($refreshToken);
            }

            $this->logger?->error('OAuth token request failed', [
                'error' => $e->getMessage(),
            ]);

            throw new VaultException(
                \sprintf('OAuth token request failed: %s', $e->getMessage()),
                0,
                $e,
            );
        } catch (JsonException $e) {
            throw new VaultException(
                'Invalid JSON response from OAuth server',
                0,
                $e,
            );
        }
    }

    /**
     * Generate a cache key for an OAuth config.
     */
    private function getCacheKey(OAuthConfig $config): string
    {
        return md5(implode(':', [
            $config->tokenEndpoint,
            $config->clientIdSecret,
            $config->grantType,
            $config->getScopesString(),
        ]));
    }
}
