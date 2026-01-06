<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\VaultException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretExpiredException::class)]
final class SecretExpiredExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = SecretExpiredException::forIdentifier('test', time());

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function forIdentifier(): void
    {
        $expiredAt = 1704067200; // 2024-01-01 00:00:00 UTC
        $exception = SecretExpiredException::forIdentifier('api-key', $expiredAt);

        self::assertStringContainsString('Secret "api-key" expired at', $exception->getMessage());
        self::assertStringContainsString('2024-01-01', $exception->getMessage());
        self::assertEquals(1703800002, $exception->getCode());
    }
}
