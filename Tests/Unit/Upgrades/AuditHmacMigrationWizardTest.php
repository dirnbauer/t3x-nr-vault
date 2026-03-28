<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Upgrades;

use Doctrine\DBAL\Result;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Upgrades\AuditHmacMigrationWizard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;

#[CoversClass(AuditHmacMigrationWizard::class)]
final class AuditHmacMigrationWizardTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;

    private MasterKeyProviderInterface&MockObject $masterKeyProvider;

    private ExtensionConfigurationInterface&MockObject $configuration;

    private AuditHmacMigrationWizard $subject;

    protected function setUp(): void
    {
        if (!interface_exists(UpgradeWizardInterface::class)) {
            self::markTestSkipped('UpgradeWizardInterface not available in TYPO3 v13');
        }

        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->masterKeyProvider = $this->createMock(MasterKeyProviderInterface::class);
        $this->configuration = $this->createMock(ExtensionConfigurationInterface::class);

        $this->subject = new AuditHmacMigrationWizard(
            $this->connectionPool,
            $this->masterKeyProvider,
            $this->configuration,
        );
    }

    #[Test]
    public function titleIsDescriptive(): void
    {
        self::assertStringContainsString('HMAC', $this->subject->getTitle());
    }

    #[Test]
    public function descriptionExplainsPurpose(): void
    {
        self::assertStringContainsString('tamper resistance', $this->subject->getDescription());
    }

    #[Test]
    public function updateNotNecessaryWhenEpochIsZero(): void
    {
        $this->configuration->method('getAuditHmacEpoch')->willReturn(0);

        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function updateNotNecessaryWhenNoLegacyEntries(): void
    {
        $this->configuration->method('getAuditHmacEpoch')->willReturn(1);

        $this->mockCountQuery(0);

        self::assertFalse($this->subject->updateNecessary());
    }

    #[Test]
    public function updateNecessaryWhenLegacyEntriesExist(): void
    {
        $this->configuration->method('getAuditHmacEpoch')->willReturn(1);

        $this->mockCountQuery(5);

        self::assertTrue($this->subject->updateNecessary());
    }

    #[Test]
    public function executeUpdateReturnsTrueWhenEpochIsZero(): void
    {
        $this->configuration->method('getAuditHmacEpoch')->willReturn(0);

        self::assertTrue($this->subject->executeUpdate());
    }

    #[Test]
    public function prerequisitesAreEmpty(): void
    {
        self::assertSame([], $this->subject->getPrerequisites());
    }

    private function mockCountQuery(int $count): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('hmac_key_epoch = 0');

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn($count);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);
    }
}
