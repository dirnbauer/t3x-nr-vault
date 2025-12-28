<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Exception\ConfigurationException;

/**
 * Factory for creating master key providers.
 */
final class MasterKeyProviderFactory
{
    public function __construct(
        private readonly ExtensionConfigurationInterface $configuration,
    ) {}

    public function create(): MasterKeyProviderInterface
    {
        $provider = $this->configuration->getMasterKeyProvider();

        return match ($provider) {
            'file' => new FileMasterKeyProvider($this->configuration),
            'env' => new EnvironmentMasterKeyProvider($this->configuration),
            default => throw ConfigurationException::invalidProvider($provider),
        };
    }

    /**
     * Get the configured provider, falling back to auto-detection.
     */
    public function getAvailableProvider(): MasterKeyProviderInterface
    {
        // Try configured provider first
        try {
            $provider = $this->create();
            if ($provider->isAvailable()) {
                return $provider;
            }
        } catch (ConfigurationException) {
            // Fall through to auto-detection
        }

        // Try environment variable
        $envProvider = new EnvironmentMasterKeyProvider($this->configuration);
        if ($envProvider->isAvailable()) {
            return $envProvider;
        }

        // Try file-based (including auto-generated)
        $fileProvider = new FileMasterKeyProvider($this->configuration);
        if ($fileProvider->isAvailable()) {
            return $fileProvider;
        }

        // No provider available - return file provider for initialization
        return $fileProvider;
    }
}
