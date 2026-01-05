<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Event;

use Netresearch\NrVault\Event\SecretDeletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretDeletedEvent::class)]
final class SecretDeletedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $event = new SecretDeletedEvent('old-secret', 42, 'no longer needed');

        self::assertEquals('old-secret', $event->getIdentifier());
        self::assertEquals(42, $event->getActorUid());
        self::assertEquals('no longer needed', $event->getReason());
    }

    #[Test]
    public function reasonDefaultsToEmptyString(): void
    {
        $event = new SecretDeletedEvent('old-secret', 42);

        self::assertEquals('', $event->getReason());
    }
}
