<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Netresearch\NrVault\Command\VaultMigrateFieldCommand;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(VaultMigrateFieldCommand::class)]
final class VaultMigrateFieldCommandTest extends TestCase
{
    private VaultServiceInterface&MockObject $vaultService;

    private ConnectionPool&MockObject $connectionPool;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);

        $command = new VaultMigrateFieldCommand(
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
        $command = new VaultMigrateFieldCommand(
            $this->vaultService,
            $this->connectionPool,
        );

        self::assertSame('vault:migrate-field', $command->getName());
    }

    #[Test]
    public function failsWhenTableDoesNotExist(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->with(['nonexistent_table'])->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->with(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->willReturn($connection);

        $exitCode = $this->commandTester->execute([
            'table' => 'nonexistent_table',
            'field' => 'secret_field',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('does not exist', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWhenFieldDoesNotExist(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->with(['tx_myext_settings'])->willReturn(true);
        $schemaManager->method('listTableColumns')->with('tx_myext_settings')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->with(ConnectionPool::DEFAULT_CONNECTION_NAME)
            ->willReturn($connection);

        $exitCode = $this->commandTester->execute([
            'table' => 'tx_myext_settings',
            'field' => 'nonexistent_field',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('does not exist', $this->commandTester->getDisplay());
    }

    #[Test]
    public function succeedsWithNoRecordsToMigrate(): void
    {
        $this->mockTableAndFieldExist('tx_myext_settings', 'api_key');
        $this->mockQueryReturnsRecords('tx_myext_settings', []);

        $exitCode = $this->commandTester->execute([
            'table' => 'tx_myext_settings',
            'field' => 'api_key',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No records found to migrate', $this->commandTester->getDisplay());
    }

    #[Test]
    public function dryRunShowsRecordsWithoutMigrating(): void
    {
        $this->mockTableAndFieldExist('tx_myext_settings', 'api_key');
        $this->mockQueryReturnsRecords('tx_myext_settings', [
            ['uid' => 1, 'api_key' => 'secret123'],
            ['uid' => 2, 'api_key' => 'secret456'],
        ]);

        $this->vaultService->expects($this->never())->method('store');

        $exitCode = $this->commandTester->execute([
            'table' => 'tx_myext_settings',
            'field' => 'api_key',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Dry Run', $display);
        self::assertStringContainsString('would be migrated', $display);
    }

    #[Test]
    public function migratesRecordsWhenConfirmed(): void
    {
        $this->mockTableAndFieldExist('tx_myext_settings', 'api_key');
        $this->mockQueryReturnsRecords('tx_myext_settings', [
            ['uid' => 1, 'api_key' => 'secret123'],
        ]);

        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with(
                'tx_myext_settings__api_key__1',
                'secret123',
                $this->callback(fn (array $metadata): bool => $metadata['table'] === 'tx_myext_settings'
                    && $metadata['field'] === 'api_key'
                    && $metadata['uid'] === 1
                    && $metadata['source'] === 'migration'),
            );

        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([
            'table' => 'tx_myext_settings',
            'field' => 'api_key',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Successfully migrated', $this->commandTester->getDisplay());
    }

    #[Test]
    public function cancelsWhenNotConfirmed(): void
    {
        $this->mockTableAndFieldExist('tx_myext_settings', 'api_key');
        $this->mockQueryReturnsRecords('tx_myext_settings', [
            ['uid' => 1, 'api_key' => 'secret123'],
        ]);

        $this->vaultService->expects($this->never())->method('store');

        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([
            'table' => 'tx_myext_settings',
            'field' => 'api_key',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('cancelled', $this->commandTester->getDisplay());
    }

    private function mockTableAndFieldExist(string $table, string $field): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->willReturn(true);
        $schemaManager->method('listTableColumns')->willReturn([
            $field => new stdClass(),
        ]);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $this->connectionPool
            ->method('getConnectionByName')
            ->willReturn($connection);
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function mockQueryReturnsRecords(string $table, array $records): void
    {
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn($records);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('neq')->willReturn('field != ""');
        $expressionBuilder->method('isNotNull')->willReturn('field IS NOT NULL');
        $expressionBuilder->method('notLike')->willReturn('field NOT LIKE pattern');
        $expressionBuilder->method('eq')->willReturn('deleted = 0');

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
            ->with($table)
            ->willReturn($queryBuilder);
    }
}
