<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\VaultFieldPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VaultFieldPermission::class)]
final class VaultFieldPermissionTest extends TestCase
{
    #[Test]
    public function hasFourCases(): void
    {
        self::assertCount(4, VaultFieldPermission::cases());
    }

    #[Test]
    public function revealHasCorrectValue(): void
    {
        self::assertEquals('reveal', VaultFieldPermission::Reveal->value);
    }

    #[Test]
    public function copyHasCorrectValue(): void
    {
        self::assertEquals('copy', VaultFieldPermission::Copy->value);
    }

    #[Test]
    public function editHasCorrectValue(): void
    {
        self::assertEquals('edit', VaultFieldPermission::Edit->value);
    }

    #[Test]
    public function readOnlyHasCorrectValue(): void
    {
        self::assertEquals('readOnly', VaultFieldPermission::ReadOnly->value);
    }

    #[Test]
    public function canBeCreatedFromString(): void
    {
        self::assertEquals(VaultFieldPermission::Reveal, VaultFieldPermission::from('reveal'));
        self::assertEquals(VaultFieldPermission::Copy, VaultFieldPermission::from('copy'));
        self::assertEquals(VaultFieldPermission::Edit, VaultFieldPermission::from('edit'));
        self::assertEquals(VaultFieldPermission::ReadOnly, VaultFieldPermission::from('readOnly'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(VaultFieldPermission::tryFrom('invalid'));
        self::assertNull(VaultFieldPermission::tryFrom(''));
    }
}
