<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Configuration;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Extension configuration wrapper with typed accessors.
 */
final class ExtensionConfiguration implements ExtensionConfigurationInterface, SingletonInterface
{
    // Default values as constants for maintainability
    public const DEFAULT_STORAGE_ADAPTER = 'local';

    public const DEFAULT_MASTER_KEY_PROVIDER = 'typo3';

    public const DEFAULT_MASTER_KEY_SOURCE = 'NR_VAULT_MASTER_KEY';

    public const DEFAULT_AUDIT_LOG_RETENTION = 365;

    public const DEFAULT_ALLOW_CLI_ACCESS = false;

    public const DEFAULT_CACHE_ENABLED = true;

    public const DEFAULT_PREFER_XCHACHA20 = false;

    private const EXTENSION_KEY = 'nr_vault';

    private array $configuration;

    public function __construct(
        private readonly Typo3ExtensionConfiguration $extensionConfiguration,
    ) {
        $this->configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY) ?? [];
    }

    /**
     * Get storage adapter identifier (local, hashicorp, aws).
     */
    public function getStorageAdapter(): string
    {
        return (string) ($this->configuration['storageAdapter'] ?? self::DEFAULT_STORAGE_ADAPTER);
    }

    /**
     * Get master key provider identifier (typo3, file, env, derived).
     */
    public function getMasterKeyProvider(): string
    {
        return (string) ($this->configuration['masterKeyProvider'] ?? self::DEFAULT_MASTER_KEY_PROVIDER);
    }

    /**
     * Get master key source (file path for 'file', env var name for 'env').
     */
    public function getMasterKeySource(): string
    {
        return (string) ($this->configuration['masterKeySource'] ?? self::DEFAULT_MASTER_KEY_SOURCE);
    }

    /**
     * Get audit log retention days (0 = forever).
     */
    public function getAuditLogRetention(): int
    {
        return (int) ($this->configuration['auditLogRetention'] ?? self::DEFAULT_AUDIT_LOG_RETENTION);
    }

    /**
     * Check if CLI access is allowed.
     */
    public function isCliAccessAllowed(): bool
    {
        return (bool) ($this->configuration['allowCliAccess'] ?? self::DEFAULT_ALLOW_CLI_ACCESS);
    }

    /**
     * Get backend groups that can access secrets via CLI.
     *
     * @return int[]
     */
    public function getCliAccessGroups(): array
    {
        $groups = $this->configuration['cliAccessGroups'] ?? [];
        if (\is_string($groups)) {
            $groups = array_filter(array_map('intval', explode(',', $groups)));
        }

        return array_map('intval', (array) $groups);
    }

    /**
     * Check if request-scoped caching is enabled.
     */
    public function isCacheEnabled(): bool
    {
        return (bool) ($this->configuration['cacheEnabled'] ?? self::DEFAULT_CACHE_ENABLED);
    }

    /**
     * Check if XChaCha20-Poly1305 should be preferred over AES-256-GCM.
     */
    public function preferXChaCha20(): bool
    {
        return (bool) ($this->configuration['preferXChaCha20'] ?? self::DEFAULT_PREFER_XCHACHA20);
    }

    /**
     * Get HashiCorp Vault configuration.
     *
     * @return array{address?: string, path?: string, authMethod?: string, token?: string}
     */
    public function getHashiCorpConfig(): array
    {
        return (array) ($this->configuration['hashicorp'] ?? []);
    }

    /**
     * Get AWS Secrets Manager configuration.
     *
     * @return array{region?: string, secretPrefix?: string}
     */
    public function getAwsConfig(): array
    {
        return (array) ($this->configuration['aws'] ?? []);
    }

    /**
     * Get the auto-generated key storage path (for development).
     */
    public function getAutoKeyPath(): string
    {
        return Environment::getVarPath() . '/secrets/vault-master.key';
    }
}
