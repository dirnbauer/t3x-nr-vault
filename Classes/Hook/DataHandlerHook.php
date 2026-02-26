<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\Dto\PendingSecret;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * DataHandler hook for vault secret TCA fields.
 *
 * Intercepts record save operations to store vault secrets
 * and handles record deletion to clean up secrets.
 *
 * Vault identifiers are UUIDs stored directly in the database field.
 * This allows:
 * - Direct use of field value as vault identifier
 * - Reuse of secrets across multiple records (future)
 * - Portability (identifiers don't depend on table/field/uid)
 */
final class DataHandlerHook
{
    /**
     * Pending secrets to be stored after database operations.
     *
     * @var array<string, array<string|int, array<string, PendingSecret>>>
     */
    private array $pendingSecrets = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly VaultServiceInterface $vaultService,
    ) {}

    /**
     * Called before database operations.
     * Extracts vault field values and generates UUIDs for new secrets.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        string|int $id,
    ): void {
        $vaultFieldNames = $this->getVaultFieldNames($table);

        foreach ($vaultFieldNames as $fieldName) {
            // Check if field is in the data being saved
            if (!isset($fieldArray[$fieldName])) {
                continue;
            }

            $value = $fieldArray[$fieldName];

            // Handle array format from form element
            if (\is_array($value)) {
                $rawSecretValue = $value['value'] ?? $value[0] ?? '';
                $rawIdentifier = $value['_vault_identifier'] ?? '';
                $rawChecksum = $value['_vault_checksum'] ?? '';
                $secretValue = \is_string($rawSecretValue) || \is_int($rawSecretValue) ? (string) $rawSecretValue : '';
                $existingIdentifier = \is_string($rawIdentifier) ? $rawIdentifier : '';
                $originalChecksum = \is_string($rawChecksum) ? $rawChecksum : '';
            } else {
                $secretValue = \is_string($value) || \is_int($value) ? (string) $value : '';
                $existingIdentifier = '';
                $originalChecksum = '';
            }

            // Skip if empty and no existing value
            if ($secretValue === '' && $originalChecksum === '') {
                unset($fieldArray[$fieldName]);
                continue;
            }

            // Determine vault identifier
            $isNewSecret = $existingIdentifier === '' || $originalChecksum === '';
            $vaultIdentifier = $isNewSecret ? $this->generateUuid() : $existingIdentifier;

            // Store pending secret for post-processing
            $this->pendingSecrets[$table][$id][$fieldName] = $isNewSecret
                ? PendingSecret::createNew($secretValue, $vaultIdentifier)
                : PendingSecret::createUpdate($secretValue, $vaultIdentifier, $originalChecksum);

            // Store UUID in the database field (empty string if clearing)
            $fieldArray[$fieldName] = $secretValue !== '' ? $vaultIdentifier : '';
        }
    }

    /**
     * Called after database operations.
     * Stores vault secrets with the generated UUIDs.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        // Get actual UID for new records
        $uidRaw = $id;
        if ($status === 'new') {
            $uidRaw = $dataHandler->substNEWwithIDs[$id] ?? $id;
        }
        $uid = is_numeric($uidRaw) ? (int) $uidRaw : 0;

        // Process pending secrets for this record
        $pendingForRecord = $this->pendingSecrets[$table][$id] ?? [];

        foreach ($pendingForRecord as $fieldName => $pending) {
            $secretValue = $pending->value;
            $vaultIdentifier = $pending->identifier;
            $originalChecksum = $pending->originalChecksum;
            $isNew = $pending->isNew;

            try {
                if ($secretValue === '') {
                    // Empty value means delete the secret
                    if ($originalChecksum !== '') {
                        $this->vaultService->delete($vaultIdentifier, 'TCA field cleared');
                    }
                } elseif ($isNew) {
                    // New secret with new UUID
                    $this->vaultService->store($vaultIdentifier, $secretValue, [
                        'table' => $table,
                        'field' => $fieldName,
                        'uid' => $uid,
                        'source' => 'tca_field',
                    ]);
                } else {
                    // Update existing - use rotate to maintain audit trail
                    $this->vaultService->rotate($vaultIdentifier, $secretValue, 'TCA field updated');
                }
            } catch (VaultException $e) {
                /** @phpstan-ignore method.internal */
                $dataHandler->log(
                    $table,
                    $uid,
                    $status === 'new' ? 1 : 2,
                    null,
                    1,
                    'Vault error for field "' . $fieldName . '": ' . $e->getMessage(),
                );
            }
        }

        // Clean up pending secrets
        unset($this->pendingSecrets[$table][$id]);
    }

    /**
     * Called before record deletion.
     * Removes associated vault secrets.
     */
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        string|int $id,
        mixed $value,
        DataHandler $dataHandler,
        bool $pasteUpdate,
    ): void {
        if ($command !== 'delete') {
            return;
        }

        $vaultFields = $this->getVaultFieldNames($table);
        if ($vaultFields === []) {
            return;
        }

        // Read current field values to get UUIDs
        $connection = $this->connectionPool
            ->getConnectionForTable($table);

        $record = $connection->select(
            $vaultFields,
            $table,
            ['uid' => (int) $id],
        )->fetchAssociative();

        if ($record === false) {
            return;
        }

        foreach ($vaultFields as $fieldName) {
            $vaultIdentifier = $record[$fieldName] ?? '';
            if (!\is_string($vaultIdentifier)) {
                continue;
            }
            if ($vaultIdentifier === '') {
                continue;
            }

            try {
                $this->vaultService->delete($vaultIdentifier, 'Record deleted');
            } catch (VaultException $e) {
                /** @phpstan-ignore method.internal */
                $dataHandler->log(
                    $table,
                    (int) $id,
                    3,
                    null,
                    1,
                    'Vault error during delete for field "' . $fieldName . '": ' . $e->getMessage(),
                );
            }
        }
    }

    /**
     * Called after record copy.
     * Copies vault secrets to the new record with new UUIDs.
     */
    public function processCmdmap_postProcess(
        string $command,
        string $table,
        string|int $id,
        mixed $value,
        DataHandler $dataHandler,
        bool $pasteUpdate,
    ): void {
        if ($command !== 'copy') {
            return;
        }

        /** @phpstan-ignore property.internal */
        $newIdRaw = $dataHandler->copyMappingArray[$table][$id] ?? null;
        if ($newIdRaw === null) {
            return;
        }
        $newId = is_numeric($newIdRaw) ? (int) $newIdRaw : 0;

        $vaultFields = $this->getVaultFieldNames($table);
        if ($vaultFields === []) {
            return;
        }

        // Read source record to get UUIDs
        $connection = $this->connectionPool
            ->getConnectionForTable($table);

        $sourceRecord = $connection->select(
            $vaultFields,
            $table,
            ['uid' => (int) $id],
        )->fetchAssociative();

        if ($sourceRecord === false) {
            return;
        }

        $updates = [];

        foreach ($vaultFields as $fieldName) {
            $sourceIdentifier = $sourceRecord[$fieldName] ?? '';
            if (!\is_string($sourceIdentifier)) {
                continue;
            }
            if ($sourceIdentifier === '') {
                continue;
            }

            try {
                // Get source secret
                $sourceValue = $this->vaultService->retrieve($sourceIdentifier);
                if ($sourceValue === null) {
                    continue;
                }

                // Generate new UUID for copied record
                $newIdentifier = $this->generateUuid();

                // Store as new secret
                $this->vaultService->store($newIdentifier, $sourceValue, [
                    'table' => $table,
                    'field' => $fieldName,
                    'uid' => $newId,
                    'source' => 'record_copy',
                    'copied_from' => $sourceIdentifier,
                ]);

                // Track update for the copied record
                $updates[$fieldName] = $newIdentifier;
            } catch (VaultException $e) {
                /** @phpstan-ignore method.internal */
                $dataHandler->log(
                    $table,
                    $newId,
                    1,
                    null,
                    1,
                    'Vault error during copy for field "' . $fieldName . '": ' . $e->getMessage(),
                );
            }
        }

        // Update copied record with new UUIDs
        if ($updates !== []) {
            $connection->update($table, $updates, ['uid' => $newId]);
        }
    }

    /**
     * Get field names with vaultSecret renderType from TCA schema.
     *
     * @return list<string>
     */
    private function getVaultFieldNames(string $table): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $schema = $this->tcaSchemaFactory->get($table);
        $vaultFields = [];

        foreach ($schema->getFields() as $field) {
            $config = $field->getConfiguration();
            $renderType = $config['renderType'] ?? '';
            if ($renderType === 'vaultSecret') {
                $vaultFields[] = $field->getName();
            }
        }

        return $vaultFields;
    }

    /**
     * Generate a UUID v7 for vault identifiers.
     *
     * UUID v7 contains a 48-bit Unix timestamp (milliseconds) followed by random data.
     * This provides time-ordered IDs with better database index performance.
     */
    private function generateUuid(): string
    {
        // 48-bit timestamp in milliseconds
        $time = (int) (microtime(true) * 1000);

        // 10 random bytes for the remaining fields
        $random = random_bytes(10);

        // Build UUID v7:
        // - Bytes 0-5: timestamp (48 bits)
        // - Byte 6: version (0111) + 4 random bits
        // - Byte 7: 8 random bits
        // - Byte 8: variant (10) + 6 random bits
        // - Bytes 9-15: 56 random bits
        return \sprintf(
            '%08x-%04x-7%03x-%04x-%012x',
            ($time >> 16) & 0xFFFFFFFF,           // timestamp high 32 bits
            $time & 0xFFFF,                        // timestamp low 16 bits
            \ord($random[0]) << 4 | \ord($random[1]) >> 4 & 0x0FFF, // version 7 + 12 random bits
            (\ord($random[1]) & 0x0F) << 8 | \ord($random[2]) & 0x3FFF | 0x8000, // variant 10 + 14 random bits
            (\ord($random[3]) << 40) | (\ord($random[4]) << 32) | (\ord($random[5]) << 24)
                | (\ord($random[6]) << 16) | (\ord($random[7]) << 8) | \ord($random[8]), // 48 random bits
        );
    }
}
