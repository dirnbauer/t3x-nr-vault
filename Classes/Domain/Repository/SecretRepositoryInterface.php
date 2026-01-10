<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Domain\Repository;

use Netresearch\NrVault\Domain\Dto\SecretFilters;
use Netresearch\NrVault\Domain\Model\Secret;

/**
 * Interface for secret repository operations.
 */
interface SecretRepositoryInterface
{
    public function findByIdentifier(string $identifier): ?Secret;

    public function findByUid(int $uid): ?Secret;

    public function exists(string $identifier): bool;

    public function save(Secret $secret): void;

    public function delete(Secret $secret): void;

    /**
     * @return list<string>
     */
    public function findIdentifiers(?SecretFilters $filters = null): array;
}
