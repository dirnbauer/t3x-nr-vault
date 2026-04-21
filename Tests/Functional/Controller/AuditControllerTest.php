<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Audit\HashChainVerificationResult;
use Netresearch\NrVault\Controller\AuditController;
use Netresearch\NrVault\Tests\Functional\AbstractVaultFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for AuditController.
 *
 * Tests the backend module controller for audit log viewing and chain verification.
 */
#[CoversClass(AuditController::class)]
final class AuditControllerTest extends AbstractVaultFunctionalTestCase
{
    protected ?string $backendUserFixture = __DIR__ . '/../Hook/Fixtures/be_users.csv';

    #[Test]
    public function auditLogServiceIsInjectable(): void
    {
        $auditLogService = $this->get(AuditLogServiceInterface::class);

        self::assertInstanceOf(AuditLogServiceInterface::class, $auditLogService);
    }

    #[Test]
    public function auditLogServiceCanQueryEmptyLog(): void
    {
        $auditLogService = $this->get(AuditLogServiceInterface::class);

        $entries = $auditLogService->query(null, 10, 0);

        self::assertIsArray($entries);
        self::assertCount(0, $entries);
    }

    #[Test]
    public function auditLogServiceReturnsCorrectCount(): void
    {
        $auditLogService = $this->get(AuditLogServiceInterface::class);

        $count = $auditLogService->count();

        self::assertSame(0, $count);
    }

    #[Test]
    public function verifyHashChainReturnsValidForEmptyLog(): void
    {
        $auditLogService = $this->get(AuditLogServiceInterface::class);

        $result = $auditLogService->verifyHashChain();

        self::assertInstanceOf(HashChainVerificationResult::class, $result);
        self::assertTrue($result->valid);
    }

    #[Test]
    public function exportReturnsEmptyArrayForEmptyLog(): void
    {
        $auditLogService = $this->get(AuditLogServiceInterface::class);

        $entries = $auditLogService->export();

        self::assertIsArray($entries);
        self::assertCount(0, $entries);
    }
}
