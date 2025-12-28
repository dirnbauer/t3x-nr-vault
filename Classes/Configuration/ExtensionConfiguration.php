<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Extension configuration wrapper with typed accessors.
 */
final class ExtensionConfiguration implements ExtensionConfigurationInterface, SingletonInterface
{
    private const EXTENSION_KEY = 'nr_vault';

    private array $configuration;

    public function __construct(
        private readonly Typo3ExtensionConfiguration $extensionConfiguration,
    ) {
        $this->configuration = $this->extensionConfiguration->get(self::EXTENSION_KEY) ?? [];
    }

    /**
     * Get storage adapter identifier.
     */
    public function getAdapter(): string
    {
        return (string) ($this->configuration['adapter'] ?? 'local');
    }

    /**
     * Get master key provider identifier.
     */
    public function getMasterKeyProvider(): string
    {
        return (string) ($this->configuration['masterKeyProvider'] ?? 'file');
    }

    /**
     * Get master key file path.
     */
    public function getMasterKeyPath(): string
    {
        return (string) ($this->configuration['masterKeyPath'] ?? '');
    }

    /**
     * Get master key environment variable name.
     */
    public function getMasterKeyEnvVar(): string
    {
        return (string) ($this->configuration['masterKeyEnvVar'] ?? 'NR_VAULT_MASTER_KEY');
    }

    /**
     * Get audit log retention days (0 = forever).
     */
    public function getAuditLogRetention(): int
    {
        return (int) ($this->configuration['auditLogRetention'] ?? 365);
    }

    /**
     * Check if CLI access is allowed.
     */
    public function isCliAccessAllowed(): bool
    {
        return (bool) ($this->configuration['allowCliAccess'] ?? false);
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
        return (bool) ($this->configuration['cacheEnabled'] ?? true);
    }

    /**
     * Check if XChaCha20-Poly1305 should be preferred over AES-256-GCM.
     */
    public function preferXChaCha20(): bool
    {
        return (bool) ($this->configuration['preferXChaCha20'] ?? false);
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
        return \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/secrets/vault-master.key';
    }
}
