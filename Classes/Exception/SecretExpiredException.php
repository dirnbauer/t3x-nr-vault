<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

/**
 * Thrown when attempting to access an expired secret.
 */
final class SecretExpiredException extends VaultException
{
    public static function forIdentifier(string $identifier, int $expiredAt): self
    {
        return new self(
            sprintf(
                'Secret "%s" expired at %s',
                $identifier,
                date('Y-m-d H:i:s', $expiredAt)
            ),
            1703800002
        );
    }
}
