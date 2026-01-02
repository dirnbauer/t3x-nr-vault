<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Http\VaultHttpClientInterface;

/**
 * Primary interface for interacting with the vault.
 */
interface VaultServiceInterface
{
    /**
     * Store a secret.
     *
     * @param string $identifier Unique identifier for the secret
     * @param string $secret The secret value to store
     * @param array $options Optional configuration:
     *                       - owner: int - BE user UID who owns this secret
     *                       - groups: int[] - BE user group UIDs allowed to access
     *                       - context: string - Permission scoping
     *                       - expiresAt: int|\DateTimeInterface|null - When secret expires
     *                       - metadata: array - Custom metadata
     *                       - description: string - Human-readable description
     *                       - scopePid: int - Page ID for multi-site scoping
     *
     * @throws ValidationException If identifier is invalid
     * @throws EncryptionException If encryption fails
     */
    public function store(string $identifier, string $secret, array $options = []): void;

    /**
     * Retrieve a secret value.
     *
     * @throws AccessDeniedException If current user lacks permission
     * @throws EncryptionException If decryption fails
     * @throws SecretExpiredException If secret has expired
     *
     * @return string|null The secret value, or null if not found
     */
    public function retrieve(string $identifier): ?string;

    /**
     * Check if a secret exists.
     */
    public function exists(string $identifier): bool;

    /**
     * Delete a secret permanently.
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     */
    public function delete(string $identifier, string $reason = ''): void;

    /**
     * Rotate a secret.
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     * @throws EncryptionException If encryption fails
     */
    public function rotate(string $identifier, string $newSecret, string $reason = ''): void;

    /**
     * List all accessible secrets with metadata.
     *
     * @param string|null $pattern Optional pattern to filter identifiers (supports * wildcard)
     *
     * @return array<array{
     *     identifier: string,
     *     owner_uid: int,
     *     crdate: int,
     *     tstamp: int,
     *     read_count: int,
     *     last_read_at: int|null,
     *     description: string,
     *     version: int
     * }>
     */
    public function list(?string $pattern = null): array;

    /**
     * Get metadata about a secret.
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     *
     * @return array{
     *     identifier: string,
     *     description: string,
     *     owner: int,
     *     groups: int[],
     *     context: string,
     *     version: int,
     *     createdAt: int,
     *     updatedAt: int,
     *     expiresAt: ?int,
     *     lastRotatedAt: ?int,
     *     metadata: array,
     * }
     */
    public function getMetadata(string $identifier): array;

    /**
     * Get the Vault HTTP Client for making authenticated API calls.
     */
    public function http(): VaultHttpClientInterface;
}
