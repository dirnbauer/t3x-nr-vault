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
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\OAuth\OAuthTokenManager;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 HTTP client that injects vault secrets as authentication.
 *
 * This is an immutable, fluent PSR-18 client. Configure authentication
 * with withAuthentication() or withOAuth(), then send requests with sendRequest().
 *
 * Supports various authentication types via SecretPlacement enum:
 * - Bearer: Bearer token in Authorization header
 * - BasicAuth: HTTP Basic Authentication
 * - Header: Custom header with secret value
 * - QueryParam: Query parameter with secret value
 * - BodyField: Secret in request body (JSON or form)
 * - OAuth2: OAuth 2.0 with automatic token refresh
 * - ApiKey: X-API-Key header (shorthand)
 *
 * @example
 *     // Bearer token authentication
 *     $client = $vault->http()->withAuthentication('stripe_key', SecretPlacement::Bearer);
 *     $response = $client->sendRequest($request);
 *
 *     // OAuth 2.0
 *     $client = $vault->http()->withOAuth($oauthConfig);
 *     $response = $client->sendRequest($request);
 */
final readonly class VaultHttpClient implements VaultHttpClientInterface
{
    private ClientInterface $innerClient;
    private OAuthTokenManager $oauthManager;

    /**
     * @param VaultServiceInterface $vaultService Vault for secret retrieval
     * @param AuditLogServiceInterface $auditLogService Audit logging
     * @param ClientInterface|null $innerClient Underlying PSR-18 client
     * @param string|null $secretIdentifier Configured secret identifier
     * @param SecretPlacement|null $placement Configured placement type
     * @param OAuthConfig|null $oauthConfig Configured OAuth config
     * @param string|null $headerName Custom header name for Header placement
     * @param string|null $queryParam Custom query param for QueryParam placement
     * @param string|null $bodyField Custom body field for BodyField placement
     * @param string|null $usernameSecretIdentifier Username secret for BasicAuth
     * @param string $reason Audit log reason
     */
    public function __construct(
        private VaultServiceInterface $vaultService,
        private AuditLogServiceInterface $auditLogService,
        ?ClientInterface $innerClient = null,
        private ?string $secretIdentifier = null,
        private ?SecretPlacement $placement = null,
        private ?OAuthConfig $oauthConfig = null,
        private ?string $headerName = null,
        private ?string $queryParam = null,
        private ?string $bodyField = null,
        private ?string $usernameSecretIdentifier = null,
        private string $reason = 'HTTP API call',
    ) {
        $this->innerClient = $innerClient ?? new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);
        $this->oauthManager = new OAuthTokenManager($this->vaultService);
    }

    public function withAuthentication(
        string $secretIdentifier,
        SecretPlacement $placement = SecretPlacement::Bearer,
        array $options = [],
    ): static {
        return new self(
            vaultService: $this->vaultService,
            auditLogService: $this->auditLogService,
            innerClient: $this->innerClient,
            secretIdentifier: $secretIdentifier,
            placement: $placement,
            oauthConfig: null,
            headerName: $options['headerName'] ?? null,
            queryParam: $options['queryParam'] ?? null,
            bodyField: $options['bodyField'] ?? null,
            usernameSecretIdentifier: $options['usernameSecret'] ?? null,
            reason: $options['reason'] ?? $this->reason,
        );
    }

    public function withOAuth(OAuthConfig $config, string $reason = 'OAuth2 API call'): static
    {
        return new self(
            vaultService: $this->vaultService,
            auditLogService: $this->auditLogService,
            innerClient: $this->innerClient,
            secretIdentifier: null,
            placement: SecretPlacement::OAuth2,
            oauthConfig: $config,
            headerName: null,
            queryParam: null,
            bodyField: null,
            usernameSecretIdentifier: null,
            reason: $reason,
        );
    }

    public function withReason(string $reason): static
    {
        return new self(
            vaultService: $this->vaultService,
            auditLogService: $this->auditLogService,
            innerClient: $this->innerClient,
            secretIdentifier: $this->secretIdentifier,
            placement: $this->placement,
            oauthConfig: $this->oauthConfig,
            headerName: $this->headerName,
            queryParam: $this->queryParam,
            bodyField: $this->bodyField,
            usernameSecretIdentifier: $this->usernameSecretIdentifier,
            reason: $reason,
        );
    }

    /**
     * Send an HTTP request with configured authentication.
     *
     * @throws ClientExceptionInterface If request fails
     * @throws VaultException If secret retrieval fails
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $authenticatedRequest = $this->injectAuthentication($request);
        $secretForAudit = $this->getSecretIdentifierForAudit();

        try {
            $response = $this->innerClient->sendRequest($authenticatedRequest);

            $this->logHttpCall(
                $secretForAudit,
                $request->getMethod(),
                (string) $request->getUri(),
                $response->getStatusCode(),
                true,
                null,
            );

            return $response;
        } catch (ClientExceptionInterface $e) {
            $this->logHttpCall(
                $secretForAudit,
                $request->getMethod(),
                (string) $request->getUri(),
                0,
                false,
                $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * Inject authentication into the request based on configuration.
     */
    private function injectAuthentication(RequestInterface $request): RequestInterface
    {
        if ($this->oauthConfig instanceof OAuthConfig) {
            return $this->injectOAuth($request);
        }

        if ($this->secretIdentifier === null || $this->placement === null) {
            return $request;
        }

        return match ($this->placement) {
            SecretPlacement::Bearer => $this->injectBearer($request),
            SecretPlacement::BasicAuth => $this->injectBasicAuth($request),
            SecretPlacement::Header => $this->injectHeader($request),
            SecretPlacement::ApiKey => $this->injectApiKey($request),
            SecretPlacement::QueryParam => $this->injectQueryParam($request),
            SecretPlacement::BodyField => $this->injectBodyField($request),
            SecretPlacement::OAuth2 => $request, // Handled above
        };
    }

    private function injectBearer(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $secret = $this->retrieveSecret($this->secretIdentifier);

        try {
            return $request->withHeader('Authorization', 'Bearer ' . $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectBasicAuth(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $password = $this->retrieveSecret($this->secretIdentifier);

        if ($this->usernameSecretIdentifier !== null) {
            $username = $this->retrieveSecret($this->usernameSecretIdentifier);
            $credentials = $username . ':' . $password;
            sodium_memzero($username);
        } else {
            $credentials = $password;
        }

        try {
            return $request->withHeader('Authorization', 'Basic ' . base64_encode($credentials));
        } finally {
            sodium_memzero($password);
            sodium_memzero($credentials);
        }
    }

    private function injectHeader(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $secret = $this->retrieveSecret($this->secretIdentifier);
        $headerName = $this->headerName ?? 'X-API-Key';

        try {
            return $request->withHeader($headerName, $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectApiKey(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $secret = $this->retrieveSecret($this->secretIdentifier);

        try {
            return $request->withHeader('X-API-Key', $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectQueryParam(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $secret = $this->retrieveSecret($this->secretIdentifier);
        $paramName = $this->queryParam ?? 'api_key';

        try {
            $uri = $request->getUri();
            $existingQuery = $uri->getQuery();
            $separator = $existingQuery !== '' ? '&' : '';
            $newQuery = $existingQuery . $separator . urlencode($paramName) . '=' . urlencode($secret);

            return $request->withUri($uri->withQuery($newQuery));
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectBodyField(RequestInterface $request): RequestInterface
    {
        \assert($this->secretIdentifier !== null);
        $secret = $this->retrieveSecret($this->secretIdentifier);
        $fieldName = $this->bodyField ?? 'api_key';

        try {
            $contentType = $request->getHeaderLine('Content-Type');
            $body = (string) $request->getBody();

            if (str_contains($contentType, 'application/json')) {
                /** @var array<string, mixed> $data */
                $data = json_decode($body, true) ?: [];
                $data[$fieldName] = $secret;
                $newBody = json_encode($data, JSON_THROW_ON_ERROR);
            } else {
                parse_str($body, $data);
                $data[$fieldName] = $secret;
                $newBody = http_build_query($data);
            }

            return $request
                ->withBody(\GuzzleHttp\Psr7\Utils::streamFor($newBody));
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectOAuth(RequestInterface $request): RequestInterface
    {
        \assert($this->oauthConfig !== null);
        $accessToken = $this->oauthManager->getAccessToken($this->oauthConfig);

        try {
            return $request->withHeader('Authorization', 'Bearer ' . $accessToken);
        } finally {
            sodium_memzero($accessToken);
        }
    }

    /**
     * Retrieve secret from vault, throwing if not found.
     */
    private function retrieveSecret(string $identifier): string
    {
        $secret = $this->vaultService->retrieve($identifier);

        if ($secret === null) {
            throw new SecretNotFoundException($identifier, 1735858521);
        }

        return $secret;
    }

    /**
     * Get the secret identifier for audit logging.
     */
    private function getSecretIdentifierForAudit(): string
    {
        if ($this->oauthConfig instanceof OAuthConfig) {
            return 'oauth2:' . $this->oauthConfig->clientIdSecret;
        }

        return $this->secretIdentifier ?? 'none';
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
    ): void {
        $parsedUrl = parse_url($url);
        $host = $parsedUrl['host'] ?? 'unknown';
        $path = $parsedUrl['path'] ?? '/';

        $this->auditLogService->log(
            $secretIdentifier,
            'http_call',
            $success,
            $errorMessage,
            $this->reason,
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
