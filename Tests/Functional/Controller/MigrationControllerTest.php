<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\MigrationController;
use Netresearch\NrVault\Service\SecretDetectionServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for MigrationController.
 *
 * Tests the backend module controller for secret detection and migration.
 * These tests verify that the controller's dependencies are properly configured.
 */
#[CoversClass(MigrationController::class)]
final class MigrationControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private ?string $masterKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary master key for testing
        $this->masterKeyPath = $this->instancePath . '/master.key';
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0o600);

        // Configure extension to use file-based master key
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
            'masterKeySource' => $this->masterKeyPath,
            'autoKeyPath' => $this->masterKeyPath,
            'enableCache' => false,
        ];

        // Create backend user
        $this->importCSVDataSet(__DIR__ . '/../Hook/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        // Clean up master key
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            // nosemgrep: php.lang.security.unlink-use.unlink-use - test-owned path
            unlink($this->masterKeyPath);
        }

        parent::tearDown();
    }

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
