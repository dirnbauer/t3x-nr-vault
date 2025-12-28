<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

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
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url Request URL
     * @param array{
     *     auth_secret?: string,
     *     auth_type?: 'basic'|'bearer'|'header'|'query',
     *     auth_header?: string,
     *     auth_query_param?: string,
     *     auth_username_secret?: string,
     *     headers?: array<string, string>,
     *     query?: array<string, mixed>,
     *     body?: string|array,
     *     json?: array,
     *     timeout?: int,
     *     verify_ssl?: bool,
     *     reason?: string
     * } $options Request options
     *
     * @throws \Netresearch\NrVault\Exception\VaultException If secret retrieval fails
     * @throws \Psr\Http\Client\ClientExceptionInterface If request fails
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
