<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\MasterKeyException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class MasterKeyExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = MasterKeyException::notFound('/path/to/key');

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function notFound(): void
    {
        $exception = MasterKeyException::notFound('/var/vault/master.key');

        self::assertEquals('Master key not found at: /var/vault/master.key', $exception->getMessage());
        self::assertEquals(1703800008, $exception->getCode());
    }

    #[Test]
    public function invalidLength(): void
    {
        $exception = MasterKeyException::invalidLength(32, 16);

        self::assertEquals('Invalid master key length: expected 32 bytes, got 16', $exception->getMessage());
        self::assertEquals(1703800009, $exception->getCode());
    }

    #[Test]
    public function cannotStore(): void
    {
        $exception = MasterKeyException::cannotStore('directory not writable');

        self::assertEquals('Cannot store master key: directory not writable', $exception->getMessage());
        self::assertEquals(1703800010, $exception->getCode());
    }

    #[Test]
    public function environmentVariableNotSet(): void
    {
        $exception = MasterKeyException::environmentVariableNotSet('NR_VAULT_MASTER_KEY');

        self::assertEquals('Environment variable "NR_VAULT_MASTER_KEY" for master key is not set', $exception->getMessage());
        self::assertEquals(1703800011, $exception->getCode());
    }
}
