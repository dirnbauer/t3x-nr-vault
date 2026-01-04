<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Utility;

use Deprecated;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Utility for resolving vault secrets in FlexForm data.
 *
 * Use this to retrieve actual secret values from FlexForm settings
 * that were stored using the vaultSecret renderType.
 *
 * FlexForm vault fields store UUIDs just like regular TCA vault fields.
 *
 * Example:
 *   $flexFormService = GeneralUtility::makeInstance(FlexFormService::class);
 *   $settings = $flexFormService->convertFlexFormContentToArray($record['pi_flexform']);
 *   $resolved = FlexFormVaultResolver::resolveSettings($settings, ['apiKey', 'apiSecret']);
 */
final class FlexFormVaultResolver
{
    /** UUID v7 pattern for vault identifiers. */
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Resolve vault identifiers in FlexForm settings.
     *
     * @param array<string, mixed> $settings FlexForm settings array
     * @param array<string> $fields Field names to resolve
     * @param bool $throwOnError Throw exception on vault errors
     *
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

            if (!self::isVaultIdentifier($value)) {
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
     *
     * @return array<string, mixed> Settings with all vault identifiers resolved
     */
    public static function resolveAll(array $settings): array
    {
        return self::resolveRecursive($settings);
    }

    /**
     * Check if a value is a vault identifier (UUID v7).
     *
     * Both FlexForm and regular TCA vault fields use the same UUID format.
     *
     * @param mixed $value Value to check
     *
     * @return bool True if it's a vault identifier
     */
    public static function isVaultIdentifier(mixed $value): bool
    {
        if (!\is_string($value) || $value === '') {
            return false;
        }

        return (bool) preg_match(self::UUID_PATTERN, $value);
    }

    /**
     * Check if a value is a FlexForm vault identifier (UUID v7).
     *
     * @param mixed $value Value to check
     *
     * @return bool True if it's a vault identifier
     */
    #[Deprecated(message: 'Use isVaultIdentifier() instead. FlexForm and TCA fields use the same UUID format.')]
    public static function isFlexFormVaultIdentifier(mixed $value): bool
    {
        return self::isVaultIdentifier($value);
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
            } elseif (self::isVaultIdentifier($value)) {
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
