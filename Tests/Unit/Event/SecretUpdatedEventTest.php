<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Event;

use Netresearch\NrVault\Event\SecretUpdatedEvent;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SecretUpdatedEvent::class)]
final class SecretUpdatedEventTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $event = new SecretUpdatedEvent('my-secret', 3, 42);

        self::assertEquals('my-secret', $event->getIdentifier());
        self::assertEquals(3, $event->getNewVersion());
        self::assertEquals(42, $event->getActorUid());
    }
}
