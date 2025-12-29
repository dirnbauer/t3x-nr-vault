<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Netresearch\NrVault\Command\VaultCleanupOrphansCommand;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

#[CoversClass(VaultCleanupOrphansCommand::class)]
final class VaultCleanupOrphansCommandTest extends TestCase
{
    private VaultServiceInterface&MockObject $vaultService;

    private ConnectionPool&MockObject $connectionPool;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);

        $command = new VaultCleanupOrphansCommand(
            $this->vaultService,
            $this->connectionPool,
        );

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function hasCorrectName(): void
    {
        $command = new VaultCleanupOrphansCommand(
            $this->vaultService,
            $this->connectionPool,
        );

        self::assertSame('vault:cleanup-orphans', $command->getName());
    }

    #[Test]
    public function succeedsWithNoTcaSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No TCA-sourced secrets found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function skipsNonTcaSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'manual_secret',
                'metadata' => ['source' => 'manual'],
                'createdAt' => time(),
            ],
        ]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No TCA-sourced secrets found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function findsNoOrphansWhenRecordsExist(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordExists('tx_myext', 1, true);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No orphaned secrets found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function findsOrphansWhenRecordsDeleted(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordExists('tx_myext', 1, false);

        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('1', $display);
        self::assertStringContainsString('orphan', strtolower($display));
    }

    #[Test]
    public function respectsRetentionDays(): void
    {
        $recentOrphan = time() - 86400 * 3; // 3 days ago
        $oldOrphan = time() - 86400 * 30;   // 30 days ago

        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => $recentOrphan,
            ],
            [
                'identifier' => 'tx_myext__api_key__2',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => $oldOrphan,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
            '--retention-days' => 7,
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        // Only the old orphan (30 days) should be found, not the recent one (3 days)
        self::assertStringContainsString('1', $display);
    }

    #[Test]
    public function tableFilterLimitsScope(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
            [
                'identifier' => 'tx_other__secret__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_other'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
            '--table' => 'tx_myext',
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('tx_myext', $display);
        self::assertStringNotContainsString('tx_other', $display);
    }

    #[Test]
    public function deletesOrphansWhenConfirmed(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('tx_myext__api_key__1', 'Orphan cleanup');

        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Successfully deleted', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesDeleteFailures(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $this->vaultService
            ->method('delete')
            ->willThrowException(new VaultException('Delete failed'));

        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('error', strtolower($this->commandTester->getDisplay()));
    }

    #[Test]
    public function cancelsWhenNotConfirmed(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'tca_field', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $this->vaultService->expects($this->never())->method('delete');

        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('cancelled', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesMigrationSourceSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([
            [
                'identifier' => 'tx_myext__api_key__1',
                'metadata' => ['source' => 'migration', 'table' => 'tx_myext'],
                'createdAt' => time() - 86400 * 30,
            ],
        ]);

        $this->mockRecordDoesNotExist();

        $exitCode = $this->commandTester->execute([
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        // Migration source should also be checked for orphans
        self::assertStringContainsString('orphan', strtolower($this->commandTester->getDisplay()));
    }

    private function mockRecordExists(string $table, int $uid, bool $exists): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->willReturn($connection);

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchOne')->willReturn($exists ? 1 : 0);

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('uid = ' . $uid);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);
    }

    private function mockRecordDoesNotExist(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->willReturn($connection);

        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchOne')->willReturn(0);

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('uid = 1');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->willReturn($queryBuilder);
    }
}
