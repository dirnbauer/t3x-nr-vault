<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[CoversNothing]
final class VaultExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = new VaultException('test message', 123);

        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertEquals('test message', $exception->getMessage());
        self::assertEquals(123, $exception->getCode());
    }
}
