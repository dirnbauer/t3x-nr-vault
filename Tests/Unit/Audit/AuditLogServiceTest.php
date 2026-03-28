<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Audit;

use DateTimeImmutable;
use Doctrine\DBAL\Result;
use Netresearch\NrVault\Audit\AuditLogEntry;
use Netresearch\NrVault\Audit\AuditLogFilter;
use Netresearch\NrVault\Audit\AuditLogService;
use Netresearch\NrVault\Audit\GenericContext;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Security\AccessControlServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(AuditLogService::class)]
#[CoversClass(AuditLogEntry::class)]
#[AllowMockObjectsWithoutExpectations]
final class AuditLogServiceTest extends TestCase
{
    private ?AuditLogService $subject = null;

    private ?MockObject $connectionPool = null;

    private ?MockObject $accessControlService = null;

    private ?MockObject $masterKeyProvider = null;

    private ?MockObject $extensionConfiguration = null;

    private ?MockObject $queryBuilder = null;

    private ?MockObject $connection = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $this->masterKeyProvider = $this->createMock(MasterKeyProviderInterface::class);
        $this->extensionConfiguration = $this->createMock(ExtensionConfigurationInterface::class);
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

        // Default: epoch 1 (HMAC mode)
        $this->extensionConfiguration
            ->method('getAuditHmacEpoch')
            ->willReturn(1);

        // Provide a stable 32-byte master key for tests
        $this->masterKeyProvider
            ->method('getMasterKey')
            ->willReturn(str_repeat("\x01", 32));

        self::assertNotNull($this->connectionPool);
        self::assertNotNull($this->accessControlService);
        $this->subject = new AuditLogService(
            $this->connectionPool,
            $this->accessControlService,
            $this->masterKeyProvider,
            $this->extensionConfiguration,
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

        $this->getSubject()->log('test_secret', 'create', true, null, 'Test secret stored');
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

        $this->getSubject()->log('api_key', 'read', true);
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

        $this->getSubject()->log('old_secret', 'delete', true, null, 'Cleanup');
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

        $this->getSubject()->log('rotated_secret', 'rotate', true, null, 'Annual rotation');
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

        $this->getSubject()->log('restricted_secret', 'access_denied', false, 'Permission denied');
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

        $this->getSubject()->log('context_test', 'create', true, null, 'Testing context');

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

        $this->getSubject()->log('chained_secret', 'create', true, null, 'Testing hash chain');
    }

    #[Test]
    public function queryReturnsAuditLogEntries(): void
    {
        $this->setupQueryMocks([
            [
                'uid' => 1,
                'pid' => 0,
                'secret_identifier' => 'test_secret',
                'action' => 'create',
                'success' => 1,
                'error_message' => '',
                'reason' => 'Test',
                'actor_uid' => 1,
                'actor_type' => 'backend',
                'actor_username' => 'admin',
                'actor_role' => 'backend',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'PHPUnit',
                'request_id' => 'abc123',
                'previous_hash' => '',
                'entry_hash' => 'hash123',
                'hash_before' => '',
                'hash_after' => 'newhash',
                'crdate' => time(),
                'context' => '{}',
            ],
        ]);

        $entries = $this->getSubject()->query();

        self::assertCount(1, $entries);
        self::assertInstanceOf(AuditLogEntry::class, $entries[0]);
        self::assertSame('test_secret', $entries[0]->secretIdentifier);
    }

    #[Test]
    public function queryWithFilterAppliesSecretIdentifierFilter(): void
    {
        $filter = new AuditLogFilter(secretIdentifier: 'specific_secret');

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->expects(self::once())
            ->method('eq')
            ->with('secret_identifier', self::anything())
            ->willReturn('secret_identifier = ?');

        $this->setupQueryMocksWithFilter($expressionBuilder, []);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->with('secret_identifier = ?')
            ->willReturnSelf();

        $this->getSubject()->query($filter);
    }

    #[Test]
    public function queryWithFilterAppliesActionFilter(): void
    {
        $filter = new AuditLogFilter(action: 'read');

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->expects(self::once())
            ->method('eq')
            ->with('action', self::anything())
            ->willReturn('action = ?');

        $this->setupQueryMocksWithFilter($expressionBuilder, []);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->with('action = ?')
            ->willReturnSelf();

        $this->getSubject()->query($filter);
    }

    #[Test]
    public function queryWithFilterAppliesSuccessFilter(): void
    {
        $filter = new AuditLogFilter(success: true);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->expects(self::once())
            ->method('eq')
            ->with('success', self::anything())
            ->willReturn('success = 1');

        $this->setupQueryMocksWithFilter($expressionBuilder, []);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->with('success = 1')
            ->willReturnSelf();

        $this->getSubject()->query($filter);
    }

    #[Test]
    public function queryWithFilterAppliesDateRangeFilters(): void
    {
        $since = new DateTimeImmutable('2024-01-01');
        $until = new DateTimeImmutable('2024-12-31');
        $filter = new AuditLogFilter(since: $since, until: $until);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->expects(self::once())
            ->method('gte')
            ->willReturn('crdate >= ?');
        $expressionBuilder->expects(self::once())
            ->method('lte')
            ->willReturn('crdate <= ?');

        $this->setupQueryMocksWithFilter($expressionBuilder, []);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->willReturnSelf();

        $this->getSubject()->query($filter);
    }

