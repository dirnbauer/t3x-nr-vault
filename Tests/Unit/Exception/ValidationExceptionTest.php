<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Exception\VaultException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValidationException::class)]
final class ValidationExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = ValidationException::emptySecret();

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function invalidIdentifier(): void
    {
        $exception = ValidationException::invalidIdentifier('bad id!', 'contains invalid characters');

        self::assertEquals('Invalid secret identifier "bad id!": contains invalid characters', $exception->getMessage());
        self::assertEquals(1703800012, $exception->getCode());
    }

    #[Test]
    public function emptySecret(): void
    {
        $exception = ValidationException::emptySecret();

        self::assertEquals('Secret value cannot be empty', $exception->getMessage());
        self::assertEquals(1703800013, $exception->getCode());
    }

    #[Test]
    public function invalidOption(): void
    {
        $exception = ValidationException::invalidOption('expiresAt', 'must be a positive integer');

        self::assertEquals('Invalid option "expiresAt": must be a positive integer', $exception->getMessage());
        self::assertEquals(1703800014, $exception->getCode());
    }
}
