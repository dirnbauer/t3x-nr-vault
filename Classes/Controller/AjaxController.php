<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Exception;
use JsonException;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * AJAX controller for vault backend operations.
 *
 * Provides JSON endpoints for:
 * - Revealing secrets (FormEngine and list view)
 * - Rotating secrets (list view modal)
 */
#[AsController]
final readonly class AjaxController
{
    public function __construct(
        private VaultServiceInterface $vaultService,
    ) {}

    /**
     * Reveal a secret value.
     *
     * Accepts both GET (query params) and POST (JSON body) requests.
     *
     * @return ResponseInterface JSON response with secret or error
     */
    public function revealAction(ServerRequestInterface $request): ResponseInterface
    {
        // Support both GET (query params) and POST (JSON body)
        $identifier = $this->getIdentifierFromRequest($request);

        if ($identifier === '') {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'No identifier provided',
            ], 400);
        }

        try {
            $secret = $this->vaultService->retrieve($identifier);

            // retrieve() returns null when secret not found
            if ($secret === null) {
                /** @phpstan-ignore new.internalClass, method.internalClass */
                return new JsonResponse([
                    'success' => false,
                    'error' => 'Secret not found',
                ], 404);
            }

            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => true,
                'secret' => $secret,
            ]);
        } catch (AccessDeniedException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        } catch (SecretExpiredException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Secret has expired',
            ], 410);
        } catch (EncryptionException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Decryption failed',
            ], 500);
        } catch (Exception) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to retrieve secret',
            ], 500);
        }
    }

    /**
     * Rotate a secret value (store a new value for existing identifier).
     *
     * Accepts POST requests with JSON body containing:
     * - identifier: string - The secret identifier
     * - secret: string - The new secret value
     *
     * @return ResponseInterface JSON response with success status
     */
    public function rotateAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only accept POST
        if ($request->getMethod() !== 'POST') {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Method not allowed',
            ], 405);
        }

        $body = $this->getJsonBody($request);
        $identifier = isset($body['identifier']) && \is_string($body['identifier']) ? $body['identifier'] : '';
        $newSecret = isset($body['secret']) && \is_string($body['secret']) ? $body['secret'] : '';

        if ($identifier === '') {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'No identifier provided',
            ], 400);
        }

        if ($newSecret === '') {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'No secret value provided',
            ], 400);
        }

        try {
            // Rotate the secret (will throw SecretNotFoundException if it doesn't exist)
            $this->vaultService->rotate($identifier, $newSecret);

            // Get updated metadata
            $updatedMetadata = $this->vaultService->getMetadata($identifier);

            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => true,
                'message' => 'Secret rotated successfully',
                'version' => $updatedMetadata->version,
            ]);
        } catch (SecretNotFoundException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Secret not found',
            ], 404);
        } catch (ValidationException $e) { // @phpstan-ignore catch.neverThrown
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Validation error: ' . $e->getMessage(),
            ], 400);
        } catch (AccessDeniedException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        } catch (EncryptionException) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Encryption failed',
            ], 500);
        } catch (Exception) {
            /** @phpstan-ignore new.internalClass, method.internalClass */
            return new JsonResponse([
                'success' => false,
                'error' => 'Failed to rotate secret',
            ], 500);
        }
    }

    /**
     * Extract identifier from request (supports query params and JSON body).
     */
    private function getIdentifierFromRequest(ServerRequestInterface $request): string
    {
        // First try query params (GET request or query string)
        $queryParams = $request->getQueryParams();
        if (isset($queryParams['identifier']) && \is_string($queryParams['identifier']) && $queryParams['identifier'] !== '') {
            return $queryParams['identifier'];
        }

        // Then try JSON body (POST request)
        $body = $this->getJsonBody($request);
        if (isset($body['identifier']) && \is_string($body['identifier']) && $body['identifier'] !== '') {
            return $body['identifier'];
        }

        return '';
    }

    /**
     * Parse JSON body from request.
     *
     * @return array<string, mixed>
     */
    private function getJsonBody(ServerRequestInterface $request): array
    {
        $parsedBody = $request->getParsedBody();
        if (\is_array($parsedBody)) {
            /** @var array<string, mixed> $parsedBody */
            return $parsedBody;
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return [];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }

            return [];
        } catch (JsonException) {
            return [];
        }
    }
}
