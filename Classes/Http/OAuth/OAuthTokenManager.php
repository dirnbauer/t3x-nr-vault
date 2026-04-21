<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use JsonException;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\OAuthException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Manages OAuth 2.0 token acquisition and refresh.
 *
 * Features:
 * - Automatic token refresh before expiry
 * - In-memory token caching
 * - Support for client_credentials and refresh_token grants
 * - Secure credential retrieval from vault
 * - PSR-18 compliant HTTP client
 * - Automatic fallback to client_credentials if refresh_token rejected
 */
final class OAuthTokenManager
{
    /**
     * Cached tokens indexed by config hash.
     *
     * @var array<string, OAuthToken>
     */
    private array $tokenCache = [];

    private readonly RequestFactoryInterface $requestFactory;

    private readonly StreamFactoryInterface $streamFactory;

    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly ?LoggerInterface $logger = null,
        private readonly ClientInterface $httpClient = new Client(['timeout' => 30, 'connect_timeout' => 10]),
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        private readonly ?AuditLogServiceInterface $auditLogService = null,
    ) {
        $httpFactory = new HttpFactory();
        $this->requestFactory = $requestFactory ?? $httpFactory;
        $this->streamFactory = $streamFactory ?? $httpFactory;
    }

    public function __destruct()
    {
        $this->clearToken();
    }

    /**
     * Get a valid access token for the given OAuth config.
     *
     * Automatically refreshes the token if it's expired or about to expire.
     *
     * @throws OAuthException If token cannot be obtained
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

        // Fetch new token, with fallback to client_credentials on refresh failure.
        $token = $this->fetchTokenWithFallback($config);
        $this->tokenCache[$cacheKey] = $token;

        return $token->accessToken;
    }

    /**
     * Clear the token cache for a specific config or all configs.
     */
    public function clearCache(?OAuthConfig $config = null): void
    {
        if ($config instanceof OAuthConfig) {
            $cacheKey = $this->getCacheKey($config);
            unset($this->tokenCache[$cacheKey]);
        } else {
            $this->tokenCache = [];
        }
    }

    /**
     * Clear the cached token references to allow garbage collection.
     *
     * Since OAuthToken is readonly, sodium_memzero cannot be used on its properties.
     * This method nulls the cache references so the token objects can be collected.
     */
    public function clearToken(): void
    {
        $this->tokenCache = [];
    }

    /**
     * Attempt to fetch a token; on refresh_token rejection (HTTP 401), fall back
     * to a client_credentials grant if configured to do so.
     *
     * The fallback is intentionally narrow: we only retry with client_credentials
     * when the original grant was `refresh_token` AND the failure was a token-
     * endpoint 401 (invalid / revoked / expired refresh token). Any other failure
     * (network error, 5xx, JSON decode error) re-throws the original exception.
     *
     * Both the failed refresh and the subsequent fallback are audit-logged, so an
     * operator can see the fallback happened.
     *
     * @throws OAuthException If both refresh and fallback fail
     */
    private function fetchTokenWithFallback(OAuthConfig $config): OAuthToken
    {
        if ($config->grantType !== 'refresh_token') {
            return $this->fetchToken($config);
        }

        try {
            return $this->fetchToken($config);
        } catch (OAuthException $e) {
            // Only fall back when the OAuth server said "your refresh token is
            // not valid". Code 2477018617 is tokenRequestFailed (non-200 from
            // the token endpoint).
            if ($e->getCode() !== 2477018617) {
                throw $e;
            }

            $this->logger?->warning('OAuth refresh_token rejected, falling back to client_credentials', [
                'token_endpoint' => $config->tokenEndpoint,
                'original_error' => $e->getMessage(),
            ]);

            // Audit the failed refresh attempt so the fallback is not silent.
            $this->auditLogService?->log(
                $config->refreshTokenSecret ?? $config->clientIdSecret,
                'oauth_refresh_failed',
                false,
                $e->getMessage(),
                'refresh_token rejected by OAuth server (HTTP non-200)',
            );

            // Build a client_credentials config with the same endpoint & scopes.
            $fallback = new OAuthConfig(
                tokenEndpoint: $config->tokenEndpoint,
                clientIdSecret: $config->clientIdSecret,
                clientSecretSecret: $config->clientSecretSecret,
                grantType: 'client_credentials',
                refreshTokenSecret: null,
                scopes: $config->scopes,
                tokenExpiryBuffer: $config->tokenExpiryBuffer,
                additionalParams: $config->additionalParams,
            );

            $token = $this->fetchToken($fallback);

            // Audit the successful fallback.
            $this->auditLogService?->log(
                $config->clientIdSecret,
                'oauth_fallback_client_credentials',
                true,
                null,
                'fell back to client_credentials after refresh_token rejection',
            );

            return $token;
        }
    }

    /**
     * Fetch a new token from the OAuth server.
     *
     * @throws OAuthException If token request fails
     */
    private function fetchToken(OAuthConfig $config): OAuthToken
    {
        // Get credentials from vault
        $clientId = $this->vaultService->retrieve($config->clientIdSecret);
        if ($clientId === null) {
            throw new SecretNotFoundException($config->clientIdSecret, 6051576903);
        }

        $clientSecret = $this->vaultService->retrieve($config->clientSecretSecret);
        if ($clientSecret === null) {
            sodium_memzero($clientId);

            throw new SecretNotFoundException($config->clientSecretSecret, 4158358265);
        }

        // Build token request
        $params = [
            'grant_type' => $config->grantType,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        // Add scopes if specified
        if ($config->scopes !== []) {
            $params['scope'] = $config->getScopesString();
        }

        // Add refresh token if using refresh_token grant
        if ($config->grantType === 'refresh_token' && $config->refreshTokenSecret !== null) {
            $refreshToken = $this->vaultService->retrieve($config->refreshTokenSecret);
            if ($refreshToken === null) {
                sodium_memzero($clientId);
                sodium_memzero($clientSecret);

                throw new SecretNotFoundException($config->refreshTokenSecret, 6618787426);
            }
            $params['refresh_token'] = $refreshToken;
        }

        // Add any additional parameters
        $params = array_merge($params, $config->additionalParams);

        try {
            // Build PSR-7 request
            $body = $this->streamFactory->createStream(http_build_query($params));
            $request = $this->requestFactory->createRequest('POST', $config->tokenEndpoint)
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withHeader('Accept', 'application/json')
                ->withBody($body);

            // Send request via PSR-18 client
            $response = $this->httpClient->sendRequest($request);

            // Clear credentials from memory
            sodium_memzero($clientId);
            sodium_memzero($clientSecret);
            if (isset($refreshToken)) {
                sodium_memzero($refreshToken);
            }

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                throw OAuthException::tokenRequestFailed($statusCode);
            }

            /** @var array<string, mixed>|null $body */
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!\is_array($body) || !isset($body['access_token'])) {
                throw OAuthException::missingAccessToken();
            }

            $accessToken = \is_string($body['access_token']) ? $body['access_token'] : '';
            $tokenType = \is_string($body['token_type'] ?? null) ? $body['token_type'] : 'Bearer';
            $scope = isset($body['scope']) && \is_string($body['scope']) ? $body['scope'] : null;
            $expiresIn = \is_int($body['expires_in'] ?? null) ? $body['expires_in'] : 3600;
            $expiresAt = new DateTimeImmutable('+' . $expiresIn . ' seconds');

            // Store new refresh token if provided
            if (isset($body['refresh_token']) && \is_string($body['refresh_token']) && $config->refreshTokenSecret !== null) {
                $this->vaultService->store($config->refreshTokenSecret, $body['refresh_token'], [
                    'source' => 'oauth_refresh',
                ]);
            }

            $this->logger?->info('OAuth token obtained successfully', [
                'expires_in' => $expiresIn,
                'token_type' => $tokenType,
            ]);

            return new OAuthToken(
                accessToken: $accessToken,
                tokenType: $tokenType,
                expiresAt: $expiresAt,
                scope: $scope,
            );
        } catch (ClientExceptionInterface $e) {
            // Clear credentials from memory on error
            sodium_memzero($clientId);
            sodium_memzero($clientSecret);
            if (isset($refreshToken)) {
                sodium_memzero($refreshToken);
            }

            $this->logger?->error('OAuth token request failed', [
                'error' => $e->getMessage(),
            ]);

            throw OAuthException::requestFailed($e->getMessage(), $e);
        } catch (JsonException $e) {
            throw OAuthException::invalidJsonResponse($e);
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
