<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

/**
 * Thrown when access to a secret is denied.
 */
final class AccessDeniedException extends VaultException
{
    public static function forIdentifier(string $identifier, string $reason = ''): self
    {
        $message = \sprintf('Access denied to secret "%s"', $identifier);
        if ($reason !== '') {
            $message .= ': ' . $reason;
        }

        return new self($message, 1703800003);
    }

    public static function cliAccessDisabled(): self
    {
        return new self('CLI access to vault is disabled', 1703800004);
    }
}
