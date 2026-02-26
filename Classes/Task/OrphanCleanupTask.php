<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Task;

use Netresearch\NrVault\Domain\Dto\OrphanReference;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task to clean up orphaned vault secrets.
 *
 * This task runs periodically to remove vault secrets whose source
 * TCA records have been deleted. It helps maintain vault hygiene
 * and prevents accumulation of unused encrypted data.
 *
 * Configuration (via TCA fields in tx_scheduler_task):
 * - nr_vault_retention_days: Only delete orphans older than this many days (default: 7)
 * - nr_vault_table_filter: Only check secrets for a specific table (optional)
 */
final class OrphanCleanupTask extends AbstractTask
{
    /** Only delete orphans older than this many days. */
    protected int $retentionDays = 7;

    /** Only check secrets for this specific table (optional). */
    protected string $tableFilter = '';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ?VaultServiceInterface $vaultService = null,
        private readonly ?LogManager $logManager = null,
    ) {
        parent::__construct();
    }

    /**
     * Get task parameters for TCA storage.
     *
     * Maps internal properties to TCA field names.
     *
     * @return array<string, mixed>
     */
    public function getTaskParameters(): array
    {
        return [
            'nr_vault_retention_days' => $this->retentionDays,
            'nr_vault_table_filter' => $this->tableFilter,
        ];
    }

    /**
     * Set task parameters from TCA fields.
     *
     * @param array<string, mixed> $parameters
     */
    public function setTaskParameters(array $parameters): void
    {
        $retentionDays = $parameters['nr_vault_retention_days'] ?? 7;
        $this->retentionDays = is_numeric($retentionDays) ? (int) $retentionDays : 7;

        $tableFilter = $parameters['nr_vault_table_filter'] ?? '';
        $this->tableFilter = \is_string($tableFilter) ? trim($tableFilter) : '';
    }

    public function execute(): bool
    {
        $vaultService = $this->getVaultService();
        $connectionPool = $this->getConnectionPool();
        $logger = $this->getLogger();

        $logger->info('Starting vault orphan cleanup', [
            'retentionDays' => $this->retentionDays,
            'tableFilter' => $this->tableFilter ?: '(all)',
        ]);

        // Get all TCA-sourced secrets
        $allSecrets = $vaultService->list();
        $retentionCutoff = $this->retentionDays > 0
            ? time() - ($this->retentionDays * 86400)
            : PHP_INT_MAX;

        $orphans = [];
        $checked = 0;

        foreach ($allSecrets as $secret) {
            $metadata = $secret->metadata;
            $source = $metadata['source'] ?? '';

            // Only check TCA-sourced secrets
            if ($source !== 'tca_field' && $source !== 'migration') {
                continue;
            }

            // Apply table filter if specified
            if ($this->tableFilter !== '') {
                $table = $metadata['table'] ?? '';
                if ($table !== $this->tableFilter) {
                    continue;
                }
            }

            $checked++;
            $identifier = $secret->identifier;
            $createdAt = $secret->createdAt;

            // Parse identifier
            $reference = $this->parseIdentifier($identifier);
            if (!$reference instanceof OrphanReference) {
                continue;
            }

            // Check if record still exists
            // Only include if older than retention period
            if (!$this->recordExists($connectionPool, $reference->table, $reference->uid) && $createdAt < $retentionCutoff) {
                $orphans[] = $identifier;
            }
        }

        $logger->info('Orphan check complete', [
            'secretsChecked' => $checked,
            'orphansFound' => \count($orphans),
        ]);

        // Delete identified orphans
        $success = true;
        foreach ($orphans as $orphanIdentifier) {
            try {
                $vaultService->delete($orphanIdentifier, 'Scheduler orphan cleanup');
                $logger->info('Deleted orphan secret', ['identifier' => $orphanIdentifier]);
            } catch (Throwable $e) {
                $logger->error('Failed to delete orphan secret', [
                    'identifier' => $orphanIdentifier,
                    'error' => $e->getMessage(),
                ]);
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Return additional information for the scheduler module display.
     */
    public function getAdditionalInformation(): string
    {
        $info = [];
        $info[] = \sprintf('Retention: %d days', $this->retentionDays);

        if ($this->tableFilter !== '') {
            $info[] = \sprintf('Table filter: %s', $this->tableFilter);
        }

        return implode(', ', $info);
    }

    private function parseIdentifier(string $identifier): ?OrphanReference
    {
        $parts = explode('__', $identifier);
        if (\count($parts) !== 3) {
            return null;
        }

        [$table, $field, $uidStr] = $parts;

        if (!is_numeric($uidStr)) {
            return null;
        }

        return new OrphanReference(
            table: $table,
            field: $field,
            uid: (int) $uidStr,
        );
    }

    private function recordExists(ConnectionPool $connectionPool, string $table, int $uid): bool
    {
        $connection = $connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return false;
        }

        $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    private function getVaultService(): VaultServiceInterface
    {
        return $this->vaultService ?? GeneralUtility::makeInstance(VaultServiceInterface::class);
    }

    private function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool;
    }

    private function getLogger(): LoggerInterface
    {
        if (!$this->logManager instanceof LogManager) {
            return new NullLogger();
        }

        return $this->logManager
            ->getLogger(self::class);
    }
}
