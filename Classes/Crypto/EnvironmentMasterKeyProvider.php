<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Exception\MasterKeyException;

/**
 * Environment variable-based master key provider.
 */
final class EnvironmentMasterKeyProvider implements MasterKeyProviderInterface
{
    private const KEY_LENGTH = 32; // 256 bits

    public function __construct(
        private readonly ExtensionConfigurationInterface $configuration,
    ) {}

    public function getIdentifier(): string
    {
        return 'env';
    }

    public function isAvailable(): bool
    {
        $varName = $this->getEnvVarName();
        $value = getenv($varName);

        return $value !== false && $value !== '';
    }

    public function getMasterKey(): string
    {
        $varName = $this->getEnvVarName();
        $value = getenv($varName);

        if ($value === false || $value === '') {
            throw MasterKeyException::environmentVariableNotSet($varName);
        }

        // Handle base64-encoded keys
        $key = $value;
        if (\strlen($key) !== self::KEY_LENGTH) {
            $decoded = base64_decode($value, true);
            if ($decoded !== false && \strlen($decoded) === self::KEY_LENGTH) {
                $key = $decoded;
            }
        }

        if (\strlen($key) !== self::KEY_LENGTH) {
            throw MasterKeyException::invalidLength(self::KEY_LENGTH, \strlen($key));
        }

        return $key;
    }

    public function storeMasterKey(string $key): void
    {
        // Cannot store to environment variable at runtime
        throw MasterKeyException::cannotStore(
            'Environment variables cannot be persisted. Set the environment variable manually.',
        );
    }

    public function generateMasterKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }

    private function getEnvVarName(): string
    {
        $source = $this->configuration->getMasterKeySource();
        // For env provider, source is the environment variable name
        return $source !== '' ? $source : ExtensionConfiguration::DEFAULT_MASTER_KEY_SOURCE;
    }
}
