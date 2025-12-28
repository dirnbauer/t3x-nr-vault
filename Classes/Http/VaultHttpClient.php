<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client that injects vault secrets as authentication.
 *
 * Supports various authentication types:
 * - basic: HTTP Basic Authentication (username:password)
 * - bearer: Bearer token in Authorization header
 * - header: Custom header with secret value
 * - query: Query parameter with secret value
 */
final class VaultHttpClient implements VaultHttpClientInterface
{
    private ClientInterface $client;

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
        $authType = $options['auth_type'] ?? 'bearer';
        $reason = $options['reason'] ?? 'HTTP API call';

        // Remove vault-specific options before passing to Guzzle
        $guzzleOptions = $this->extractGuzzleOptions($options);

        // Inject authentication from vault
        if ($authSecret !== null) {
            $guzzleOptions = $this->injectAuthentication(
                $guzzleOptions,
                $authSecret,
                $authType,
                $options
            );
        }

        // Basic auth with separate username/password secrets
        if (isset($options['auth_username_secret'])) {
            $guzzleOptions = $this->injectBasicAuthFromSecrets($guzzleOptions, $options);
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
                $reason
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
                $reason
            );

            throw new VaultException(
                sprintf('HTTP request failed: %s', $e->getMessage()),
                0,
                $e
            );
        }
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
     * Extract Guzzle-compatible options from vault options.
     */
    private function extractGuzzleOptions(array $options): array
    {
        // Keys that are vault-specific, not Guzzle options
        $vaultKeys = [
            'auth_secret',
            'auth_type',
            'auth_header',
            'auth_query_param',
            'auth_username_secret',
            'reason',
        ];

        $guzzleOptions = [];

        foreach ($options as $key => $value) {
            if (in_array($key, $vaultKeys, true)) {
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
     */
    private function injectAuthentication(
        array $guzzleOptions,
        string $secretIdentifier,
        string $authType,
        array $options
    ): array {
        $secret = $this->vaultService->retrieve($secretIdentifier);

        if ($secret === null) {
            throw new SecretNotFoundException($secretIdentifier);
        }

        switch ($authType) {
            case 'bearer':
                $guzzleOptions['headers']['Authorization'] = 'Bearer ' . $secret;
                break;

            case 'basic':
                // Secret is expected to be "username:password" format
                $guzzleOptions['headers']['Authorization'] = 'Basic ' . base64_encode($secret);
                break;

            case 'header':
                $headerName = $options['auth_header'] ?? 'X-API-Key';
                $guzzleOptions['headers'][$headerName] = $secret;
                break;

            case 'query':
                $paramName = $options['auth_query_param'] ?? 'api_key';
                $guzzleOptions['query'][$paramName] = $secret;
                break;

            default:
                throw new VaultException(sprintf('Unknown auth type: %s', $authType));
        }

        // Clear secret from memory
        sodium_memzero($secret);

        return $guzzleOptions;
    }

    /**
     * Inject HTTP Basic auth from separate username and password secrets.
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
        string $reason
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
            ]
        );
    }
}
