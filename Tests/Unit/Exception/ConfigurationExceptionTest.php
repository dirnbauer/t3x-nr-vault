<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\ConfigurationException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

#[CoversNothing]
final class ConfigurationExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = ConfigurationException::invalidProvider('unknown');

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function invalidProvider(): void
    {
        $exception = ConfigurationException::invalidProvider('hashicorp');

        self::assertEquals('Unknown master key provider: hashicorp', $exception->getMessage());
        self::assertEquals(1703800015, $exception->getCode());
    }

    #[Test]
    public function invalidAdapter(): void
    {
        $exception = ConfigurationException::invalidAdapter('aws-secrets');

        self::assertEquals('Unknown vault adapter: aws-secrets', $exception->getMessage());
        self::assertEquals(1703800016, $exception->getCode());
    }

    #[Test]
    public function missingConfiguration(): void
    {
        $exception = ConfigurationException::missingConfiguration('masterKeyPath');

        self::assertEquals('Missing required configuration: masterKeyPath', $exception->getMessage());
        self::assertEquals(1703800017, $exception->getCode());
    }
}
