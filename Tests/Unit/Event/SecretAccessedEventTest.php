<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Event;

use Netresearch\NrVault\Event\SecretAccessedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretAccessedEvent::class)]
final class SecretAccessedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $event = new SecretAccessedEvent('my-secret', 42, 'payment');

        self::assertEquals('my-secret', $event->getIdentifier());
        self::assertEquals(42, $event->getActorUid());
        self::assertEquals('payment', $event->getContext());
    }

    #[Test]
    public function contextDefaultsToEmptyString(): void
    {
        $event = new SecretAccessedEvent('my-secret', 42);

        self::assertEquals('', $event->getContext());
    }
}
