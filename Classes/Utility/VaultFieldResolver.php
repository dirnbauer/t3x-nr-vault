<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Utility;

use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility for resolving vault identifiers to actual secret values.
 *
 * Vault identifiers are UUIDs stored in TCA fields with renderType 'vaultSecret'.
 * Use this in your extension code to retrieve secrets stored via vault TCA fields.
 *
 * Example:
 *   // Resolve specific fields
 *   $settings = $this->getTypoScriptSettings();
 *   $resolved = VaultFieldResolver::resolveFields($settings, ['api_key', 'api_secret']);
 *   // Now $resolved['api_key'] contains the actual secret value
 *
 *   // Or resolve all vault fields in a record
 *   $endpoint = $this->endpointRepository->findByUid(42);
 *   $resolved = VaultFieldResolver::resolveRecord('tx_myext_apiendpoint', $endpoint);
 */
final class VaultFieldResolver
{
    /**
     * UUID v7 pattern for vault identifiers.
     */
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Resolve vault identifiers in an array to their actual secret values.
     *
     * @param array<string, mixed> $data Record data potentially containing vault identifiers
     * @param array<string> $fields Field names to check and resolve
     * @param bool $throwOnError If true, throws exception on vault errors; if false, sets field to null
     *
     * @throws VaultException If throwOnError is true and vault retrieval fails
     *
     * @return array<string, mixed> Data with vault identifiers replaced by actual values
     */
    public static function resolveFields(array $data, array $fields, bool $throwOnError = false): array
    {
        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

        foreach ($fields as $field) {
            if (!isset($data[$field])) {
                continue;
            }

            $value = $data[$field];

            if (!self::isVaultIdentifier($value)) {
                continue;
            }

            try {
                $data[$field] = $vaultService->retrieve($value);
            } catch (SecretNotFoundException) {
                $data[$field] = null;
            } catch (VaultException $e) {
                if ($throwOnError) {
                    throw $e;
                }
                self::getLogger()->error('Failed to resolve vault field', [
                    'field' => $field,
                    'identifier' => $value,
                    'error' => $e->getMessage(),
                ]);
                $data[$field] = null;
            }
        }

        return $data;
    }

    /**
     * Resolve a single vault identifier to its secret value.
     *
     * @param string $identifier The vault identifier (UUID)
     *
     * @throws VaultException On vault errors (if identifier is valid but retrieval fails)
     *
     * @return string|null The secret value, or null if not found or invalid identifier
     */
    public static function resolve(string $identifier): ?string
    {
        if (!self::isVaultIdentifier($identifier)) {
            return null;
        }

        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

        try {
            return $vaultService->retrieve($identifier);
        } catch (SecretNotFoundException) {
            return null;
        }
    }

    /**
     * Resolve all vault fields in a record based on TCA configuration.
     *
     * Automatically detects which fields use renderType 'vaultSecret'.
     *
     * @param string $table The TCA table name
     * @param array<string, mixed> $record The record data
     *
     * @return array<string, mixed> Record with vault fields resolved
     */
    public static function resolveRecord(string $table, array $record): array
    {
        $vaultFields = self::getVaultFieldsForTable($table);

        if ($vaultFields === []) {
            return $record;
        }

        return self::resolveFields($record, $vaultFields);
    }

    /**
     * Check if a value looks like a vault identifier (UUID v4).
     *
     * @param mixed $value The value to check
     *
     * @return bool True if the value appears to be a vault identifier
     */
    public static function isVaultIdentifier(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }

        return (bool) preg_match(self::UUID_PATTERN, $value);
    }

    /**
     * Get list of vault field names for a table from TCA.
     *
     * @param string $table The table name
     *
     * @return array<string> Field names that use vaultSecret renderType
     */
    public static function getVaultFieldsForTable(string $table): array
    {
        $tcaColumns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $vaultFields = [];

        foreach ($tcaColumns as $fieldName => $fieldConfig) {
            $renderType = $fieldConfig['config']['renderType'] ?? '';
            if ($renderType === 'vaultSecret') {
                $vaultFields[] = $fieldName;
            }
        }

        return $vaultFields;
    }

    /**
     * Check if a table has any vault fields configured.
     *
     * @param string $table The table name
     *
     * @return bool True if the table has vault fields
     */
    public static function hasVaultFields(string $table): bool
    {
        return self::getVaultFieldsForTable($table) !== [];
    }

    private static function getLogger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LoggerInterface::class);
    }
}
