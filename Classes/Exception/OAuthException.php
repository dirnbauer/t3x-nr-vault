<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Exception;

use Throwable;

/**
 * Thrown when OAuth 2.0 token operations fail.
 */
final class OAuthException extends VaultException
{
    public static function tokenRequestFailed(int $statusCode): self
    {
        return new self(
            \sprintf('OAuth token request failed with status %d', $statusCode),
            2477018617,
        );
    }

    public static function missingAccessToken(): self
    {
        return new self(
            'OAuth response missing access_token',
            9878610721,
        );
    }

    public static function invalidJsonResponse(Throwable $previous): self
    {
        return new self(
            'Invalid JSON response from OAuth server',
            1703800020,
            $previous,
        );
    }

    public static function requestFailed(string $message, Throwable $previous): self
    {
        return new self(
            \sprintf('OAuth token request failed: %s', $message),
            1703800021,
            $previous,
        );
    }
}
