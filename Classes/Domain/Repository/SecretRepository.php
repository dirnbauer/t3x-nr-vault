<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Domain\Repository;

use Netresearch\NrVault\Domain\Model\Secret;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for secret entities.
 */
final class SecretRepository
{
    private const TABLE_NAME = 'tx_nrvault_secret';
    private const MM_TABLE_NAME = 'tx_nrvault_secret_begroups_mm';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function findByIdentifier(string $identifier): ?Secret
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $secret = Secret::fromDatabaseRow($row);

        // Load groups from MM table
        $groups = $this->loadGroupsForSecret((int)$row['uid']);
        if (!empty($groups)) {
            $secret->setAllowedGroups($groups);
        }

        return $secret;
    }

    public function findByUid(int $uid): ?Secret
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $secret = Secret::fromDatabaseRow($row);

        // Load groups from MM table
        $groups = $this->loadGroupsForSecret($uid);
        if (!empty($groups)) {
            $secret->setAllowedGroups($groups);
        }

        return $secret;
    }

    public function exists(string $identifier): bool
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    public function save(Secret $secret): void
    {
        $connection = $this->getConnection();
        $data = $secret->toDatabaseRow();

        if ($secret->getUid() === null) {
            // Insert new secret
            $data['crdate'] = time();
            $connection->insert(self::TABLE_NAME, $data);
            $secret->setUid((int)$connection->lastInsertId(self::TABLE_NAME));
        } else {
            // Update existing secret
            $connection->update(
                self::TABLE_NAME,
                $data,
                ['uid' => $secret->getUid()]
            );
        }

        // Update MM table for groups
        $this->saveGroupsForSecret($secret);
    }

    public function delete(Secret $secret): void
    {
        if ($secret->getUid() === null) {
            return;
        }

        // Soft delete
        $this->getConnection()->update(
            self::TABLE_NAME,
            ['deleted' => 1, 'tstamp' => time()],
            ['uid' => $secret->getUid()]
        );
    }

    /**
     * Find all secrets matching filters.
     *
     * @param array{owner?: int, prefix?: string, context?: string, pid?: int} $filters
     * @return string[]
     */
    public function findIdentifiers(array $filters = []): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select('identifier')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('deleted', 0));

        if (isset($filters['owner'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('owner_uid', $queryBuilder->createNamedParameter($filters['owner'], Connection::PARAM_INT))
            );
        }

        if (isset($filters['prefix'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->like('identifier', $queryBuilder->createNamedParameter($filters['prefix'] . '%'))
            );
        }

        if (isset($filters['context'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('context', $queryBuilder->createNamedParameter($filters['context']))
            );
        }

        if (isset($filters['pid'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($filters['pid'], Connection::PARAM_INT))
            );
        }

        $queryBuilder->orderBy('identifier', 'ASC');

        $result = $queryBuilder->executeQuery();
        $identifiers = [];

        while ($row = $result->fetchAssociative()) {
            $identifiers[] = (string)$row['identifier'];
        }

        return $identifiers;
    }

    /**
     * Find all secrets accessible by specific groups.
     *
     * @param int[] $groupUids
     * @return Secret[]
     */
    public function findByGroups(array $groupUids): array
    {
        if (empty($groupUids)) {
            return [];
        }

        $connection = $this->getConnection();

        // Find secret UIDs that have any of the specified groups
        $mmQuery = $connection->createQueryBuilder();
        $secretUids = $mmQuery
            ->select('DISTINCT uid_local')
            ->from(self::MM_TABLE_NAME)
            ->where($mmQuery->expr()->in('uid_foreign', array_map('intval', $groupUids)))
            ->executeQuery()
            ->fetchFirstColumn();

        if (empty($secretUids)) {
            return [];
        }

        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->in('uid', array_map('intval', $secretUids)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $secrets = [];
        foreach ($rows as $row) {
            $secret = Secret::fromDatabaseRow($row);
            $groups = $this->loadGroupsForSecret((int)$row['uid']);
            if (!empty($groups)) {
                $secret->setAllowedGroups($groups);
            }
            $secrets[] = $secret;
        }

        return $secrets;
    }

    /**
     * Find expired secrets.
     *
     * @return Secret[]
     */
    public function findExpired(): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->gt('expires_at', 0),
                $queryBuilder->expr()->lt('expires_at', time()),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn($row) => Secret::fromDatabaseRow($row), $rows);
    }

    /**
     * Find secrets expiring within given days.
     *
     * @return Secret[]
     */
    public function findExpiringSoon(int $days): array
    {
        $now = time();
        $future = $now + ($days * 86400);

        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->gt('expires_at', $now),
                $queryBuilder->expr()->lte('expires_at', $future),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('expires_at', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn($row) => Secret::fromDatabaseRow($row), $rows);
    }

    /**
     * Count all active secrets.
     */
    public function countAll(): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Load groups for a secret from MM table.
     *
     * @return int[]
     */
    private function loadGroupsForSecret(int $secretUid): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('uid_foreign')
            ->from(self::MM_TABLE_NAME)
            ->where($queryBuilder->expr()->eq('uid_local', $secretUid))
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn($row) => (int)$row['uid_foreign'], $rows);
    }

    /**
     * Save groups for a secret to MM table.
     */
    private function saveGroupsForSecret(Secret $secret): void
    {
        if ($secret->getUid() === null) {
            return;
        }

        $connection = $this->getConnection();

        // Delete existing relations
        $connection->delete(self::MM_TABLE_NAME, ['uid_local' => $secret->getUid()]);

        // Insert new relations
        $groups = $secret->getAllowedGroups();
        foreach ($groups as $sorting => $groupUid) {
            $connection->insert(self::MM_TABLE_NAME, [
                'uid_local' => $secret->getUid(),
                'uid_foreign' => $groupUid,
                'sorting' => $sorting,
                'sorting_foreign' => 0,
            ]);
        }
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
    }
}
