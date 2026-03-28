<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Exception;
use Netresearch\NrVault\Hook\Dto\FlexFormPendingSecret;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Utility\IdentifierValidator;
use Throwable;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * DataHandler hook for vault secrets in FlexForm fields.
 *
 * Processes FlexForm XML to detect and handle vault secret fields.
 * Works alongside the regular DataHandlerHook for standard TCA fields.
 *
 * Vault identifiers are UUIDs stored in the FlexForm XML.
 */
final class FlexFormVaultHook
{
    /** @var array<string, array<string|int, list<FlexFormPendingSecret>>> */
    private array $pendingFlexSecrets = [];

    public function __construct(
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly VaultServiceInterface $vaultService,
        private readonly FlexFormTools $flexFormTools,
        private readonly FlashMessageService $flashMessageService,
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Called before database operations.
     * Scans FlexForm fields for vault secrets.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        string|int $id,
    ): void {
        if (!$this->tcaSchemaFactory->has($table)) {
            return;
        }

        $schema = $this->tcaSchemaFactory->get($table);

        foreach ($schema->getFields() as $field) {
            $fieldConfig = $field->getConfiguration();

            // Check for FlexForm type fields
            $configType = $fieldConfig['type'] ?? '';
            if (!\is_string($configType)) {
                continue;
            }
            if ($configType !== 'flex') {
                continue;
            }

            $fieldName = $field->getName();

            // Check if this FlexForm field is being saved
            if (!isset($fieldArray[$fieldName])) {
                continue;
            }
            if (!\is_array($fieldArray[$fieldName])) {
                continue;
            }

            /** @var array<string, mixed> $flexData */
            $flexData = $fieldArray[$fieldName];
            // Process the FlexForm data array
            $this->processFlexFormData(
                $flexData,
                $table,
                $id,
                $fieldName,
                ['config' => $fieldConfig],
            );
            $fieldArray[$fieldName] = $flexData;
        }
    }

    /**
     * Called after database operations.
     * Stores vault secrets with the correct record UID for FlexForm fields.
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

        // Process pending FlexForm secrets
        $pendingForRecord = $this->pendingFlexSecrets[$table][$id] ?? [];

        foreach ($pendingForRecord as $secretData) {
            $this->storeFlexFormSecret($secretData, $table, $uid, $dataHandler);
        }

        // Clean up
        unset($this->pendingFlexSecrets[$table][$id]);
    }

    /**
     * Handle record deletion: clean up associated FlexForm vault secrets.
     *
     * @param array<string, mixed> $recordToDelete
     */
    public function processCmdmap_deleteAction(
        string $table,
        int $id,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler,
    ): void {
        // Only clean up vault secrets on hard delete.
        // Soft-delete keeps the record so secrets remain referenced.
        if (!$this->isHardDelete($table)) {
            return;
        }

        $flexFieldNames = $this->getFlexFieldNames($table);
        if ($flexFieldNames === []) {
            return;
        }

        foreach ($flexFieldNames as $flexFieldName) {
            $xmlValue = $recordToDelete[$flexFieldName] ?? '';
            if (!\is_string($xmlValue)) {
                continue;
            }
            if ($xmlValue === '') {
                continue;
            }

            $identifiers = $this->extractVaultIdentifiersFromXml($xmlValue);

            foreach ($identifiers as $identifier) {
                try {
                    $this->vaultService->delete($identifier, 'Record deleted');
                } catch (Throwable $e) {
                    /** @phpstan-ignore method.internal */
                    $dataHandler->log(
                        $table,
                        $id,
                        3,
                        null,
                        1,
                        'Vault error during delete for FlexForm field: ' . $e->getMessage(),
                    );
                }
            }
        }
    }

