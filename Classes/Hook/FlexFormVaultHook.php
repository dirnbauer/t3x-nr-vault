<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Exception;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\Dto\FlexFormPendingSecret;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

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
                $vaultIdentifier = $isNewSecret ? $this->generateUuid() : $existingIdentifier;

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
        } catch (VaultException $e) {
            /** @phpstan-ignore method.internal */
            $dataHandler->log(
                $table,
                $uid,
                2,
                null,
                1,
                'Vault error for FlexForm field "' . $pending->fieldPath . '": ' . $e->getMessage(),
            );
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

        return \sprintf(
            '%08x-%04x-7%03x-%04x-%012x',
            ($time >> 16) & 0xFFFFFFFF,
            $time & 0xFFFF,
            \ord($random[0]) << 4 | \ord($random[1]) >> 4 & 0x0FFF,
            (\ord($random[1]) & 0x0F) << 8 | \ord($random[2]) & 0x3FFF | 0x8000,
            (\ord($random[3]) << 40) | (\ord($random[4]) << 32) | (\ord($random[5]) << 24)
                | (\ord($random[6]) << 16) | (\ord($random[7]) << 8) | \ord($random[8]),
        );
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
}
