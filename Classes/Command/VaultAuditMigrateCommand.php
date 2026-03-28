<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * CLI command to migrate audit log entries from legacy SHA-256 to HMAC-SHA256.
 */
#[AsCommand(
    name: 'vault:audit-migrate-hmac',
    description: 'Migrate audit log hash chain from SHA-256 to HMAC-SHA256',
)]
final class VaultAuditMigrateCommand extends Command
{
    private const TABLE_NAME = 'tx_nrvault_audit_log';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly MasterKeyProviderInterface $masterKeyProvider,
        private readonly ExtensionConfigurationInterface $extensionConfiguration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would be changed without modifying data',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $targetEpoch = $this->extensionConfiguration->getAuditHmacEpoch();
        if ($targetEpoch === 0) {
            $io->error('Cannot migrate to epoch 0 (legacy mode). Set auditHmacEpoch >= 1 in extension configuration.');

            return Command::FAILURE;
        }

        $io->title('Audit Log HMAC Migration');

        if ($dryRun) {
            $io->note('DRY RUN - no changes will be made');
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);

        // Count entries to migrate
        $queryBuilder = $connection->createQueryBuilder();
        $countResult = $queryBuilder
            ->count('uid')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->eq(
                    'hmac_key_epoch',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        $totalEntries = is_numeric($countResult) ? (int) $countResult : 0;

        if ($totalEntries === 0) {
            $io->success('No entries with epoch 0 found. Nothing to migrate.');

            return Command::SUCCESS;
        }

        $io->writeln(\sprintf('Found %d entries with epoch 0 to migrate to epoch %d', $totalEntries, $targetEpoch));

        // Derive HMAC key
        $hmacKey = $this->getHmacKey();

        try {
            return $this->migrateEntries($io, $connection, $hmacKey, $targetEpoch, $totalEntries, $dryRun);
        } finally {
            sodium_memzero($hmacKey);
        }
    }

    private function migrateEntries(
        SymfonyStyle $io,
        Connection $connection,
        string $hmacKey,
        int $targetEpoch,
        int $totalEntries,
        bool $dryRun,
    ): int {
        // Read ALL entries in UID order to maintain chain integrity
        $queryBuilder = $connection->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $progressBar = new ProgressBar($io, $totalEntries);
        $progressBar->start();

        $previousHash = '';
        $migratedCount = 0;

        foreach ($rows as $row) {
            $rowUid = $row['uid'] ?? 0;
            $uid = is_numeric($rowUid) ? (int) $rowUid : 0;
            $rowSecretId = $row['secret_identifier'] ?? '';
            $secretId = \is_string($rowSecretId) ? $rowSecretId : '';
            $rowAction = $row['action'] ?? '';
            $actionStr = \is_string($rowAction) ? $rowAction : '';
            $rowActorUid = $row['actor_uid'] ?? 0;
            $actorUid = is_numeric($rowActorUid) ? (int) $rowActorUid : 0;
            $rowCrdate = $row['crdate'] ?? 0;
            $crdate = is_numeric($rowCrdate) ? (int) $rowCrdate : 0;
            $rowEpoch = $row['hmac_key_epoch'] ?? 0;
            $epoch = is_numeric($rowEpoch) ? (int) $rowEpoch : 0;

            if ($epoch === 0) {
                // Recalculate hash using HMAC
                $newHash = $this->calculateHmacHash($uid, $secretId, $actionStr, $actorUid, $crdate, $previousHash, $hmacKey);

                if (!$dryRun) {
                    $connection->update(
                        self::TABLE_NAME,
                        [
                            'entry_hash' => $newHash,
                            'previous_hash' => $previousHash,
                            'hmac_key_epoch' => $targetEpoch,
                        ],
                        ['uid' => $uid],
                    );
                }

                $previousHash = $newHash;
                ++$migratedCount;
                $progressBar->advance();
            } else {
                // Already migrated, use its existing hash as previous
                $rowEntryHash = $row['entry_hash'] ?? '';
                $previousHash = \is_string($rowEntryHash) ? $rowEntryHash : '';
            }
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($dryRun) {
            $io->success(\sprintf('DRY RUN: Would migrate %d entries from epoch 0 to epoch %d', $migratedCount, $targetEpoch));
        } else {
            $io->success(\sprintf('Migrated %d entries from epoch 0 to epoch %d', $migratedCount, $targetEpoch));
        }

        return Command::SUCCESS;
    }

    private function calculateHmacHash(
        int $uid,
        string $secretIdentifier,
        string $action,
        int $actorUid,
        int $crdate,
        string $previousHash,
        string $hmacKey,
    ): string {
        $payload = json_encode([
            'uid' => $uid,
            'secret_identifier' => $secretIdentifier,
            'action' => $action,
            'actor_uid' => $actorUid,
            'crdate' => $crdate,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, $hmacKey);
    }

    /**
     * Derive the HMAC key from the master key via HKDF.
     */
    private function getHmacKey(): string
    {
        $masterKey = $this->masterKeyProvider->getMasterKey();

        try {
            return hash_hkdf('sha256', $masterKey, 32, 'nr-vault-audit-hmac-v1');
        } finally {
            sodium_memzero($masterKey);
        }
    }
}
