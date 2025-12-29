<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CLI command to clean up orphaned vault secrets from TCA fields.
 *
 * When records with vault-backed fields are deleted, the corresponding
 * vault secrets may become orphaned. This command identifies and removes
 * such orphaned secrets.
 *
 * Usage:
 *   vendor/bin/typo3 vault:cleanup-orphans --dry-run
 *   vendor/bin/typo3 vault:cleanup-orphans --retention-days=30
 *   vendor/bin/typo3 vault:cleanup-orphans
 */
#[AsCommand(
    name: 'vault:cleanup-orphans',
    description: 'Clean up orphaned vault secrets from deleted TCA records',
)]
final class VaultCleanupOrphansCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be deleted without making changes',
            )
            ->addOption(
                'retention-days',
                'r',
                InputOption::VALUE_REQUIRED,
                'Only delete orphans older than this many days',
                0,
            )
            ->addOption(
                'table',
                't',
                InputOption::VALUE_REQUIRED,
                'Only check secrets for this specific table',
            )
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_REQUIRED,
                'Number of secrets to check per batch',
                100,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = $input->getOption('dry-run');
        $retentionDays = (int) $input->getOption('retention-days');
        $tableFilter = $input->getOption('table');
        $batchSize = (int) $input->getOption('batch-size');

        $io->title('Vault Orphan Cleanup');
        $io->text([
            \sprintf('Mode: <info>%s</info>', $dryRun ? 'Dry Run' : 'Live'),
            \sprintf('Retention: <info>%d days</info>', $retentionDays),
            $tableFilter ? \sprintf('Table filter: <info>%s</info>', $tableFilter) : '',
        ]);

        // Get all TCA-sourced secrets
        $secrets = $this->getTcaSecrets($tableFilter);
        $totalSecrets = \count($secrets);

        if ($totalSecrets === 0) {
            $io->success('No TCA-sourced secrets found');

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Found <info>%d</info> TCA-sourced secrets to check', $totalSecrets));

        // Find orphans
        $io->section('Checking for orphaned secrets...');
        $progressBar = $io->createProgressBar($totalSecrets);
        $progressBar->start();

        $orphans = [];
        $retentionCutoff = $retentionDays > 0 ? time() - ($retentionDays * 86400) : PHP_INT_MAX;

        foreach (array_chunk($secrets, $batchSize) as $batch) {
            foreach ($batch as $secret) {
                $identifier = $secret['identifier'];
                $metadata = $secret['metadata'];
                $createdAt = $secret['created_at'] ?? 0;

                // Parse identifier to get table/field/uid
                $parsed = $this->parseIdentifier($identifier);
                if ($parsed === null) {
                    $progressBar->advance();
                    continue;
                }

                // Check if record still exists
                if (!$this->recordExists($parsed['table'], $parsed['uid'])) {
                    // Only include if older than retention period
                    if ($createdAt < $retentionCutoff) {
                        $orphans[] = [
                            'identifier' => $identifier,
                            'table' => $parsed['table'],
                            'field' => $parsed['field'],
                            'uid' => $parsed['uid'],
                            'created_at' => $createdAt,
                        ];
                    }
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        // Show results
        $orphanCount = \count($orphans);
        if ($orphanCount === 0) {
            $io->success('No orphaned secrets found');

            return Command::SUCCESS;
        }

        $io->text(\sprintf('Found <comment>%d</comment> orphaned secrets', $orphanCount));

        if ($dryRun) {
            $io->section('Orphaned secrets that would be deleted:');
            $this->showOrphanTable($io, $orphans);

            return Command::SUCCESS;
        }

        // Confirm deletion
        if (!$io->confirm(\sprintf('Delete %d orphaned secrets?', $orphanCount), false)) {
            $io->warning('Cleanup cancelled');

            return Command::SUCCESS;
        }

        // Delete orphans
        $io->section('Deleting orphaned secrets...');
        $deleted = 0;
        $failed = 0;
        $errors = [];

        foreach ($orphans as $orphan) {
            try {
                $this->vaultService->delete($orphan['identifier'], 'Orphan cleanup');
                $deleted++;
            } catch (VaultException $e) {
                $failed++;
                $errors[] = \sprintf('%s: %s', $orphan['identifier'], $e->getMessage());
            }
        }

        // Summary
        $io->section('Cleanup Summary');
        $io->definitionList(
            ['Orphans found' => $orphanCount],
            ['Successfully deleted' => $deleted],
            ['Failed' => $failed],
        );

        if (!empty($errors)) {
            $io->section('Errors');
            foreach (array_slice($errors, 0, 10) as $error) {
                $io->text('<error>✗</error> ' . $error);
            }
        }

        if ($failed > 0) {
            $io->warning(\sprintf('Cleanup completed with %d errors', $failed));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Successfully deleted %d orphaned secrets', $deleted));

        return Command::SUCCESS;
    }

    /**
     * Get all secrets that were created from TCA fields.
     *
     * @return array<int, array{identifier: string, metadata: array, created_at: int}>
     */
    private function getTcaSecrets(?string $tableFilter): array
    {
        $allSecrets = $this->vaultService->list();
        $tcaSecrets = [];

        foreach ($allSecrets as $secret) {
            $metadata = $secret['metadata'] ?? [];
            $source = $metadata['source'] ?? '';

            // Only include TCA-sourced secrets
            if ($source !== 'tca_field' && $source !== 'migration') {
                continue;
            }

            // Apply table filter if specified
            if ($tableFilter !== null) {
                $table = $metadata['table'] ?? '';
                if ($table !== $tableFilter) {
                    continue;
                }
            }

            $tcaSecrets[] = [
                'identifier' => $secret['identifier'],
                'metadata' => $metadata,
                'created_at' => $secret['createdAt'] ?? 0,
            ];
        }

        return $tcaSecrets;
    }

    /**
     * Parse a vault identifier into table, field, and uid components.
     *
     * @return array{table: string, field: string, uid: int}|null
     */
    private function parseIdentifier(string $identifier): ?array
    {
        // Format: table__field__uid
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

    /**
     * Check if a record exists in the database.
     */
    private function recordExists(string $table, int $uid): bool
    {
        // Check if table exists first
        $connection = $this->connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);
        if (!$connection->createSchemaManager()->tablesExist([$table])) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        $count = $queryBuilder
            ->count('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return $count > 0;
    }

    /**
     * @param array<int, array{identifier: string, table: string, field: string, uid: int, created_at: int}> $orphans
     */
    private function showOrphanTable(SymfonyStyle $io, array $orphans): void
    {
        $rows = [];
        foreach (array_slice($orphans, 0, 20) as $orphan) {
            $rows[] = [
                $orphan['identifier'],
                $orphan['table'],
                $orphan['field'],
                $orphan['uid'],
                $orphan['created_at'] > 0 ? date('Y-m-d H:i', $orphan['created_at']) : 'Unknown',
            ];
        }

        $io->table(
            ['Identifier', 'Table', 'Field', 'UID', 'Created'],
            $rows,
        );

        if (\count($orphans) > 20) {
            $io->text(\sprintf('... and %d more orphans', \count($orphans) - 20));
        }
    }
}
