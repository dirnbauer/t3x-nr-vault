<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Configuration;

/**
 * Interface for extension configuration access.
 */
interface ExtensionConfigurationInterface
{
    /**
     * Get storage adapter identifier.
     */
    public function getAdapter(): string;

    /**
     * Get master key provider identifier.
     */
    public function getMasterKeyProvider(): string;

    /**
     * Get master key file path.
     */
    public function getMasterKeyPath(): string;

    /**
     * Get master key environment variable name.
     */
    public function getMasterKeyEnvVar(): string;

    /**
     * Get audit log retention days (0 = forever).
     */
    public function getAuditLogRetention(): int;

    /**
     * Check if CLI access is allowed.
     */
    public function isCliAccessAllowed(): bool;

    /**
     * Get backend groups that can access secrets via CLI.
     *
     * @return int[]
     */
    public function getCliAccessGroups(): array;

    /**
     * Check if request-scoped caching is enabled.
     */
    public function isCacheEnabled(): bool;

    /**
     * Check if XChaCha20-Poly1305 should be preferred over AES-256-GCM.
     */
    public function preferXChaCha20(): bool;

    /**
     * Get HashiCorp Vault configuration.
     *
     * @return array{address?: string, path?: string, authMethod?: string, token?: string}
     */
    public function getHashiCorpConfig(): array;

    /**
     * Get AWS Secrets Manager configuration.
     *
     * @return array{region?: string, secretPrefix?: string}
     */
    public function getAwsConfig(): array;

    /**
     * Get the auto-generated key storage path (for development).
     */
    public function getAutoKeyPath(): string;
}
