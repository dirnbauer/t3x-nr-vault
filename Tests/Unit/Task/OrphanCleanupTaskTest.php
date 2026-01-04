<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Task;

use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Netresearch\NrVault\Domain\Dto\SecretMetadata;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Task\OrphanCleanupTask;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(OrphanCleanupTask::class)]
final class OrphanCleanupTaskTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private VaultServiceInterface&MockObject $vaultService;

    private ConnectionPool&MockObject $connectionPool;

    private LoggerInterface&MockObject $logger;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Mock GeneralUtility::makeInstance
        $logManager = $this->createMock(LogManager::class);
        $logManager->method('getLogger')->willReturn($this->logger);

        GeneralUtility::addInstance(VaultServiceInterface::class, $this->vaultService);
        GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool);
        GeneralUtility::setSingletonInstance(LogManager::class, $logManager);
    }

    #[Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function hasDefaultRetentionDays(): void
    {
        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);

        $params = $task->getTaskParameters();
        self::assertSame(7, $params['nr_vault_retention_days']);
    }

    #[Test]
    public function hasEmptyDefaultTableFilter(): void
    {
        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);

        $params = $task->getTaskParameters();
        self::assertSame('', $params['nr_vault_table_filter']);
    }

    #[Test]
    public function returnsTrueWithNoSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([]);

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function skipsNonTcaSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata('manual_secret', time(), ['source' => 'manual']),
        ]);

        $this->vaultService->expects($this->never())->method('delete');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function skipsSecretsWithExistingRecords(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 30,
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
        ]);

        $this->mockRecordExists(1, true);
        $this->vaultService->expects($this->never())->method('delete');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function deletesOrphansOlderThanRetention(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 30, // 30 days old
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
        ]);

        $this->mockRecordExists(1, false);

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('tx_myext__api_key__1', 'Scheduler orphan cleanup');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->setTaskParameters(['nr_vault_retention_days' => 7]);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function skipsOrphansWithinRetentionPeriod(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 3, // 3 days old
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
        ]);

        $this->mockRecordExists(1, false);
        $this->vaultService->expects($this->never())->method('delete');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->setTaskParameters(['nr_vault_retention_days' => 7]);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function appliesTableFilter(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 30,
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
            $this->createSecretMetadata(
                'tx_other__secret__1',
                time() - 86400 * 30,
                ['source' => 'tca_field', 'table' => 'tx_other'],
            ),
        ]);

        $this->mockRecordExists(1, false);

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('tx_myext__api_key__1', 'Scheduler orphan cleanup');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->setTaskParameters([
            'nr_vault_retention_days' => 0,
            'nr_vault_table_filter' => 'tx_myext',
        ]);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function returnsFalseOnDeleteFailure(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 30,
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
        ]);

        $this->mockRecordExists(1, false);

        $this->vaultService
            ->method('delete')
            ->willThrowException(new VaultException('Delete failed'));

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->setTaskParameters(['nr_vault_retention_days' => 0]);
        $result = $task->execute();

        self::assertFalse($result);
    }

    #[Test]
    public function handlesMigrationSourceSecrets(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'tx_myext__api_key__1',
                time() - 86400 * 30,
                ['source' => 'migration', 'table' => 'tx_myext'],
            ),
        ]);

        $this->mockRecordExists(1, false);

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('tx_myext__api_key__1', 'Scheduler orphan cleanup');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->setTaskParameters(['nr_vault_retention_days' => 0]);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function skipsInvalidIdentifierFormats(): void
    {
        $this->vaultService->method('list')->willReturn([
            $this->createSecretMetadata(
                'invalid_format',
                time() - 86400 * 30,
                ['source' => 'tca_field', 'table' => 'tx_myext'],
            ),
        ]);

        $this->vaultService->expects($this->never())->method('delete');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $result = $task->execute();

        self::assertTrue($result);
    }

    #[Test]
    public function logsCleanupProgress(): void
    {
        $this->vaultService->method('list')->willReturn([]);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('info');

        $task = new OrphanCleanupTask($this->connectionPool, $this->vaultService);
        $task->execute();
    }

    private function mockRecordExists(int $uid, bool $exists): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->willReturn($connection);

        $result = $this->createMock(Result::class);
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function createSecretMetadata(
        string $identifier,
        int $createdAt,
        array $metadata = [],
    ): SecretMetadata {
        return new SecretMetadata(
            identifier: $identifier,
            ownerUid: 1,
            createdAt: $createdAt,
            updatedAt: $createdAt,
            readCount: 0,
            lastReadAt: null,
            description: '',
            version: 1,
            metadata: $metadata,
        );
    }
}
