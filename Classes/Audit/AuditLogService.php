<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

use DateTimeInterface;
use Netresearch\NrVault\Security\AccessControlServiceInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Audit log service with tamper-evident hash chain.
 */
final readonly class AuditLogService implements AuditLogServiceInterface
{
    private const string TABLE_NAME = 'tx_nrvault_audit_log';

    public function __construct(
        private ConnectionPool $connectionPool,
        private AccessControlServiceInterface $accessControlService,
    ) {}

    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null,
        ?string $reason = null,
        ?string $hashBefore = null,
        ?string $hashAfter = null,
        ?AuditContextInterface $context = null,
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
            'context' => $context instanceof AuditContextInterface ? json_encode($context->toArray()) : '{}',
        ];

        // Insert to get UID
        $connection->insert(self::TABLE_NAME, $data);
        $uid = (int) $connection->lastInsertId();

        // Calculate entry hash
        $entryHash = $this->calculateEntryHash($uid, $secretIdentifier, $action, $data['actor_uid'], $data['crdate'], $previousHash);

        // Update with hash
        $connection->update(
            self::TABLE_NAME,
            ['entry_hash' => $entryHash],
            ['uid' => $uid],
        );
    }

    public function query(?AuditLogFilter $filter = null, int $limit = 100, int $offset = 0): array
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($filter instanceof AuditLogFilter) {
            $this->applyFilter($queryBuilder, $filter);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map(
            AuditLogEntry::fromDatabaseRow(...),
            $rows,
        );
    }

    public function count(?AuditLogFilter $filter = null): int
    {
        $queryBuilder = $this->getConnection()->createQueryBuilder();
        $queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME);

        if ($filter instanceof AuditLogFilter) {
            $this->applyFilter($queryBuilder, $filter);
        }

        return (int) $queryBuilder->executeQuery()->fetchOne();
    }

    public function export(?AuditLogFilter $filter = null): array
    {
        return $this->query($filter, PHP_INT_MAX, 0);
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
                $queryBuilder->expr()->gte('uid', $queryBuilder->createNamedParameter($fromUid, Connection::PARAM_INT)),
            );
        }

        if ($toUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte('uid', $queryBuilder->createNamedParameter($toUid, Connection::PARAM_INT)),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        $errors = [];
        $previousHash = '';

        foreach ($rows as $row) {
            $expectedHash = $this->calculateEntryHash(
                (int) $row['uid'],
                (string) $row['secret_identifier'],
                (string) $row['action'],
                (int) $row['actor_uid'],
                (int) $row['crdate'],
                $previousHash,
            );

            // Verify previous_hash matches
            if ($row['previous_hash'] !== $previousHash) {
                $errors[(int) $row['uid']] = 'Previous hash mismatch - chain broken';
            }

            // Verify entry_hash is correct
            if ($row['entry_hash'] !== $expectedHash) {
                $errors[(int) $row['uid']] = 'Entry hash mismatch - possible tampering';
            }

            $previousHash = (string) $row['entry_hash'];
        }

        return [
            'valid' => $errors === [],
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

        return $hash !== false ? (string) $hash : null;
    }

    /**
     * Calculate hash for an audit log entry.
     */
    private function calculateEntryHash(
        int $uid,
        string $secretIdentifier,
        string $action,
        int $actorUid,
        int $crdate,
        string $previousHash,
    ): string {
        $payload = json_encode([
            'uid' => $uid,
            'secret_identifier' => $secretIdentifier,
            'action' => $action,
            'actor_uid' => $actorUid,
            'crdate' => $crdate,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * Apply filter to query builder.
     */
    private function applyFilter(QueryBuilder $queryBuilder, AuditLogFilter $filter): void
    {
        if ($filter->secretIdentifier !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'secret_identifier',
                    $queryBuilder->createNamedParameter($filter->secretIdentifier),
                ),
            );
        }

        if ($filter->action !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'action',
                    $queryBuilder->createNamedParameter($filter->action),
                ),
            );
        }

        if ($filter->actorUid !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'actor_uid',
                    $queryBuilder->createNamedParameter($filter->actorUid, Connection::PARAM_INT),
                ),
            );
        }

        if ($filter->success !== null) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'success',
                    $queryBuilder->createNamedParameter($filter->success ? 1 : 0, Connection::PARAM_INT),
                ),
            );
        }

        if ($filter->since instanceof DateTimeInterface) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->gte(
                    'crdate',
                    $queryBuilder->createNamedParameter($filter->since->getTimestamp(), Connection::PARAM_INT),
                ),
            );
        }

        if ($filter->until instanceof DateTimeInterface) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->lte(
                    'crdate',
                    $queryBuilder->createNamedParameter($filter->until->getTimestamp(), Connection::PARAM_INT),
                ),
            );
        }
    }

    private function getCurrentUserRole(): string
    {
        $groups = $this->accessControlService->getCurrentUserGroups();
        if ($groups === []) {
            return $this->accessControlService->getCurrentActorType();
        }

        return 'groups:' . implode(',', $groups);
    }

    private function getClientIp(): string
    {
        $request = $this->getServerRequest();
        if (!$request instanceof ServerRequest) {
            return PHP_SAPI === 'cli' ? 'CLI' : '';
        }

        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }

    private function getUserAgent(): string
    {
        $request = $this->getServerRequest();
        if (!$request instanceof ServerRequest) {
            return PHP_SAPI === 'cli' ? 'CLI' : '';
        }

        $userAgent = $request->getHeaderLine('User-Agent');
        if (\strlen($userAgent) > 500) {
            return substr($userAgent, 0, 500);
        }

        return $userAgent;
    }

    private function getRequestId(): string
    {
        $request = $this->getServerRequest();
        if (!$request instanceof ServerRequest) {
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
