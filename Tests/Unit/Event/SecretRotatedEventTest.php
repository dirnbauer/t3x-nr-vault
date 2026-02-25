<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Event;

use Netresearch\NrVault\Event\SecretRotatedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretRotatedEvent::class)]
final class SecretRotatedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $event = new SecretRotatedEvent('my-secret', 5, 42, 'scheduled rotation');

        self::assertEquals('my-secret', $event->getIdentifier());
        self::assertEquals(5, $event->getNewVersion());
        self::assertEquals(42, $event->getActorUid());
        self::assertEquals('scheduled rotation', $event->getReason());
    }

    #[Test]
    public function reasonDefaultsToEmptyString(): void
    {
        $event = new SecretRotatedEvent('my-secret', 2, 42);

        self::assertEquals('', $event->getReason());
    }
}
