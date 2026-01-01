<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for vault secret TCA fields.
 *
 * Intercepts record save operations to store vault secrets
 * and handles record deletion to clean up secrets.
 */
final class DataHandlerHook
{
    private array $pendingSecrets = [];

    /**
     * Called before database operations.
     * Extracts vault field values before they're processed.
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
                $vaultIdentifier = $value['_vault_identifier'] ?? '';
                $originalChecksum = $value['_vault_checksum'] ?? '';
            } else {
                $secretValue = (string) $value;
                $vaultIdentifier = '';
                $originalChecksum = '';
            }

            // Skip if empty and no existing value
            if ($secretValue === '' && $originalChecksum === '') {
                // Remove from field array - nothing to store
                unset($fieldArray[$fieldName]);
                continue;
            }

            // Store pending secret for post-processing
            $this->pendingSecrets[$table][$id][$fieldName] = [
                'value' => $secretValue,
                'identifier' => $vaultIdentifier,
                'originalChecksum' => $originalChecksum,
                'config' => $fieldConfig['config'],
            ];

            // Replace field value with placeholder
            // The actual value is stored in the vault, not the database
            $fieldArray[$fieldName] = $secretValue !== '' ? '__VAULT__' : '';
        }
    }

    /**
     * Called after database operations.
     * Stores vault secrets with the correct record UID.
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
            $originalChecksum = $secretData['originalChecksum'];

            // Build vault identifier
            $vaultIdentifier = $this->buildVaultIdentifier($table, $fieldName, (int) $uid);

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
                $auditService = GeneralUtility::makeInstance(AuditLogServiceInterface::class);

                if ($secretValue === '') {
                    // Empty value means delete the secret
                    if ($originalChecksum !== '') {
                        $vaultService->delete($vaultIdentifier, 'TCA field cleared');
                    }
                } elseif ($originalChecksum === '') {
                    // New secret
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

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';

            if ($renderType !== 'vaultSecret') {
                continue;
            }

            $vaultIdentifier = $this->buildVaultIdentifier($table, $fieldName, (int) $id);

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

                // Check if secret exists before trying to delete
                $metadata = $vaultService->getMetadata($vaultIdentifier);
                if ($metadata !== null) {
                    $vaultService->delete($vaultIdentifier, 'Record deleted');
                }
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
     * Copies vault secrets to the new record.
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

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';

            if ($renderType !== 'vaultSecret') {
                continue;
            }

            $sourceIdentifier = $this->buildVaultIdentifier($table, $fieldName, (int) $id);
            $targetIdentifier = $this->buildVaultIdentifier($table, $fieldName, (int) $newId);

            try {
                $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

                // Get source secret
                $sourceValue = $vaultService->retrieve($sourceIdentifier);
                if ($sourceValue === null) {
                    continue;
                }

                // Store as new secret for copied record
                $vaultService->store($targetIdentifier, $sourceValue, [
                    'table' => $table,
                    'field' => $fieldName,
                    'uid' => (int) $newId,
                    'source' => 'record_copy',
                    'copied_from' => $sourceIdentifier,
                ]);
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
    }

    /**
     * Build vault identifier from table, field, and uid.
     */
    private function buildVaultIdentifier(string $table, string $field, int $uid): string
    {
        return \sprintf('%s__%s__%d', $table, $field, $uid);
    }
}
