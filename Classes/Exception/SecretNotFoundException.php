<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

/**
 * Thrown when a requested secret does not exist.
 */
final class SecretNotFoundException extends VaultException
{
    public static function forIdentifier(string $identifier): self
    {
        return new self(
            \sprintf('Secret with identifier "%s" not found', $identifier),
            1703800001,
        );
    }
}
