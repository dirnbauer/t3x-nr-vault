<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Upgrades;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

/**
 * Upgrade wizard to migrate audit log hash chain from SHA-256 to HMAC-SHA256.
 *
 * Appears in the TYPO3 Install Tool after extension update. Automatically
 * detects legacy (epoch 0) entries and re-hashes them with the HMAC key
 * derived from the master key.
 */
final readonly class AuditHmacMigrationWizard implements UpgradeWizardInterface
{
    private const TABLE_NAME = 'tx_nrvault_audit_log';

    private const HKDF_INFO = 'nr-vault-audit-hmac-v1';

    public function __construct(
        private ConnectionPool $connectionPool,
        private MasterKeyProviderInterface $masterKeyProvider,
        private ExtensionConfigurationInterface $extensionConfiguration,
    ) {}

    public function getTitle(): string
    {
        return 'Vault: Migrate audit hash chain to HMAC-SHA256';
    }

    public function getDescription(): string
    {
        return 'Migrates existing audit log entries from plain SHA-256 hashing to '
            . 'HMAC-SHA256 keyed with a master-key-derived key. This provides '
            . 'tamper resistance against database-privileged attackers. '
            . 'The migration re-hashes all entries while maintaining chain integrity.';
    }

    public function updateNecessary(): bool
    {
        if ($this->extensionConfiguration->getAuditHmacEpoch() === 0) {
            return false;
        }

        return $this->countLegacyEntries() > 0;
    }

    public function executeUpdate(): bool
    {
        $targetEpoch = $this->extensionConfiguration->getAuditHmacEpoch();
        if ($targetEpoch === 0) {
            return true;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);

        $masterKey = $this->masterKeyProvider->getMasterKey();
        $hmacKey = hash_hkdf('sha256', $masterKey, 32, self::HKDF_INFO);
        sodium_memzero($masterKey);

        try {
            // Read ALL entries in UID order to rebuild chain
            $rows = $connection->createQueryBuilder()
                ->select('*')
                ->from(self::TABLE_NAME)
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();

            $previousHash = '';

            foreach ($rows as $row) {
                $rowUid = $row['uid'] ?? 0;
                $uid = is_numeric($rowUid) ? (int) $rowUid : 0;
                $rowSecretId = $row['secret_identifier'] ?? '';
                $secretId = \is_string($rowSecretId) ? $rowSecretId : '';
                $rowAction = $row['action'] ?? '';
                $action = \is_string($rowAction) ? $rowAction : '';
                $rowActorUid = $row['actor_uid'] ?? 0;
                $actorUid = is_numeric($rowActorUid) ? (int) $rowActorUid : 0;
                $rowCrdate = $row['crdate'] ?? 0;
                $crdate = is_numeric($rowCrdate) ? (int) $rowCrdate : 0;
                $rowEpoch = $row['hmac_key_epoch'] ?? 0;
                $epoch = is_numeric($rowEpoch) ? (int) $rowEpoch : 0;

                if ($epoch === 0) {
                    $payload = json_encode([
                        'uid' => $uid,
                        'secret_identifier' => $secretId,
                        'action' => $action,
                        'actor_uid' => $actorUid,
                        'crdate' => $crdate,
                        'previous_hash' => $previousHash,
                    ], JSON_THROW_ON_ERROR);

                    $newHash = hash_hmac('sha256', $payload, $hmacKey);

                    $connection->update(
                        self::TABLE_NAME,
                        [
                            'entry_hash' => $newHash,
                            'previous_hash' => $previousHash,
                            'hmac_key_epoch' => $targetEpoch,
                        ],
                        ['uid' => $uid],
                    );

                    $previousHash = $newHash;
                } else {
                    $rowEntryHash = $row['entry_hash'] ?? '';
                    $previousHash = \is_string($rowEntryHash) ? $rowEntryHash : '';
                }
            }

            return true;
        } finally {
            sodium_memzero($hmacKey);
        }
    }

    /**
     * @return string[]
     */
    public function getPrerequisites(): array
    {
        return [];
    }

    private function countLegacyEntries(): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_NAME);
        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
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

        return is_numeric($result) ? (int) $result : 0;
    }
}
