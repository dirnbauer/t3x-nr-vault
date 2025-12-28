<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Audit;

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
    public function logStoreCreatesAuditEntry(): void
    {
        $this->setupDatabaseMocks();

        // Expect insert to be called
        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['action'] === 'store'
                        && $data['identifier'] === 'test_secret'
                        && $data['actor_uid'] === 1
                        && $data['actor_type'] === 'backend';
                }),
            );

        $this->subject->logStore('test_secret', 'Test secret stored');
    }

    #[Test]
    public function logRetrieveCreatesAuditEntry(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['action'] === 'retrieve'
                        && $data['identifier'] === 'api_key';
                }),
            );

        $this->subject->logRetrieve('api_key');
    }

    #[Test]
    public function logDeleteCreatesAuditEntry(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['action'] === 'delete'
                        && $data['identifier'] === 'old_secret';
                }),
            );

        $this->subject->logDelete('old_secret', 'Cleanup');
    }

    #[Test]
    public function logRotateCreatesAuditEntry(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['action'] === 'rotate'
                        && $data['identifier'] === 'rotated_secret';
                }),
            );

        $this->subject->logRotate('rotated_secret', 2, 'Annual rotation');
    }

    #[Test]
    public function logAccessDeniedCreatesAuditEntry(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['action'] === 'access_denied'
                        && $data['identifier'] === 'restricted_secret';
                }),
            );

        $this->subject->logAccessDenied('restricted_secret', 'retrieve');
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
                self::callback(static function (array $data): bool {
                    return isset($data['ip_address'])
                        && isset($data['user_agent'])
                        && isset($data['request_id']);
                }),
            );

        $this->subject->logStore('context_test', 'Testing context');

        // Cleanup
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    #[Test]
    public function hashChainLinksToLastEntry(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
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

        // Return a previous entry hash
        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn(new class {
                public function fetchAssociative(): array|false
                {
                    return ['entry_hash' => 'previous_hash_abc123'];
                }
            });

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data): bool {
                    return $data['previous_hash'] === 'previous_hash_abc123'
                        && !empty($data['entry_hash']);
                }),
            );

        $this->subject->logStore('chained_secret', 'Testing hash chain');
    }

    private function setupDatabaseMocks(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connectionPool
            ->method('getQueryBuilderForTable')
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
            ->willReturn(new class {
                public function fetchAssociative(): false
                {
                    return false;
                }
            });
    }
}
