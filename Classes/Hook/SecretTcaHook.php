<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Throwable;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for tx_nrvault_secret TCA operations.
 *
 * Handles:
 * - Identifier immutability (prevent changes after creation)
 * - Audit logging for metadata changes
 * - FormEngine integration with VaultService
 */
final class SecretTcaHook
{
    private const string TABLE = 'tx_nrvault_secret';

    /**
     * Called before database operations.
     * Prevents identifier changes on existing records.
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        // For existing records, prevent identifier changes
        if (!str_starts_with((string) $id, 'NEW') && isset($fieldArray['identifier'])) {
            // Get the original identifier
            $originalRecord = BackendUtility::getRecord(self::TABLE, (int) $id, 'identifier');
            if ($originalRecord !== null && $fieldArray['identifier'] !== $originalRecord['identifier']) {
                // Identifier change attempted - revert to original
                $dataHandler->log(
                    self::TABLE,
                    (int) $id,
                    2,
                    0,
                    1,
                    'Vault secret identifier cannot be changed after creation',
                );
                $fieldArray['identifier'] = $originalRecord['identifier'];
            }
        }

        // Handle owner_uid - convert group format to simple uid
        if (isset($fieldArray['owner_uid']) && \is_string($fieldArray['owner_uid'])) {
            // Format from group field: "be_users_123" or just "123"
            $fieldArray['owner_uid'] = $this->extractUidFromGroupValue($fieldArray['owner_uid']);
        }

        // Handle scope_pid - convert group format to simple uid
        if (isset($fieldArray['scope_pid']) && \is_string($fieldArray['scope_pid']) && str_contains($fieldArray['scope_pid'], 'pages')) {
            $fieldArray['scope_pid'] = $this->extractUidFromGroupValue($fieldArray['scope_pid']);
        }
    }

    /**
     * Called after database operations.
     * Logs metadata changes to audit log.
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ($table !== self::TABLE) {
            return;
        }

        // Get actual UID for new records
        $uid = $id;
        if ($status === 'new') {
            $uid = $dataHandler->substNEWwithIDs[$id] ?? $id;
        }

        // Get the secret identifier for audit logging
        $record = BackendUtility::getRecord(self::TABLE, (int) $uid, 'identifier');
        if ($record === null) {
            return;
        }

        $identifier = $record['identifier'];

        // Determine what changed for audit context
        $changedFields = array_keys($fieldArray);

        try {
            $auditService = GeneralUtility::makeInstance(AuditLogServiceInterface::class);

            // Log the metadata update
            $auditService->log(
                $identifier,
                $status === 'new' ? 'create' : 'metadata_update',
                true,
                null,
                'FormEngine edit: ' . implode(', ', $changedFields),
            );
        } catch (Throwable $e) {
            // Don't fail the save if audit logging fails
            $dataHandler->log(
                self::TABLE,
                (int) $uid,
                2,
                0,
                1,
                'Audit logging failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Called before record deletion.
     * Logs deletion to audit log.
     */
    public function processCmdmap_preProcess(
        string $command,
        string $table,
        string|int $id,
    ): void {
        if ($table !== self::TABLE || $command !== 'delete') {
            return;
        }

        $record = BackendUtility::getRecord(self::TABLE, (int) $id, 'identifier');
        if ($record === null) {
            return;
        }

        try {
            $auditService = GeneralUtility::makeInstance(AuditLogServiceInterface::class);
            $auditService->log(
                $record['identifier'],
                'delete',
                true,
                null,
                'Deleted via FormEngine',
            );
        } catch (Throwable) {
            // Don't fail the delete if audit logging fails
        }
    }

    /**
     * Extract UID from group field value format.
     *
     * @param string $value Value like "be_users_123" or "123"
     *
     * @return int The extracted UID
     */
    private function extractUidFromGroupValue(string $value): int
    {
        // Handle format: "table_uid" (e.g., "be_users_123")
        if (preg_match('/_(\d+)$/', $value, $matches)) {
            return (int) $matches[1];
        }

        // Handle simple numeric value
        return (int) $value;
    }
}