    /**
     * Called after record copy.
     * Copies FlexForm vault secrets to the new record with fresh UUIDs.
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

        $flexFieldNames = $this->getFlexFieldNames($table);
        if ($flexFieldNames === []) {
            return;
        }

        // Read the copied record's FlexForm XML
        $connection = $this->connectionPool->getConnectionForTable($table);
        $copiedRecord = $connection->select(
            $flexFieldNames,
            $table,
            ['uid' => $newId],
        )->fetchAssociative();

        if ($copiedRecord === false) {
            return;
        }

        foreach ($flexFieldNames as $flexFieldName) {
            $xmlValue = $copiedRecord[$flexFieldName] ?? '';
            if (!\is_string($xmlValue)) {
                continue;
            }
            if ($xmlValue === '') {
                continue;
            }

            $xml = $xmlValue;
            $identifiers = $this->extractVaultIdentifiersFromXml($xml);

            foreach ($identifiers as $oldIdentifier) {
                try {
                    $secretValue = $this->vaultService->retrieve($oldIdentifier);
                    if ($secretValue === null) {
                        continue;
                    }

                    $newIdentifier = IdentifierValidator::generateUuid();

                    $this->vaultService->store($newIdentifier, $secretValue, [
                        'table' => $table,
                        'flexField' => $flexFieldName,
                        'uid' => $newId,
                        'source' => 'flexform_record_copy',
                        'copied_from' => $oldIdentifier,
                    ]);

                    // Replace old identifier with new in XML
                    $xml = str_replace($oldIdentifier, $newIdentifier, $xml);
                } catch (Throwable $e) {
                    /** @phpstan-ignore method.internal */
                    $dataHandler->log(
                        $table,
                        $newId,
                        1,
                        null,
                        1,
                        'Vault error during copy for FlexForm field "' . $flexFieldName . '": ' . $e->getMessage(),
                    );
                }
            }

            if ($xml !== $xmlValue) {
                $connection->update($table, [$flexFieldName => $xml], ['uid' => $newId]);
            }
        }
    }

    /**
     * Process FlexForm data array recursively to find vault fields.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $flexFieldConfig
     */
    private function processFlexFormData(
        array &$data,
        string $table,
        string|int $id,
        string $flexFieldName,
        array $flexFieldConfig,
    ): void {
        // Get the FlexForm data structure
        $dataStructure = $this->getFlexFormDataStructure($flexFieldConfig, $data);
        if ($dataStructure === null) {
            return;
        }

        // Process each sheet
        if (!\is_array($data['data'] ?? null)) {
            return;
        }

        foreach ($data['data'] as $sheetName => &$sheetData) {
            if (!\is_array($sheetData)) {
                continue;
            }

            /** @var array<string, mixed> $sheets */
            $sheets = $dataStructure['sheets'] ?? [];
            /** @var array{ROOT?: array{el?: array<string, array{config?: array{renderType?: string}}>}} $sheetConfig */
            $sheetConfig = \is_array($sheets[$sheetName] ?? null) ? $sheets[$sheetName] : [];

            if (!\is_array($sheetData['lDEF'] ?? null)) {
                continue;
            }

            foreach ($sheetData['lDEF'] as $fieldPath => &$fieldData) {
                if (!\is_array($fieldData)) {
                    continue;
                }

                $elementConfig = $this->getFlexFormElementConfig($sheetConfig, (string) $fieldPath);

                // Check if this is a vault secret field
                $renderType = $elementConfig['config']['renderType'] ?? '';
                if (!\is_string($renderType)) {
                    continue;
                }
                if ($renderType !== 'vaultSecret') {
                    continue;
                }

                $value = $fieldData['vDEF'] ?? '';

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

                // Skip if unchanged
                if ($secretValue === '' && $originalChecksum === '') {
                    continue;
                }

                // Determine vault identifier (generate new UUID if needed)
                $isNewSecret = $existingIdentifier === '' || $originalChecksum === '';
                $vaultIdentifier = $isNewSecret ? IdentifierValidator::generateUuid() : $existingIdentifier;

                // Store for post-processing
                $this->pendingFlexSecrets[$table][$id][] = $isNewSecret
                    ? FlexFormPendingSecret::createNew(
                        $flexFieldName,
                        (string) $sheetName,
                        (string) $fieldPath,
                        $secretValue,
                        $vaultIdentifier,
                    )
                    : FlexFormPendingSecret::createUpdate(
                        $flexFieldName,
                        (string) $sheetName,
                        (string) $fieldPath,
                        $secretValue,
                        $vaultIdentifier,
                        $originalChecksum,
                    );

                // Store UUID in FlexForm (or empty if clearing)
                $fieldData['vDEF'] = $secretValue !== '' ? $vaultIdentifier : '';
            }
        }
    }

    /**
     * Store a FlexForm vault secret.
     */
    private function storeFlexFormSecret(
        FlexFormPendingSecret $pending,
        string $table,
        int $uid,
        DataHandler $dataHandler,
    ): void {
        try {
            if ($pending->value === '') {
                // Delete secret if cleared
                if ($pending->originalChecksum !== '') {
                    $this->vaultService->delete($pending->identifier, 'FlexForm field cleared');
                }
            } elseif ($pending->isNew) {
                // New secret with new UUID
                $this->vaultService->store($pending->identifier, $pending->value, [
                    'table' => $table,
                    'flexField' => $pending->flexField,
                    'sheet' => $pending->sheet,
                    'fieldPath' => $pending->fieldPath,
                    'uid' => $uid,
                    'source' => 'flexform_field',
                ]);
            } else {
                // Update existing
                $this->vaultService->rotate($pending->identifier, $pending->value, 'FlexForm field updated');
            }
        } catch (Throwable $e) {
            /** @phpstan-ignore method.internal */
            $dataHandler->log(
                $table,
                $uid,
                2,
                null,
                1,
                'Vault error for FlexForm field "' . $pending->fieldPath . '": ' . $e->getMessage(),
            );

            $this->addFlashMessage(
                \sprintf(
                    'Vault storage failed for FlexForm field "%s" on %s:%d: %s',
                    $pending->fieldPath,
                    $table,
                    $uid,
                    $e->getMessage(),
                ),
                'Vault Error',
                ContextualFeedbackSeverity::ERROR,
            );
        }
    }

    /**
     * Add a flash message visible to the backend user.
     */
    private function addFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity,
    ): void {
        try {
            $flashMessage = new FlashMessage($message, $title, $severity, true);
            $this->flashMessageService
                ->getMessageQueueByIdentifier()
                ->addMessage($flashMessage);
        } catch (Exception) {
            // Flash message service may not be available in all contexts (e.g., CLI)
        }
    }

    /**
     * Get the FlexForm data structure.
     *
     * @param array<string, mixed> $fieldConfig
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    private function getFlexFormDataStructure(array $fieldConfig, array $data): ?array
    {
        try {
            $dataStructureIdentifier = $this->flexFormTools->getDataStructureIdentifier(
                $fieldConfig,
                '',
                '',
                $data,
            );

            /** @phpstan-ignore return.type */
            return $this->flexFormTools->parseDataStructureByIdentifier($dataStructureIdentifier);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Extract vault identifiers from FlexForm XML.
     *
     * @return list<string>
     */
    private function extractVaultIdentifiersFromXml(string $xml): array
    {
        $identifiers = [];

        // Match UUID v7 patterns in XML values
        if (preg_match_all(
            '/[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}/i',
            $xml,
            $matches,
        )) {
            foreach ($matches[0] as $match) {
                if (IdentifierValidator::looksLikeVaultIdentifier($match)
                    && $this->vaultService->exists($match)) {
                    $identifiers[] = $match;
                }
            }
        }

        return $identifiers;
    }

    /**
     * Get FlexForm element configuration from sheet config.
     *
     * @param array{ROOT?: array{el?: array<string, array{config?: array{renderType?: string}}>}} $sheetConfig
     *
     * @return array{config?: array{renderType?: string}}
     */
    private function getFlexFormElementConfig(array $sheetConfig, string $fieldPath): array
    {
        return $sheetConfig['ROOT']['el'][$fieldPath] ?? [];
    }

    /**
     * Check if the table uses hard delete (no soft-delete column).
     */
    private function isHardDelete(string $table): bool
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return true;
        }

        $schema = $this->tcaSchemaFactory->get($table);

        return !$schema->hasField('deleted');
    }

    /**
     * Get names of FlexForm-type fields in a table.
     *
     * @return list<string>
     */
    private function getFlexFieldNames(string $table): array
    {
        if (!$this->tcaSchemaFactory->has($table)) {
            return [];
        }

        $names = [];
        $schema = $this->tcaSchemaFactory->get($table);

        foreach ($schema->getFields() as $field) {
            $fieldConfig = $field->getConfiguration();
            if (($fieldConfig['type'] ?? '') === 'flex') {
                $names[] = $field->getName();
            }
        }

        return $names;
    }
}
