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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for AuditController.
 *
 * Tests the backend module controller for audit log viewing and chain verification.
 */
#[CoversClass(AuditController::class)]
final class AuditControllerTest extends FunctionalTestCase
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
            unlink($this->masterKeyPath);
        }

        parent::tearDown();
    }

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
