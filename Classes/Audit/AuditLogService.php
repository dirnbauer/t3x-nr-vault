<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

use Netresearch\NrVault\Security\AccessControlServiceInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Audit log service with tamper-evident hash chain.
 */
final class AuditLogService implements AuditLogServiceInterface
{
    private const TABLE_NAME = 'tx_nrvault_audit_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null,
        ?string $reason = null,
        ?string $hashBefore = null,
        ?string $hashAfter = null,
        array $context = []
    ): void {
        $connection = $this->getConnection();

        // Get previous hash for chain
        $previousHash = $this->getLatestHash() ?? '';

        // Build entry data
        $data = [
            'pid' => 0,
            'secret_identifier' => $secretIdentifier,
            'action' => $action,
            'success' => $success ? 1 : 0,
            'error_message' => $errorMessage ?? '',
            'reason' => $reason ?? '',
            'actor_uid' => $this->accessControlService->getCurrentActorUid(),
            'actor_type' => $this->accessControlService->getCurrentActorType(),
            'actor_username' => $this->accessControlService->getCurrentActorUsername(),
            'actor_role' => $this->getCurrentUserRole(),
            'ip_address' => $this->getClientIp(),
            'user_agent' => $this->getUserAgent(),
            'request_id' => $this->getRequestId(),
            'previous_hash' => $previousHash,
            'hash_before' => $hashBefore ?? '',
            'hash_after' => $hashAfter ?? '',
            'crdate' => time(),
            'context' => json_encode($context),
        ];

        // Insert to get UID
        $connection->insert(self::TABLE_NAME, $data);
        $uid = (int)$connection->lastInsertId(self::TABLE_NAME);

        // Calculate entry hash
        $entryHash = $this->calculateEntryHash([
            'uid' => $uid,
            'secret_identifier' => $secretIdentifier,
            'action' => $action,
            'actor_uid' => $data['actor_uid'],
            'crdate' => $data['crdate'],
        ], $previousHash);

        // Update with hash
        $connection->update(
            self::TABLE_NAME,
            ['entry_hash' => $entryHash],
            ['uid' => $uid]
        );
    }

    public function query(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($queryBuilder, $filters);

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            fn(array $row) => AuditLogEntry::fromDatabaseRow($row),
            $rows
        );
    }

    public function count(array $filters = []): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME);

        $this->applyFilters($queryBuilder, $filters);

        return (int)$queryBuilder->executeQuery()->fetchOne();
    }

    public function export(array $filters = []): array
    {
        $entries = $this->query($filters, PHP_INT_MAX, 0);
        return array_map(fn(AuditLogEntry $entry) => $entry->toArray(), $entries);
    }

    public function verifyHashChain(?int $fromUid = null, ?int $toUid = null): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('uid', 'ASC');

        if ($fromUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte('uid', $queryBuilder->createNamedParameter($fromUid, Connection::PARAM_INT))
            );
        }

        if ($toUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte('uid', $queryBuilder->createNamedParameter($toUid, Connection::PARAM_INT))
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $errors = [];
        $previousHash = '';

        foreach ($rows as $row) {
            $expectedHash = $this->calculateEntryHash([
                'uid' => (int)$row['uid'],
                'secret_identifier' => $row['secret_identifier'],
                'action' => $row['action'],
                'actor_uid' => (int)$row['actor_uid'],
                'crdate' => (int)$row['crdate'],
            ], $previousHash);

            // Verify previous_hash matches
            if ($row['previous_hash'] !== $previousHash) {
                $errors[(int)$row['uid']] = 'Previous hash mismatch - chain broken';
            }

            // Verify entry_hash is correct
            if ($row['entry_hash'] !== $expectedHash) {
                $errors[(int)$row['uid']] = 'Entry hash mismatch - possible tampering';
            }

            $previousHash = $row['entry_hash'];
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function getLatestHash(): ?string
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $hash = $queryBuilder
            ->select('entry_hash')
            ->from(self::TABLE_NAME)
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return $hash !== false ? (string)$hash : null;
    }

    /**
     * Calculate hash for an audit log entry.
     */
    private function calculateEntryHash(array $entry, string $previousHash): string
    {
        $payload = json_encode([
            'uid' => $entry['uid'],
            'secret_identifier' => $entry['secret_identifier'],
            'action' => $entry['action'],
            'actor_uid' => $entry['actor_uid'],
            'crdate' => $entry['crdate'],
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * Apply filters to query builder.
     */
    private function applyFilters($queryBuilder, array $filters): void
    {
        if (isset($filters['secretIdentifier'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'secret_identifier',
                    $queryBuilder->createNamedParameter($filters['secretIdentifier'])
                )
            );
        }

        if (isset($filters['action'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'action',
                    $queryBuilder->createNamedParameter($filters['action'])
                )
            );
        }

        if (isset($filters['actorUid'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'actor_uid',
                    $queryBuilder->createNamedParameter($filters['actorUid'], Connection::PARAM_INT)
                )
            );
        }

        if (isset($filters['success'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'success',
                    $queryBuilder->createNamedParameter($filters['success'] ? 1 : 0, Connection::PARAM_INT)
                )
            );
        }

        if (isset($filters['since']) && $filters['since'] instanceof \DateTimeInterface) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    'crdate',
                    $queryBuilder->createNamedParameter($filters['since']->getTimestamp(), Connection::PARAM_INT)
                )
            );
        }

        if (isset($filters['until']) && $filters['until'] instanceof \DateTimeInterface) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte(
                    'crdate',
                    $queryBuilder->createNamedParameter($filters['until']->getTimestamp(), Connection::PARAM_INT)
                )
            );
        }
    }

    private function getCurrentUserRole(): string
    {
        $groups = $this->accessControlService->getCurrentUserGroups();
        if (empty($groups)) {
            return $this->accessControlService->getCurrentActorType();
        }

        return 'groups:' . implode(',', $groups);
    }

    private function getClientIp(): string
    {
        $request = $this->getServerRequest();
        if ($request === null) {
            return PHP_SAPI === 'cli' ? 'CLI' : '';
        }

        return (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function getUserAgent(): string
    {
        $request = $this->getServerRequest();
        if ($request === null) {
            return PHP_SAPI === 'cli' ? 'CLI' : '';
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        if (strlen($userAgent) > 500) {
            $userAgent = substr($userAgent, 0, 500);
        }

        return $userAgent;
    }

    private function getRequestId(): string
    {
        $request = $this->getServerRequest();
        if ($request === null) {
            return '';
        }

        // Try to get request ID from header
        $requestId = $request->getHeaderLine('X-Request-Id');
        if ($requestId !== '') {
            return $requestId;
        }

        // Generate one
        return bin2hex(random_bytes(16));
    }

    private function getServerRequest(): ?ServerRequest
    {
        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    private function getConnection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
    }
}
