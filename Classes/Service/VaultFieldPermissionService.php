<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for checking TSconfig-based permissions on vault fields.
 *
 * TSconfig structure:
 *
 * vault.permissions {
 *     # Global defaults
 *     default {
 *         reveal = 1
 *         copy = 1
 *         edit = 1
 *     }
 *
 *     # Table-specific overrides
 *     tx_myext_settings {
 *         # All fields in this table
 *         default {
 *             reveal = 0
 *         }
 *
 *         # Specific field
 *         api_key {
 *             reveal = 1
 *             copy = 0
 *             edit = 1
 *             readOnly = 0
 *         }
 *     }
 * }
 */
final class VaultFieldPermissionService implements SingletonInterface
{
    public const PERMISSION_REVEAL = 'reveal';

    public const PERMISSION_COPY = 'copy';

    public const PERMISSION_EDIT = 'edit';

    public const PERMISSION_READ_ONLY = 'readOnly';

    /** @var array<string, bool> */
    private array $permissionCache = [];

    /**
     * Check if a specific action is allowed for a vault field.
     */
    public function isAllowed(
        string $table,
        string $field,
        string $permission,
        ?BackendUserAuthentication $backendUser = null,
    ): bool {
        $backendUser ??= $GLOBALS['BE_USER'] ?? null;

        if ($backendUser === null) {
            return false;
        }

        // Admins always have full access
        if ($backendUser->isAdmin()) {
            // For readOnly permission, admins should NOT be read-only (return false)
            // For all other permissions, admins have full access (return true)
            return $permission !== self::PERMISSION_READ_ONLY;
        }

        $cacheKey = \sprintf('%s:%s:%s:%d', $table, $field, $permission, $backendUser->user['uid'] ?? 0);

        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        $allowed = $this->checkPermission($table, $field, $permission, $backendUser);
        $this->permissionCache[$cacheKey] = $allowed;

        return $allowed;
    }

    /**
     * Get all permissions for a vault field.
     *
     * @return array{reveal: bool, copy: bool, edit: bool, readOnly: bool}
     */
    public function getPermissions(
        string $table,
        string $field,
        ?BackendUserAuthentication $backendUser = null,
    ): array {
        return [
            self::PERMISSION_REVEAL => $this->isAllowed($table, $field, self::PERMISSION_REVEAL, $backendUser),
            self::PERMISSION_COPY => $this->isAllowed($table, $field, self::PERMISSION_COPY, $backendUser),
            self::PERMISSION_EDIT => $this->isAllowed($table, $field, self::PERMISSION_EDIT, $backendUser),
            self::PERMISSION_READ_ONLY => $this->isAllowed($table, $field, self::PERMISSION_READ_ONLY, $backendUser),
        ];
    }

    /**
     * Check if field should be rendered as read-only.
     */
    public function isReadOnly(
        string $table,
        string $field,
        ?BackendUserAuthentication $backendUser = null,
    ): bool {
        return $this->isAllowed($table, $field, self::PERMISSION_READ_ONLY, $backendUser);
    }

    /**
     * Clear the permission cache.
     */
    public function clearCache(): void
    {
        $this->permissionCache = [];
    }

    private function checkPermission(
        string $table,
        string $field,
        string $permission,
        BackendUserAuthentication $backendUser,
    ): bool {
        $tsConfig = $this->getVaultTsConfig($backendUser);

        // Check field-specific setting: vault.permissions.{table}.{field}.{permission}
        $fieldValue = $this->getNestedValue($tsConfig, [$table, $field, $permission]);
        if ($fieldValue !== null) {
            return $this->toBoolean($fieldValue);
        }

        // Check table default: vault.permissions.{table}.default.{permission}
        $tableDefault = $this->getNestedValue($tsConfig, [$table, 'default', $permission]);
        if ($tableDefault !== null) {
            return $this->toBoolean($tableDefault);
        }

        // Check global default: vault.permissions.default.{permission}
        $globalDefault = $this->getNestedValue($tsConfig, ['default', $permission]);
        if ($globalDefault !== null) {
            return $this->toBoolean($globalDefault);
        }

        // No explicit setting - use built-in defaults
        return $this->getBuiltInDefault($permission);
    }

    /**
     * @return array<string, mixed>
     */
    private function getVaultTsConfig(BackendUserAuthentication $backendUser): array
    {
        $pageTsConfig = BackendUtility::getPagesTSconfig(0);

        return $pageTsConfig['vault.']['permissions.'] ?? [];
    }

    /**
     * @param array<string, mixed> $array
     * @param array<int, string> $keys
     */
    private function getNestedValue(array $array, array $keys): mixed
    {
        $current = $array;

        foreach ($keys as $key) {
            // Try with trailing dot (TSconfig convention for nested)
            if (isset($current[$key . '.'])) {
                $current = $current[$key . '.'];
            } elseif (isset($current[$key])) {
                return $current[$key];
            } else {
                return null;
            }
        }

        return null;
    }

    private function toBoolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_string($value)) {
            return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    private function getBuiltInDefault(string $permission): bool
    {
        return match ($permission) {
            self::PERMISSION_REVEAL => true,
            self::PERMISSION_COPY => true,
            self::PERMISSION_EDIT => true,
            self::PERMISSION_READ_ONLY => false,
            default => false,
        };
    }
}
