<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Security;

use Netresearch\NrVault\Domain\Model\Secret;

/**
 * Interface for access control operations.
 */
interface AccessControlServiceInterface
{
    /**
     * Check if current user can read a secret.
     */
    public function canRead(Secret $secret): bool;

    /**
     * Check if current user can write/update a secret.
     */
    public function canWrite(Secret $secret): bool;

    /**
     * Check if current user can delete a secret.
     */
    public function canDelete(Secret $secret): bool;

    /**
     * Check if current user can create secrets.
     */
    public function canCreate(): bool;

    /**
     * Get the current actor UID.
     *
     * @return int Backend user UID (0 for CLI/system)
     */
    public function getCurrentActorUid(): int;

    /**
     * Get the current actor type.
     *
     * @return string One of: 'backend', 'cli', 'api', 'scheduler'
     */
    public function getCurrentActorType(): string;

    /**
     * Get the current actor's username.
     */
    public function getCurrentActorUsername(): string;

    /**
     * Get groups the current user belongs to.
     *
     * @return int[]
     */
    public function getCurrentUserGroups(): array;
}
