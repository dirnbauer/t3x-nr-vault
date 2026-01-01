<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Audit;

use Doctrine\DBAL\Result;
use Netresearch\NrVault\Audit\AuditLogEntry;
use Netresearch\NrVault\Audit\AuditLogService;
use Netresearch\NrVault\Security\AccessControlServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(AuditLogService::class)]
#[CoversClass(AuditLogEntry::class)]
final class AuditLogServiceTest extends TestCase
{
    private AuditLogService $subject;

    private ConnectionPool&MockObject $connectionPool;

    private AccessControlServiceInterface&MockObject $accessControlService;

    private QueryBuilder&MockObject $queryBuilder;

    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->connection = $this->createMock(Connection::class);

        $this->accessControlService
            ->method('getCurrentActorUid')
            ->willReturn(1);
        $this->accessControlService
            ->method('getCurrentActorType')
            ->willReturn('backend');
        $this->accessControlService
            ->method('getCurrentActorUsername')
            ->willReturn('admin');
        $this->accessControlService
            ->method('getCurrentUserGroups')
            ->willReturn([]);

        $this->subject = new AuditLogService(
            $this->connectionPool,
            $this->accessControlService,
        );
    }

    #[Test]
    public function logCreatesAuditEntryForCreateAction(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['action'] === 'create'
                    && $data['secret_identifier'] === 'test_secret'
                    && $data['actor_uid'] === 1
                    && $data['actor_type'] === 'backend'),
            );

        $this->subject->log('test_secret', 'create', true, null, 'Test secret stored');
    }

    #[Test]
    public function logCreatesAuditEntryForReadAction(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['action'] === 'read'
                    && $data['secret_identifier'] === 'api_key'),
            );

        $this->subject->log('api_key', 'read', true);
    }

    #[Test]
    public function logCreatesAuditEntryForDeleteAction(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['action'] === 'delete'
                    && $data['secret_identifier'] === 'old_secret'),
            );

        $this->subject->log('old_secret', 'delete', true, null, 'Cleanup');
    }

    #[Test]
    public function logCreatesAuditEntryForRotateAction(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['action'] === 'rotate'
                    && $data['secret_identifier'] === 'rotated_secret'),
            );

        $this->subject->log('rotated_secret', 'rotate', true, null, 'Annual rotation');
    }

    #[Test]
    public function logCreatesAuditEntryForAccessDenied(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['action'] === 'access_denied'
                    && $data['secret_identifier'] === 'restricted_secret'
                    && $data['success'] === 0),
            );

        $this->subject->log('restricted_secret', 'access_denied', false, 'Permission denied');
    }

    #[Test]
    public function auditLogEntryContainsRequestContext(): void
    {
        $this->setupDatabaseMocks();

        // Set up server globals for request context
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit Test';

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => isset($data['ip_address'])
                    && isset($data['user_agent'], $data['request_id'])),
            );

        $this->subject->log('context_test', 'create', true, null, 'Testing context');

        // Cleanup
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    #[Test]
    public function hashChainLinksToLastEntry(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $result = $this->createMock(Result::class);
        // getLatestHash() uses fetchOne() which returns the value directly
        $result->method('fetchOne')->willReturn('previous_hash_abc123');

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        // The implementation uses $connection->createQueryBuilder()
        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder
            ->method('expr')
            ->willReturn($expressionBuilder);

        $this->queryBuilder
            ->method('select')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('from')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('orderBy')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('setMaxResults')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['previous_hash'] === 'previous_hash_abc123'),
            );

        $this->subject->log('chained_secret', 'create', true, null, 'Testing hash chain');
    }

    private function setupDatabaseMocks(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $result = $this->createMock(Result::class);
        // getLatestHash() uses fetchOne() which returns false when no rows exist
        $result->method('fetchOne')->willReturn(false);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        // The implementation uses $connection->createQueryBuilder()
        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder
            ->method('expr')
            ->willReturn($expressionBuilder);

        $this->queryBuilder
            ->method('select')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('from')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('orderBy')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('setMaxResults')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);
    }
}
