<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
     * @var array<string, array<string|int, array<string, array{value: string, identifier: string, originalChecksum: string, isNew: bool}>>>
     */
    private array $pendingSecrets = [];

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    /**
     * Called before database operations.
     * Extracts vault field values and generates UUIDs for new secrets.
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        string|int $id,
    ): void {
        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';

            // Check if this is a vault secret field
            if ($renderType !== 'vaultSecret') {
                continue;
            }

            // Check if field is in the data being saved
            if (!isset($fieldArray[$fieldName])) {
                continue;
            }

            $value = $fieldArray[$fieldName];

            // Handle array format from form element
            if (\is_array($value)) {
                $secretValue = $value['value'] ?? $value[0] ?? '';
                $existingIdentifier = $value['_vault_identifier'] ?? '';
                $originalChecksum = $value['_vault_checksum'] ?? '';
            } else {
                $secretValue = (string) $value;
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
            $this->pendingSecrets[$table][$id][$fieldName] = [
                'value' => $secretValue,
                'identifier' => $vaultIdentifier,
                'originalChecksum' => $originalChecksum,
                'isNew' => $isNewSecret,
            ];

            // Store UUID in the database field (empty string if clearing)
            $fieldArray[$fieldName] = $secretValue !== '' ? $vaultIdentifier : '';
        }
    }

    /**
     * Called after database operations.
     * Stores vault secrets with the generated UUIDs.
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        // Get actual UID for new records
        $uid = $id;
        if ($status === 'new') {
            $uid = $dataHandler->substNEWwithIDs[$id] ?? $id;
        }

        // Process pending secrets for this record
        $pendingForRecord = $this->pendingSecrets[$table][$id] ?? [];

        foreach ($pendingForRecord as $fieldName => $secretData) {
            $secretValue = $secretData['value'];
            $vaultIdentifier = $secretData['identifier'];
            $originalChecksum = $secretData['originalChecksum'];
            $isNew = $secretData['isNew'];

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

                if ($secretValue === '') {
                    // Empty value means delete the secret
                    if ($originalChecksum !== '') {
                        $vaultService->delete($vaultIdentifier, 'TCA field cleared');
                    }
                } elseif ($isNew) {
                    // New secret with new UUID
                    $vaultService->store($vaultIdentifier, $secretValue, [
                        'table' => $table,
                        'field' => $fieldName,
                        'uid' => (int) $uid,
                        'source' => 'tca_field',
                    ]);
                } else {
                    // Update existing - use rotate to maintain audit trail
                    $vaultService->rotate($vaultIdentifier, $secretValue, 'TCA field updated');
                }
            } catch (VaultException $e) {
                $dataHandler->log(
                    $table,
                    (int) $uid,
                    $status === 'new' ? 1 : 2,
                    0,
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

        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $vaultFields = [];

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';
            if ($renderType === 'vaultSecret') {
                $vaultFields[] = $fieldName;
            }
        }

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
            if ($vaultIdentifier === '') {
                continue;
            }

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
                $vaultService->delete($vaultIdentifier, 'Record deleted');
            } catch (VaultException $e) {
                $dataHandler->log(
                    $table,
                    (int) $id,
                    3,
                    0,
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

        $newId = $dataHandler->copyMappingArray[$table][$id] ?? null;
        if ($newId === null) {
            return;
        }

        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $vaultFields = [];

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';
            if ($renderType === 'vaultSecret') {
                $vaultFields[] = $fieldName;
            }
        }

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
            if ($sourceIdentifier === '') {
                continue;
            }

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

                // Get source secret
                $sourceValue = $vaultService->retrieve($sourceIdentifier);
                if ($sourceValue === null) {
                    continue;
                }

                // Generate new UUID for copied record
                $newIdentifier = $this->generateUuid();

                // Store as new secret
                $vaultService->store($newIdentifier, $sourceValue, [
                    'table' => $table,
                    'field' => $fieldName,
                    'uid' => (int) $newId,
                    'source' => 'record_copy',
                    'copied_from' => $sourceIdentifier,
                ]);

                // Track update for the copied record
                $updates[$fieldName] = $newIdentifier;
            } catch (VaultException $e) {
                $dataHandler->log(
                    $table,
                    (int) $newId,
                    1,
                    0,
                    1,
                    'Vault error during copy for field "' . $fieldName . '": ' . $e->getMessage(),
                );
            }
        }

        // Update copied record with new UUIDs
        if ($updates !== []) {
            $connection->update($table, $updates, ['uid' => (int) $newId]);
        }
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
