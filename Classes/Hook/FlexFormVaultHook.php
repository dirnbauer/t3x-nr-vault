<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Exception;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for vault secrets in FlexForm fields.
 *
 * Processes FlexForm XML to detect and handle vault secret fields.
 * Works alongside the regular DataHandlerHook for standard TCA fields.
 */
final class FlexFormVaultHook
{
    private array $pendingFlexSecrets = [];

    /**
     * Called before database operations.
     * Scans FlexForm fields for vault secrets.
     */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            // Check for FlexForm type fields
            if (($fieldConfig['config']['type'] ?? '') !== 'flex') {
                continue;
            }

            // Check if this FlexForm field is being saved
            if (!isset($fieldArray[$fieldName]) || !\is_array($fieldArray[$fieldName])) {
                continue;
            }

            // Process the FlexForm data array
            $this->processFlexFormData(
                $fieldArray[$fieldName],
                $table,
                $id,
                $fieldName,
                $fieldConfig,
            );
        }
    }

    /**
     * Called after database operations.
     * Stores vault secrets with the correct record UID for FlexForm fields.
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

        // Process pending FlexForm secrets
        $pendingForRecord = $this->pendingFlexSecrets[$table][$id] ?? [];

        foreach ($pendingForRecord as $secretData) {
            $this->storeFlexFormSecret($secretData, $table, (int) $uid, $dataHandler);
        }

        // Clean up
        unset($this->pendingFlexSecrets[$table][$id]);
    }

    /**
     * Process FlexForm data array recursively to find vault fields.
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
        foreach ($data['data'] ?? [] as $sheetName => &$sheetData) {
            $sheetConfig = $dataStructure['sheets'][$sheetName] ?? [];

            foreach ($sheetData['lDEF'] ?? [] as $fieldPath => &$fieldData) {
                $elementConfig = $this->getFlexFormElementConfig($sheetConfig, $fieldPath);

                // Check if this is a vault secret field
                if (($elementConfig['config']['renderType'] ?? '') !== 'vaultSecret') {
                    continue;
                }

                $value = $fieldData['vDEF'] ?? '';

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

                // Skip if unchanged
                if ($secretValue === '' && $originalChecksum === '') {
                    continue;
                }

                // Store for post-processing
                $this->pendingFlexSecrets[$table][$id][] = [
                    'flexField' => $flexFieldName,
                    'sheet' => $sheetName,
                    'fieldPath' => $fieldPath,
                    'value' => $secretValue,
                    'identifier' => $vaultIdentifier,
                    'originalChecksum' => $originalChecksum,
                ];

                // Replace with placeholder
                $fieldData['vDEF'] = $secretValue !== '' ? '__VAULT__' : '';
            }
        }
    }

    /**
     * Store a FlexForm vault secret.
     */
    private function storeFlexFormSecret(
        array $secretData,
        string $table,
        int $uid,
        DataHandler $dataHandler,
    ): void {
        $vaultIdentifier = $this->buildFlexFormVaultIdentifier(
            $table,
            $secretData['flexField'],
            $secretData['sheet'],
            $secretData['fieldPath'],
            $uid,
        );

        try {
            $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

            if ($secretData['value'] === '') {
                // Delete secret if cleared
                if ($secretData['originalChecksum'] !== '') {
                    $vaultService->delete($vaultIdentifier, 'FlexForm field cleared');
                }
            } elseif ($secretData['originalChecksum'] === '') {
                // New secret
                $vaultService->store($vaultIdentifier, $secretData['value'], [
                    'table' => $table,
                    'flexField' => $secretData['flexField'],
                    'sheet' => $secretData['sheet'],
                    'fieldPath' => $secretData['fieldPath'],
                    'uid' => $uid,
                    'source' => 'flexform_field',
                ]);
            } else {
                // Update existing
                $vaultService->rotate($vaultIdentifier, $secretData['value'], 'FlexForm field updated');
            }
        } catch (VaultException $e) {
            $dataHandler->log(
                $table,
                $uid,
                2,
                0,
                1,
                'Vault error for FlexForm field "' . $secretData['fieldPath'] . '": ' . $e->getMessage(),
            );
        }
    }

    /**
     * Build vault identifier for FlexForm field.
     */
    private function buildFlexFormVaultIdentifier(
        string $table,
        string $flexField,
        string $sheet,
        string $fieldPath,
        int $uid,
    ): string {
        // Format: table__flexfield__sheet__fieldpath__uid
        // Replace dots/slashes in fieldPath with underscores
        $safeFieldPath = str_replace(['.', '/'], '_', $fieldPath);

        return \sprintf('%s__%s__%s__%s__%d', $table, $flexField, $sheet, $safeFieldPath, $uid);
    }

    /**
     * Get the FlexForm data structure.
     */
    private function getFlexFormDataStructure(array $fieldConfig, array $data): ?array
    {
        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);

        try {
            $dataStructureIdentifier = $flexFormTools->getDataStructureIdentifier(
                $fieldConfig,
                '',
                '',
                $data,
            );

            return $flexFormTools->parseDataStructureByIdentifier($dataStructureIdentifier);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Get FlexForm element configuration from sheet config.
     */
    private function getFlexFormElementConfig(array $sheetConfig, string $fieldPath): array
    {
        return $sheetConfig['ROOT']['el'][$fieldPath] ?? [];
    }
}
