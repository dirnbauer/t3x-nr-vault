<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class EncryptionExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = EncryptionException::encryptionFailed();

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function encryptionFailedWithoutReason(): void
    {
        $exception = EncryptionException::encryptionFailed();

        self::assertEquals('Encryption failed', $exception->getMessage());
        self::assertEquals(1703800005, $exception->getCode());
    }

    #[Test]
    public function encryptionFailedWithReason(): void
    {
        $exception = EncryptionException::encryptionFailed('invalid key length');

        self::assertEquals('Encryption failed: invalid key length', $exception->getMessage());
        self::assertEquals(1703800005, $exception->getCode());
    }

    #[Test]
    public function decryptionFailedWithoutReason(): void
    {
        $exception = EncryptionException::decryptionFailed();

        self::assertEquals('Decryption failed', $exception->getMessage());
        self::assertEquals(1703800006, $exception->getCode());
    }

    #[Test]
    public function decryptionFailedWithReason(): void
    {
        $exception = EncryptionException::decryptionFailed('corrupted ciphertext');

        self::assertEquals('Decryption failed: corrupted ciphertext', $exception->getMessage());
        self::assertEquals(1703800006, $exception->getCode());
    }

    #[Test]
    public function algorithmNotAvailable(): void
    {
        $exception = EncryptionException::algorithmNotAvailable('aes-512-gcm');

        self::assertEquals('Encryption algorithm "aes-512-gcm" is not available', $exception->getMessage());
        self::assertEquals(1703800007, $exception->getCode());
    }
}
