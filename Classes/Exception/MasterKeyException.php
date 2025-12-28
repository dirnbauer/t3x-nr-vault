<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

/**
 * Thrown when master key operations fail.
 */
final class MasterKeyException extends VaultException
{
    public static function notFound(string $location): self
    {
        return new self(
            \sprintf('Master key not found at: %s', $location),
            1703800008,
        );
    }

    public static function invalidLength(int $expected, int $actual): self
    {
        return new self(
            \sprintf('Invalid master key length: expected %d bytes, got %d', $expected, $actual),
            1703800009,
        );
    }

    public static function cannotStore(string $reason): self
    {
        return new self(
            \sprintf('Cannot store master key: %s', $reason),
            1703800010,
        );
    }

    public static function environmentVariableNotSet(string $varName): self
    {
        return new self(
            \sprintf('Environment variable "%s" for master key is not set', $varName),
            1703800011,
        );
    }
}
