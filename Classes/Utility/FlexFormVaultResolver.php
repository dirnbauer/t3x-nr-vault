<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Utility;

use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Service\FlexFormService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility for resolving vault secrets in FlexForm data.
 *
 * Use this to retrieve actual secret values from FlexForm settings
 * that were stored using the vaultSecret renderType.
 *
 * Example:
 *   $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
 *   $settings = $flexFormService->convertFlexFormContentToArray($record['pi_flexform']);
 *   $resolved = FlexFormVaultResolver::resolveSettings($settings, ['apiKey', 'apiSecret']);
 */
final class FlexFormVaultResolver
{
    private const VAULT_FLEXFORM_PATTERN = '/^[a-z0-9_]+__[a-z0-9_]+__[a-z0-9_]+__[a-z0-9_]+__\d+$/i';

    /**
     * Resolve vault identifiers in FlexForm settings.
     *
     * @param array<string, mixed> $settings FlexForm settings array
     * @param array<string> $fields Field names to resolve
     * @param bool $throwOnError Throw exception on vault errors
     * @return array<string, mixed> Settings with vault identifiers resolved
     */
    public static function resolveSettings(array $settings, array $fields, bool $throwOnError = false): array
    {
        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

        foreach ($fields as $field) {
            if (!isset($settings[$field])) {
                continue;
            }

            $value = $settings[$field];

            if (!self::isFlexFormVaultIdentifier($value)) {
                continue;
            }

            try {
                $settings[$field] = $vaultService->retrieve($value);
            } catch (SecretNotFoundException) {
                $settings[$field] = null;
            } catch (VaultException $e) {
                if ($throwOnError) {
                    throw $e;
                }
                self::getLogger()->error('Failed to resolve FlexForm vault field', [
                    'field' => $field,
                    'identifier' => $value,
                    'error' => $e->getMessage(),
                ]);
                $settings[$field] = null;
            }
        }

        return $settings;
    }

    /**
     * Resolve all vault identifiers in FlexForm settings recursively.
     *
     * This scans the entire settings array for vault identifiers
     * without needing to specify field names.
     *
     * @param array<string, mixed> $settings FlexForm settings array
     * @return array<string, mixed> Settings with all vault identifiers resolved
     */
    public static function resolveAll(array $settings): array
    {
        return self::resolveRecursive($settings);
    }

    /**
     * Check if a value is a FlexForm vault identifier.
     *
     * FlexForm vault identifiers have format:
     * {table}__{flexfield}__{sheet}__{fieldpath}__{uid}
     *
     * @param mixed $value Value to check
     * @return bool True if it's a FlexForm vault identifier
     */
    public static function isFlexFormVaultIdentifier(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }

        return (bool) preg_match(self::VAULT_FLEXFORM_PATTERN, $value);
    }

    /**
     * Build a FlexForm vault identifier.
     *
     * @param string $table Table name
     * @param string $flexField FlexForm field name (e.g., 'pi_flexform')
     * @param string $sheet Sheet name
     * @param string $fieldPath Field path within sheet
     * @param int $uid Record UID
     * @return string The vault identifier
     */
    public static function buildIdentifier(
        string $table,
        string $flexField,
        string $sheet,
        string $fieldPath,
        int $uid,
    ): string {
        $safeFieldPath = str_replace(['.', '/'], '_', $fieldPath);

        return \sprintf('%s__%s__%s__%s__%d', $table, $flexField, $sheet, $safeFieldPath, $uid);
    }

    /**
     * Parse a FlexForm vault identifier.
     *
     * @param string $identifier The vault identifier
     * @return array{table: string, flexField: string, sheet: string, fieldPath: string, uid: int}|null
     */
    public static function parseIdentifier(string $identifier): ?array
    {
        if (!self::isFlexFormVaultIdentifier($identifier)) {
            return null;
        }

        $parts = explode('__', $identifier);
        if (\count($parts) !== 5) {
            return null;
        }

        return [
            'table' => $parts[0],
            'flexField' => $parts[1],
            'sheet' => $parts[2],
            'fieldPath' => $parts[3],
            'uid' => (int) $parts[4],
        ];
    }

    /**
     * Resolve vault identifiers recursively in nested arrays.
     */
    private static function resolveRecursive(array $data): array
    {
        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::resolveRecursive($value);
            } elseif (self::isFlexFormVaultIdentifier($value) || VaultFieldResolver::isVaultIdentifier($value)) {
                try {
                    $data[$key] = $vaultService->retrieve($value);
                } catch (SecretNotFoundException) {
                    $data[$key] = null;
                } catch (VaultException $e) {
                    self::getLogger()->error('Failed to resolve vault identifier', [
                        'key' => $key,
                        'identifier' => $value,
                        'error' => $e->getMessage(),
                    ]);
                    $data[$key] = null;
                }
            }
        }

        return $data;
    }

    private static function getLogger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LoggerInterface::class);
    }
}