    #[Test]
    public function countReturnsNumberOfEntries(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(42);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder
            ->method('count')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('from')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);

        self::assertSame(42, $this->getSubject()->count());
    }

    #[Test]
    public function countWithFilterAppliesFilter(): void
    {
        $filter = new AuditLogFilter(actorUid: 5);

        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->expects(self::once())
            ->method('eq')
            ->with('actor_uid', self::anything())
            ->willReturn('actor_uid = 5');

        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(10);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder
            ->method('expr')
            ->willReturn($expressionBuilder);

        $this->queryBuilder
            ->method('count')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('from')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('andWhere')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('createNamedParameter')
            ->willReturn('5');

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);

        self::assertSame(10, $this->getSubject()->count($filter));
    }

    #[Test]
    public function exportReturnsAllEntries(): void
    {
        $this->setupQueryMocks([]);

        $entries = $this->getSubject()->export();

        self::assertIsArray($entries);
    }

    #[Test]
    public function getLatestHashReturnsNullWhenEmpty(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(false);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

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

        self::assertNull($this->getSubject()->getLatestHash());
    }

    #[Test]
    public function getLatestHashReturnsHashWhenExists(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('abc123hash');

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

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

        self::assertSame('abc123hash', $this->getSubject()->getLatestHash());
    }

    #[Test]
    public function verifyHashChainReturnsValidWhenEmpty(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

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
            ->method('executeQuery')
            ->willReturn($result);

        $verification = $this->getSubject()->verifyHashChain();

        self::assertTrue($verification->valid);
        self::assertEmpty($verification->errors);
    }

    #[Test]
    public function verifyHashChainWithRangeAppliesFilters(): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('gte')->willReturn('uid >= 10');
        $expressionBuilder->method('lte')->willReturn('uid <= 50');

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

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
            ->expects(self::exactly(2))
            ->method('andWhere')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('createNamedParameter')
            ->willReturn('?');

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);

        $verification = $this->getSubject()->verifyHashChain(10, 50);

        self::assertTrue($verification->valid);
    }

    #[Test]
    public function logRecordsHashBeforeAndAfter(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['hash_before'] === 'before_hash'
                    && $data['hash_after'] === 'after_hash'),
            );

        $this->getSubject()->log(
            'test_secret',
            'update',
            true,
            null,
            'Updated secret',
            'before_hash',
            'after_hash',
        );
    }

    #[Test]
    public function logRecordsContext(): void
    {
        $this->setupDatabaseMocks();

        $context = new GenericContext(['key' => 'value']);

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data) use ($context): bool {
                    $decodedContext = json_decode((string) $data['context'], true);

                    return $decodedContext === $context->toArray();
                }),
            );

        $this->getSubject()->log('test_secret', 'create', true, null, null, null, null, $context);
    }

    #[Test]
    public function logRecordsActorRole(): void
    {
        // Set up access control service to return groups
        $accessControlService = $this->createMock(AccessControlServiceInterface::class);
        $accessControlService->method('getCurrentActorUid')->willReturn(1);
        $accessControlService->method('getCurrentActorType')->willReturn('backend');
        $accessControlService->method('getCurrentActorUsername')->willReturn('admin');
        $accessControlService->method('getCurrentUserGroups')->willReturn([1, 2, 3]);

        $subject = new AuditLogService($this->connectionPool, $accessControlService, $this->masterKeyProvider, $this->extensionConfiguration);

        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => $data['actor_role'] === 'groups:1,2,3'),
            );

        $subject->log('test_secret', 'read', true);
    }

    #[Test]
    public function logUsesTransactionForAtomicWrite(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('beginTransaction');

        $this->connection
            ->expects(self::once())
            ->method('commit');

        $this->connection
            ->expects(self::never())
            ->method('rollBack');

        $this->getSubject()->log('test_secret', 'create', true);
    }

    #[Test]
    public function logRollsBackTransactionOnInsertFailure(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->method('insert')
            ->willThrowException(new RuntimeException('Insert failed'));

        $this->connection
            ->expects(self::once())
            ->method('rollBack');

        $this->connection
            ->expects(self::never())
            ->method('commit');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insert failed');

        $this->getSubject()->log('test_secret', 'create', true);
    }

    #[Test]
    public function logUpdatesEntryHashAfterInsert(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->method('lastInsertId')
            ->willReturn('42');

        $this->connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => isset($data['entry_hash']) && $data['entry_hash'] !== ''),
                ['uid' => 42],
            );

        $this->getSubject()->log('test_secret', 'create', true);
    }

    #[Test]
    public function hmacHashProducesDifferentOutputThanSha256(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->method('lastInsertId')
            ->willReturn('1');

        // Capture the entry_hash written during update
        $hmacHash = '';
        $this->connection
            ->method('update')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static function (array $data) use (&$hmacHash): bool {
                    $hmacHash = $data['entry_hash'] ?? '';

                    return true;
                }),
                self::anything(),
            );

        $this->getSubject()->log('test_secret', 'create', true);

        // Calculate the legacy SHA-256 hash for the same payload
        $payload = json_encode([
            'uid' => 1,
            'secret_identifier' => 'test_secret',
            'action' => 'create',
            'actor_uid' => 1,
            'crdate' => self::anything(),
            'previous_hash' => '',
        ], JSON_THROW_ON_ERROR);

        // The HMAC hash should not be empty and should be a valid hex string
        self::assertNotEmpty($hmacHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hmacHash);

        // The HMAC hash should differ from a plain SHA-256 of any similar payload
        $legacySha256 = hash('sha256', $payload);
        self::assertNotSame($legacySha256, $hmacHash);
    }

    #[Test]
    public function logSetsHmacKeyEpochInInsertData(): void
    {
        $this->setupDatabaseMocks();

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with(
                'tx_nrvault_audit_log',
                self::callback(static fn (array $data): bool => isset($data['hmac_key_epoch']) && $data['hmac_key_epoch'] === 1),
            );

        $this->getSubject()->log('test_secret', 'create', true);
    }

    #[Test]
    public function legacyEpoch0VerificationUsesPlainSha256(): void
    {
        // Create a subject with epoch 0 (legacy mode)
        $extensionConfig = $this->createMock(ExtensionConfigurationInterface::class);
        $extensionConfig->method('getAuditHmacEpoch')->willReturn(0);

        $subject = new AuditLogService(
            $this->connectionPool,
            $this->accessControlService,
            $this->masterKeyProvider,
            $extensionConfig,
        );

        // Build a row with epoch 0 and valid SHA-256 hash
        $previousHash = '';
        $payload = json_encode([
            'uid' => 1,
            'secret_identifier' => 'test',
            'action' => 'create',
            'actor_uid' => 1,
            'crdate' => 1704067200,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);
        $legacyHash = hash('sha256', $payload);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            [
                'uid' => 1,
                'secret_identifier' => 'test',
                'action' => 'create',
                'actor_uid' => 1,
                'crdate' => 1704067200,
                'previous_hash' => '',
                'entry_hash' => $legacyHash,
                'hmac_key_epoch' => 0,
            ],
        ]);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('executeQuery')->willReturn($result);

        $verification = $subject->verifyHashChain();

        self::assertTrue($verification->isValid());
    }

    #[Test]
    public function epochBoundaryGeneratesWarning(): void
    {
        // Build rows where epoch changes from 0 to 1
        $previousHash = '';
        $payload1 = json_encode([
            'uid' => 1,
            'secret_identifier' => 'test',
            'action' => 'create',
            'actor_uid' => 1,
            'crdate' => 1704067200,
            'previous_hash' => $previousHash,
        ], JSON_THROW_ON_ERROR);
        $hash1 = hash('sha256', $payload1);

        // Second entry uses HMAC (epoch 1)
        $masterKey = str_repeat("\x01", 32);
        $hmacKey = hash_hkdf('sha256', $masterKey, 32, 'nr-vault-audit-hmac-v1');
        $payload2 = json_encode([
            'uid' => 2,
            'secret_identifier' => 'test2',
            'action' => 'read',
            'actor_uid' => 1,
            'crdate' => 1704153600,
            'previous_hash' => $hash1,
        ], JSON_THROW_ON_ERROR);
        $hash2 = hash_hmac('sha256', $payload2, $hmacKey);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            [
                'uid' => 1,
                'secret_identifier' => 'test',
                'action' => 'create',
                'actor_uid' => 1,
                'crdate' => 1704067200,
                'previous_hash' => '',
                'entry_hash' => $hash1,
                'hmac_key_epoch' => 0,
            ],
            [
                'uid' => 2,
                'secret_identifier' => 'test2',
                'action' => 'read',
                'actor_uid' => 1,
                'crdate' => 1704153600,
                'previous_hash' => $hash1,
                'entry_hash' => $hash2,
                'hmac_key_epoch' => 1,
            ],
        ]);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('executeQuery')->willReturn($result);

        $verification = $this->getSubject()->verifyHashChain();

        self::assertTrue($verification->isValid());
        self::assertNotEmpty($verification->warnings);
        self::assertArrayHasKey(2, $verification->warnings);
        self::assertStringContainsString('epoch boundary', $verification->warnings[2]);
    }

    private function getSubject(): AuditLogService
    {
        self::assertNotNull($this->subject);

        return $this->subject;
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

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function setupQueryMocks(array $rows): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

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
            ->method('setFirstResult')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function setupQueryMocksWithFilter(ExpressionBuilder&MockObject $expressionBuilder, array $rows): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

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
            ->method('setFirstResult')
            ->willReturnSelf();

        $this->queryBuilder
            ->method('createNamedParameter')
            ->willReturn('?');

        $this->queryBuilder
            ->method('executeQuery')
            ->willReturn($result);
    }
}
