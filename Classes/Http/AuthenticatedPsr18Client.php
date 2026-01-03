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

use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client wrapper that automatically injects vault secrets.
 *
 * This provides a drop-in replacement for any PSR-18 client that
 * transparently handles authentication using vault-stored secrets.
 *
 * @example
 *     $client = $vault->createAuthenticatedClient('my_api_key', SecretPlacement::Bearer);
 *     $response = $client->sendRequest($request); // Secret injected automatically
 */
final readonly class AuthenticatedPsr18Client implements ClientInterface
{
    /**
     * @param VaultServiceInterface $vaultService Vault for secret retrieval
     * @param ClientInterface $innerClient Underlying PSR-18 client
     * @param string $secretIdentifier Vault identifier for the secret
     * @param SecretPlacement $placement How to inject the secret
     * @param string|null $headerName Custom header name (for Header placement)
     * @param string|null $queryParam Custom query param name (for QueryParam placement)
     * @param string|null $usernameSecretIdentifier Username secret (for BasicAuth)
     */
    public function __construct(
        private VaultServiceInterface $vaultService,
        private ClientInterface $innerClient,
        private string $secretIdentifier,
        private SecretPlacement $placement = SecretPlacement::Bearer,
        private ?string $headerName = null,
        private ?string $queryParam = null,
        private ?string $usernameSecretIdentifier = null,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $authenticatedRequest = $this->injectAuthentication($request);

        return $this->innerClient->sendRequest($authenticatedRequest);
    }

    /**
     * Inject authentication into the request based on placement type.
     */
    private function injectAuthentication(RequestInterface $request): RequestInterface
    {
        return match ($this->placement) {
            SecretPlacement::Bearer => $this->injectBearer($request),
            SecretPlacement::BasicAuth => $this->injectBasicAuth($request),
            SecretPlacement::Header => $this->injectHeader($request),
            SecretPlacement::ApiKey => $this->injectApiKey($request),
            SecretPlacement::QueryParam => $this->injectQueryParam($request),
            SecretPlacement::BodyField, SecretPlacement::OAuth2 => throw new \InvalidArgumentException(
                \sprintf('SecretPlacement::%s is not supported for PSR-18 wrapper', $this->placement->name),
            ),
        };
    }

    private function injectBearer(RequestInterface $request): RequestInterface
    {
        $secret = $this->retrieveSecret($this->secretIdentifier);

        try {
            return $request->withHeader('Authorization', 'Bearer ' . $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectBasicAuth(RequestInterface $request): RequestInterface
    {
        $password = $this->retrieveSecret($this->secretIdentifier);

        if ($this->usernameSecretIdentifier !== null) {
            $username = $this->retrieveSecret($this->usernameSecretIdentifier);
            $credentials = $username . ':' . $password;
            sodium_memzero($username);
        } else {
            // Secret contains "username:password" format
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
        $secret = $this->retrieveSecret($this->secretIdentifier);

        try {
            return $request->withHeader('X-API-Key', $secret);
        } finally {
            sodium_memzero($secret);
        }
    }

    private function injectQueryParam(RequestInterface $request): RequestInterface
    {
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
}
