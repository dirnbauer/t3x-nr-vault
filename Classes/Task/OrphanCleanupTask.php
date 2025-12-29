<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Task;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task to clean up orphaned vault secrets.
 *
 * This task runs periodically to remove vault secrets whose source
 * TCA records have been deleted. It helps maintain vault hygiene
 * and prevents accumulation of unused encrypted data.
 *
 * Configuration:
 * - retentionDays: Only delete orphans older than this many days (default: 7)
 * - tableFilter: Only check secrets for a specific table (optional)
 */
final class OrphanCleanupTask extends AbstractTask
{
    /** Only delete orphans older than this many days. */
    public int $retentionDays = 7;

    /** Only check secrets for this specific table (optional). */
    public string $tableFilter = '';

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
            $metadata = $secret['metadata'] ?? [];
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
            $identifier = $secret['identifier'];
            $createdAt = $secret['createdAt'] ?? 0;

            // Parse identifier
            $parsed = $this->parseIdentifier($identifier);
            if ($parsed === null) {
                continue;
            }

            // Check if record still exists
            if (!$this->recordExists($connectionPool, $parsed['table'], $parsed['uid'])) {
                // Only include if older than retention period
                if ($createdAt < $retentionCutoff) {
                    $orphans[] = $identifier;
                }
            }
        }

        $logger->info('Orphan check complete', [
            'secretsChecked' => $checked,
            'orphansFound' => \count($orphans),
        ]);

        if (empty($orphans)) {
            return true;
        }

        // Delete orphans
        $deleted = 0;
        $failed = 0;

        foreach ($orphans as $identifier) {
            try {
                $vaultService->delete($identifier, 'Scheduler orphan cleanup');
                $deleted++;
            } catch (VaultException $e) {
                $failed++;
                $logger->warning('Failed to delete orphan', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $logger->info('Orphan cleanup complete', [
            'deleted' => $deleted,
            'failed' => $failed,
        ]);

        // Return true if no failures
        return $failed === 0;
    }

    /**
     * @return array{table: string, field: string, uid: int}|null
     */
    private function parseIdentifier(string $identifier): ?array
    {
        $parts = explode('__', $identifier);
        if (\count($parts) !== 3) {
            return null;
        }

        [$table, $field, $uidStr] = $parts;

        if (!is_numeric($uidStr)) {
            return null;
        }

        return [
            'table' => $table,
            'field' => $field,
            'uid' => (int) $uidStr,
        ];
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
        return \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(VaultServiceInterface::class);
    }

    private function getConnectionPool(): ConnectionPool
    {
        return \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(ConnectionPool::class);
    }

    private function getLogger(): LoggerInterface
    {
        return \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Log\LogManager::class)
            ->getLogger(__CLASS__);
    }
}
