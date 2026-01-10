<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Exception;

use Netresearch\NrVault\Exception\VaultException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
