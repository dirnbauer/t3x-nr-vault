<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\OAuth\OAuthTokenManager;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client that injects vault secrets as authentication.
 *
 * Supports various authentication types via SecretPlacement enum:
 * - Bearer: Bearer token in Authorization header
 * - BasicAuth: HTTP Basic Authentication
 * - Header: Custom header with secret value
 * - QueryParam: Query parameter with secret value
 * - BodyField: Secret in request body
 * - OAuth2: OAuth 2.0 with automatic token refresh
 * - ApiKey: X-API-Key header (shorthand)
 */
final class VaultHttpClient implements VaultHttpClientInterface
{
    private ClientInterface $client;

    private ?OAuthTokenManager $oauthManager = null;

    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly AuditLogServiceInterface $auditLogService,
        ?ClientInterface $client = null,
    ) {
        $this->client = $client ?? new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $authSecret = $options['auth_secret'] ?? null;
        $placement = $options['placement'] ?? null;
        $oauthConfig = $options['oauth_config'] ?? null;
        $reason = $options['reason'] ?? 'HTTP API call';

        // Remove vault-specific options before passing to Guzzle
        $guzzleOptions = $this->extractGuzzleOptions($options);

        // Handle OAuth2 authentication
        if ($placement === SecretPlacement::OAuth2 && $oauthConfig instanceof OAuthConfig) {
            $guzzleOptions = $this->injectOAuthAuthentication($guzzleOptions, $oauthConfig);
            $authSecret = 'oauth2:' . $oauthConfig->clientIdSecret;
        } elseif ($authSecret !== null && $placement !== null) {
            // Inject authentication from vault
            $guzzleOptions = $this->injectAuthentication(
                $guzzleOptions,
                $authSecret,
                $placement,
                $options,
            );
        } elseif (isset($options['auth_username_secret'])) {
            // Legacy: Basic auth with separate username/password secrets
            $guzzleOptions = $this->injectBasicAuthFromSecrets($guzzleOptions, $options);
            $authSecret = $options['auth_username_secret'];
        }

        try {
            $response = $this->client->request($method, $url, $guzzleOptions);

            // Log the HTTP call (without exposing sensitive data)
            $this->logHttpCall(
                $authSecret ?? 'none',
                $method,
                $url,
                $response->getStatusCode(),
                true,
                null,
                $reason,
            );

            return $response;
        } catch (GuzzleException $e) {
            // Log failed HTTP call
            $this->logHttpCall(
                $authSecret ?? 'none',
                $method,
                $url,
                0,
                false,
                $e->getMessage(),
                $reason,
            );

            throw new VaultException(
                \sprintf('HTTP request failed: %s', $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * Make a request and return a VaultHttpResponse wrapper.
     *
     * @param string $method HTTP method
     * @param string $url Request URL
     * @param array<string, mixed> $options Request options
     */
    public function send(string $method, string $url, array $options = []): VaultHttpResponse
    {
        $response = $this->request($method, $url, $options);

        return VaultHttpResponse::fromPsrResponse($response);
    }

    public function get(string $url, array $options = []): ResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    public function post(string $url, array $options = []): ResponseInterface
    {
        return $this->request('POST', $url, $options);
    }

    public function put(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PUT', $url, $options);
    }

    public function delete(string $url, array $options = []): ResponseInterface
    {
        return $this->request('DELETE', $url, $options);
    }

    public function patch(string $url, array $options = []): ResponseInterface
    {
        return $this->request('PATCH', $url, $options);
    }

    /**
     * Get the OAuth token manager (creates one if needed).
     */
    public function getOAuthManager(): OAuthTokenManager
    {
        if ($this->oauthManager === null) {
            $this->oauthManager = new OAuthTokenManager($this->vaultService);
        }

        return $this->oauthManager;
    }

    /**
     * Set a custom OAuth token manager.
     */
    public function setOAuthManager(OAuthTokenManager $manager): void
    {
        $this->oauthManager = $manager;
    }

    /**
     * Extract Guzzle-compatible options from vault options.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function extractGuzzleOptions(array $options): array
    {
        // Keys that are vault-specific, not Guzzle options
        $vaultKeys = [
            'auth_secret',
            'auth_header',
            'auth_query_param',
            'auth_body_field',
            'auth_username_secret',
            'placement',
            'oauth_config',
            'reason',
        ];

        $guzzleOptions = [];

        foreach ($options as $key => $value) {
            if (\in_array($key, $vaultKeys, true)) {
                continue;
            }

            // Map our options to Guzzle options
            switch ($key) {
                case 'body':
                    $guzzleOptions['body'] = $value;
                    break;
                case 'json':
                    $guzzleOptions['json'] = $value;
                    break;
                case 'headers':
                    $guzzleOptions['headers'] = $value;
                    break;
                case 'query':
                    $guzzleOptions['query'] = $value;
                    break;
                case 'timeout':
                    $guzzleOptions['timeout'] = $value;
                    break;
                case 'verify_ssl':
                    $guzzleOptions['verify'] = $value;
                    break;
                default:
                    $guzzleOptions[$key] = $value;
            }
        }

        return $guzzleOptions;
    }

    /**
     * Inject authentication from vault secret.
     *
     * @param array<string, mixed> $guzzleOptions
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function injectAuthentication(
        array $guzzleOptions,
        string $secretIdentifier,
        SecretPlacement $placement,
        array $options,
    ): array {
        $secret = $this->vaultService->retrieve($secretIdentifier);

        if ($secret === null) {
            throw new SecretNotFoundException($secretIdentifier);
        }

        $guzzleOptions['headers'] ??= [];
        $guzzleOptions['query'] ??= [];

        switch ($placement) {
            case SecretPlacement::Bearer:
                $guzzleOptions['headers']['Authorization'] = 'Bearer ' . $secret;
                break;
            case SecretPlacement::BasicAuth:
                // Secret is expected to be "username:password" format
                $guzzleOptions['headers']['Authorization'] = 'Basic ' . base64_encode($secret);
                break;
            case SecretPlacement::Header:
                $headerName = $options['auth_header'] ?? 'X-API-Key';
                $guzzleOptions['headers'][$headerName] = $secret;
                break;
            case SecretPlacement::ApiKey:
                $guzzleOptions['headers']['X-API-Key'] = $secret;
                break;
            case SecretPlacement::QueryParam:
                $paramName = $options['auth_query_param'] ?? 'api_key';
                $guzzleOptions['query'][$paramName] = $secret;
                break;
            case SecretPlacement::BodyField:
                $fieldName = $options['auth_body_field'] ?? 'api_key';
                if (isset($guzzleOptions['json'])) {
                    $guzzleOptions['json'][$fieldName] = $secret;
                } elseif (isset($guzzleOptions['form_params'])) {
                    $guzzleOptions['form_params'][$fieldName] = $secret;
                } else {
                    $guzzleOptions['form_params'] = [$fieldName => $secret];
                }
                break;
            case SecretPlacement::OAuth2:
                // OAuth2 is handled separately via injectOAuthAuthentication
                break;
        }

        // Clear secret from memory
        sodium_memzero($secret);

        return $guzzleOptions;
    }

    /**
     * Inject OAuth 2.0 authentication.
     *
     * @param array<string, mixed> $guzzleOptions
     *
     * @return array<string, mixed>
     */
    private function injectOAuthAuthentication(array $guzzleOptions, OAuthConfig $config): array
    {
        $accessToken = $this->getOAuthManager()->getAccessToken($config);

        $guzzleOptions['headers'] ??= [];
        $guzzleOptions['headers']['Authorization'] = 'Bearer ' . $accessToken;

        // Clear token from memory after use
        sodium_memzero($accessToken);

        return $guzzleOptions;
    }

    /**
     * Inject HTTP Basic auth from separate username and password secrets.
     *
     * @param array<string, mixed> $guzzleOptions
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function injectBasicAuthFromSecrets(array $guzzleOptions, array $options): array
    {
        $usernameSecret = $options['auth_username_secret'];
        $passwordSecret = $options['auth_secret'] ?? null;

        $username = $this->vaultService->retrieve($usernameSecret);
        if ($username === null) {
            throw new SecretNotFoundException($usernameSecret);
        }

        $password = '';
        if ($passwordSecret !== null) {
            $password = $this->vaultService->retrieve($passwordSecret);
            if ($password === null) {
                sodium_memzero($username);

                throw new SecretNotFoundException($passwordSecret);
            }
        }

        $guzzleOptions['headers'] ??= [];
        $guzzleOptions['headers']['Authorization'] = 'Basic ' . base64_encode($username . ':' . $password);

        // Clear from memory
        sodium_memzero($username);
        if ($password !== '') {
            sodium_memzero($password);
        }

        return $guzzleOptions;
    }

    /**
     * Log HTTP call to audit log.
     */
    private function logHttpCall(
        string $secretIdentifier,
        string $method,
        string $url,
        int $statusCode,
        bool $success,
        ?string $errorMessage,
        string $reason,
    ): void {
        // Parse URL to get host (don't log full URL for security)
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';
        $path = $parsedUrl['path'] ?? '/';

        $this->auditLogService->log(
            $secretIdentifier,
            'http_call',
            $success,
            $errorMessage,
            $reason,
            null,
            null,
            [
                'method' => $method,
                'host' => $host,
                'path' => $path,
                'status_code' => $statusCode,
            ],
        );
    }
}
