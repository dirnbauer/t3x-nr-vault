<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\VaultException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AccessDeniedExceptionTest extends TestCase
{
    #[Test]
    public function extendsVaultException(): void
    {
        $exception = AccessDeniedException::forIdentifier('test-secret');

        self::assertInstanceOf(VaultException::class, $exception);
    }

    #[Test]
    public function forIdentifierWithoutReason(): void
    {
        $exception = AccessDeniedException::forIdentifier('my-secret');

        self::assertEquals('Access denied to secret "my-secret"', $exception->getMessage());
        self::assertEquals(1703800003, $exception->getCode());
    }

    #[Test]
    public function forIdentifierWithReason(): void
    {
        $exception = AccessDeniedException::forIdentifier('my-secret', 'not in allowed groups');

        self::assertEquals('Access denied to secret "my-secret": not in allowed groups', $exception->getMessage());
        self::assertEquals(1703800003, $exception->getCode());
    }

    #[Test]
    public function cliAccessDisabled(): void
    {
        $exception = AccessDeniedException::cliAccessDisabled();

        self::assertEquals('CLI access to vault is disabled', $exception->getMessage());
        self::assertEquals(1703800004, $exception->getCode());
    }
}
