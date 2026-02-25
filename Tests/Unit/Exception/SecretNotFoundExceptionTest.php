<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SecretNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = SecretNotFoundException::forIdentifier('test-secret');

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function forIdentifier(): void
    {
        $exception = SecretNotFoundException::forIdentifier('api-key-123');

        self::assertEquals('Secret with identifier "api-key-123" not found', $exception->getMessage());
        self::assertEquals(1703800001, $exception->getCode());
    }
}
