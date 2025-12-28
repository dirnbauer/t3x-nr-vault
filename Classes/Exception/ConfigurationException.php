<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

/**
 * Thrown when extension configuration is invalid.
 */
final class ConfigurationException extends VaultException
{
    public static function invalidProvider(string $provider): self
    {
        return new self(
            sprintf('Unknown master key provider: %s', $provider),
            1703800015
        );
    }

    public static function invalidAdapter(string $adapter): self
    {
        return new self(
            sprintf('Unknown vault adapter: %s', $adapter),
            1703800016
        );
    }

    public static function missingConfiguration(string $key): self
    {
        return new self(
            sprintf('Missing required configuration: %s', $key),
            1703800017
        );
    }
}
