<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service\Detection;

use Netresearch\NrVault\Service\Detection\Severity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Severity::class)]
final class SeverityTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, Severity::cases());
    }

    #[Test]
    public function criticalHasLowestOrder(): void
    {
        self::assertEquals(0, Severity::Critical->order());
    }

    #[Test]
    public function highHasOrderOne(): void
    {
        self::assertEquals(1, Severity::High->order());
    }

    #[Test]
    public function mediumHasOrderTwo(): void
    {
        self::assertEquals(2, Severity::Medium->order());
    }

    #[Test]
    public function lowHasHighestOrder(): void
    {
        self::assertEquals(3, Severity::Low->order());
    }

    #[Test]
    public function ordersAreInSeverityOrder(): void
    {
        self::assertLessThan(Severity::High->order(), Severity::Critical->order());
        self::assertLessThan(Severity::Medium->order(), Severity::High->order());
        self::assertLessThan(Severity::Low->order(), Severity::Medium->order());
    }

    #[Test]
    public function isAtLeastReturnsTrueWhenMoreSevere(): void
    {
        self::assertTrue(Severity::Critical->isAtLeast(Severity::High));
        self::assertTrue(Severity::Critical->isAtLeast(Severity::Medium));
        self::assertTrue(Severity::Critical->isAtLeast(Severity::Low));
        self::assertTrue(Severity::High->isAtLeast(Severity::Medium));
        self::assertTrue(Severity::High->isAtLeast(Severity::Low));
        self::assertTrue(Severity::Medium->isAtLeast(Severity::Low));
    }

    #[Test]
    public function isAtLeastReturnsTrueForSameSeverity(): void
    {
        self::assertTrue(Severity::Critical->isAtLeast(Severity::Critical));
        self::assertTrue(Severity::High->isAtLeast(Severity::High));
        self::assertTrue(Severity::Medium->isAtLeast(Severity::Medium));
        self::assertTrue(Severity::Low->isAtLeast(Severity::Low));
    }

    #[Test]
    public function isAtLeastReturnsFalseWhenLessSevere(): void
    {
        self::assertFalse(Severity::Low->isAtLeast(Severity::Critical));
        self::assertFalse(Severity::Low->isAtLeast(Severity::High));
        self::assertFalse(Severity::Low->isAtLeast(Severity::Medium));
        self::assertFalse(Severity::Medium->isAtLeast(Severity::Critical));
        self::assertFalse(Severity::Medium->isAtLeast(Severity::High));
        self::assertFalse(Severity::High->isAtLeast(Severity::Critical));
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertEquals(Severity::Critical, Severity::from('critical'));
        self::assertEquals(Severity::High, Severity::from('high'));
        self::assertEquals(Severity::Medium, Severity::from('medium'));
        self::assertEquals(Severity::Low, Severity::from('low'));
    }

    #[Test]
    public function valueReturnsStringRepresentation(): void
    {
        self::assertEquals('critical', Severity::Critical->value);
        self::assertEquals('high', Severity::High->value);
        self::assertEquals('medium', Severity::Medium->value);
        self::assertEquals('low', Severity::Low->value);
    }
}
