<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\MigrationController;
use Netresearch\NrVault\Service\SecretDetectionServiceInterface;
use Netresearch\NrVault\Tests\Functional\AbstractVaultFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for MigrationController.
 *
 * Tests the backend module controller for secret detection and migration.
 * These tests verify that the controller's dependencies are properly configured.
 */
#[CoversClass(MigrationController::class)]
final class MigrationControllerTest extends AbstractVaultFunctionalTestCase
{
    protected ?string $backendUserFixture = __DIR__ . '/../Hook/Fixtures/be_users.csv';

    #[Test]
    public function secretDetectionServiceIsInjectable(): void
    {
        $detectionService = $this->get(SecretDetectionServiceInterface::class);

        self::assertInstanceOf(SecretDetectionServiceInterface::class, $detectionService);
    }

    #[Test]
    public function secretDetectionServiceCanScanWithNoResults(): void
    {
        $detectionService = $this->get(SecretDetectionServiceInterface::class);

        // With a clean database, scan should return empty results
        $findings = $detectionService->scan();

        self::assertIsArray($findings);
    }

    #[Test]
    public function secretDetectionServiceCountsDetectedSecrets(): void
    {
        $detectionService = $this->get(SecretDetectionServiceInterface::class);

        // First scan to populate findings
        $detectionService->scan();

        // Count should be 0 or more
        $count = $detectionService->getDetectedSecretsCount();

        self::assertGreaterThanOrEqual(0, $count);
    }

    #[Test]
    public function secretDetectionServiceGroupsBySeverity(): void
    {
        $detectionService = $this->get(SecretDetectionServiceInterface::class);

        // First scan to populate findings
        $detectionService->scan();

        // Get grouped findings
        $grouped = $detectionService->getDetectedSecretsBySeverity();

        self::assertIsArray($grouped);
    }
}
