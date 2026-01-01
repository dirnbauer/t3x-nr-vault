<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use Netresearch\NrVault\Exception\VaultException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Interface for vault-aware HTTP client.
 *
 * Provides methods to make HTTP requests with secrets automatically
 * injected from the vault as authentication credentials.
 */
interface VaultHttpClientInterface
{
    /**
     * Make an HTTP request with vault-provided authentication.
     *
     * Options:
     * - auth_secret: Secret identifier for authentication
     * - placement: SecretPlacement enum for auth type (Bearer, BasicAuth, Header, etc.)
     * - auth_header: Custom header name (for Header placement)
     * - auth_query_param: Query param name (for QueryParam placement)
     * - auth_body_field: Body field name (for BodyField placement)
     * - auth_username_secret: Username secret (for BasicAuth with separate secrets)
     * - oauth_config: OAuthConfig for OAuth2 placement
     * - headers, query, body, json: Standard HTTP request options
     * - timeout: Request timeout in seconds
     * - verify_ssl: SSL verification (default: true)
     * - reason: Audit log reason
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url Request URL
     * @param array<string, mixed> $options Request options
     *
     * @throws VaultException If secret retrieval fails
     * @throws ClientExceptionInterface If request fails
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface;

    /**
     * Shorthand for GET request.
     */
    public function get(string $url, array $options = []): ResponseInterface;

    /**
     * Shorthand for POST request.
     */
    public function post(string $url, array $options = []): ResponseInterface;

    /**
     * Shorthand for PUT request.
     */
    public function put(string $url, array $options = []): ResponseInterface;

    /**
     * Shorthand for DELETE request.
     */
    public function delete(string $url, array $options = []): ResponseInterface;

    /**
     * Shorthand for PATCH request.
     */
    public function patch(string $url, array $options = []): ResponseInterface;
}
