<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Domain\Repository;

use Doctrine\DBAL\Result;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

#[CoversClass(SecretRepository::class)]
#[AllowMockObjectsWithoutExpectations]
final class SecretRepositoryTest extends TestCase
{
    private SecretRepository $subject;

    private ConnectionPool&MockObject $connectionPool;

    private Connection&MockObject $connection;

    private QueryBuilder&MockObject $queryBuilder;

    private ExpressionBuilder&MockObject $expressionBuilder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->connection = $this->createMock(Connection::class);
        $this->queryBuilder = $this->createMock(QueryBuilder::class);
        $this->expressionBuilder = $this->createMock(ExpressionBuilder::class);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($this->connection);

        $this->connection
            ->method('createQueryBuilder')
            ->willReturn($this->queryBuilder);

        $this->queryBuilder
            ->method('expr')
            ->willReturn($this->expressionBuilder);

        $this->subject = new SecretRepository($this->connectionPool);
    }

    #[Test]
    public function findByIdentifierReturnsNullWhenNotFound(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $this->setupQueryBuilderForSelect($result);

        self::assertNull($this->subject->findByIdentifier('nonexistent'));
    }

    #[Test]
    public function findByIdentifierReturnsSecretWhenFound(): void
    {
        $secretRow = $this->createSecretRow('test-id');
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($secretRow);

        $this->setupQueryBuilderForSelect($result);

        // Mock MM table query for groups (returns empty)
        $groupResult = $this->createMock(Result::class);
        $groupResult->method('fetchAllAssociative')->willReturn([]);

        $secret = $this->subject->findByIdentifier('test-id');

        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame('test-id', $secret->getIdentifier());
    }

    #[Test]
    public function findByUidReturnsNullWhenNotFound(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $this->setupQueryBuilderForSelect($result);

        self::assertNull($this->subject->findByUid(999));
    }

    #[Test]
    public function findByUidReturnsSecretWhenFound(): void
    {
        $secretRow = $this->createSecretRow('uid-test', 42);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn($secretRow);

        $this->setupQueryBuilderForSelect($result);

        // Mock MM table query for groups (returns empty)
        $groupResult = $this->createMock(Result::class);
        $groupResult->method('fetchAllAssociative')->willReturn([]);

        $secret = $this->subject->findByUid(42);

        self::assertInstanceOf(Secret::class, $secret);
        self::assertSame(42, $secret->getUid());
    }

    #[Test]
    public function existsReturnsFalseWhenNotFound(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(0);

        $this->setupQueryBuilderForCount($result);

        self::assertFalse($this->subject->exists('nonexistent'));
    }

    #[Test]
    public function existsReturnsTrueWhenFound(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(1);

        $this->setupQueryBuilderForCount($result);

        self::assertTrue($this->subject->exists('test-id'));
    }

    #[Test]
    public function saveInsertsNewSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('new-secret');
        $secret->setEncryptedValue('encrypted');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('deknonce');
        $secret->setValueNonce('valuenonce');
        $secret->setEncryptionVersion(1);

        $this->connection
            ->expects(self::once())
            ->method('insert')
            ->with('tx_nrvault_secret', self::callback(static function (array $data): bool {
                return $data['identifier'] === 'new-secret'
                    && isset($data['crdate']);
            }));

        $this->connection
            ->method('lastInsertId')
            ->willReturn('1');

        // Mock MM table delete (no groups)
        $this->connection
            ->method('delete')
            ->with('tx_nrvault_secret_begroups_mm', self::anything());

        $this->subject->save($secret);

        self::assertSame(1, $secret->getUid());
    }

    #[Test]
    public function saveUpdatesExistingSecret(): void
    {
        $secret = new Secret();
        $secret->setUid(42);
        $secret->setIdentifier('existing-secret');
        $secret->setEncryptedValue('encrypted');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('deknonce');
        $secret->setValueNonce('valuenonce');
        $secret->setEncryptionVersion(1);

        $this->connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrvault_secret',
                self::anything(),
                ['uid' => 42],
            );

        // Mock MM table delete
        $this->connection
            ->method('delete')
            ->with('tx_nrvault_secret_begroups_mm', ['uid_local' => 42]);

        $this->subject->save($secret);
    }

    #[Test]
    public function deleteDoesNothingForNewSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('new-unsaved');

        $this->connection
            ->expects(self::never())
            ->method('update');

        $this->subject->delete($secret);
    }

    #[Test]
    public function deleteSoftDeletesExistingSecret(): void
    {
        $secret = new Secret();
        $secret->setUid(42);
        $secret->setIdentifier('to-delete');

        $this->connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'tx_nrvault_secret',
                self::callback(static fn (array $data): bool => $data['deleted'] === 1 && isset($data['tstamp'])),
                ['uid' => 42],
            );

        $this->subject->delete($secret);
    }

    #[Test]
    public function findIdentifiersReturnsEmptyArrayWhenNone(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $this->setupQueryBuilderForSelect($result);

        $identifiers = $this->subject->findIdentifiers();

        self::assertSame([], $identifiers);
    }

    #[Test]
    public function findIdentifiersReturnsIdentifiers(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(
                ['identifier' => 'secret-1'],
                ['identifier' => 'secret-2'],
                false,
            );

        $this->setupQueryBuilderForSelect($result);

        $identifiers = $this->subject->findIdentifiers();

        self::assertSame(['secret-1', 'secret-2'], $identifiers);
    }

    #[Test]
    public function findIdentifiersWithOwnerFilter(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $this->setupQueryBuilderForSelect($result);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->willReturnSelf();

        $this->subject->findIdentifiers(['owner' => 1]);
    }

    #[Test]
    public function findIdentifiersWithPrefixFilter(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);

        $this->setupQueryBuilderForSelect($result);

        $this->queryBuilder
            ->expects(self::atLeastOnce())
            ->method('andWhere')
            ->willReturnSelf();

        $this->expressionBuilder
            ->expects(self::atLeastOnce())
            ->method('like')
            ->willReturn('identifier LIKE ?');

        $this->subject->findIdentifiers(['prefix' => 'api-']);
    }

    #[Test]
    public function findByGroupsReturnsEmptyArrayWhenNoGroups(): void
    {
        $result = $this->subject->findByGroups([]);

        self::assertSame([], $result);
    }

    #[Test]
    public function findByGroupsReturnsEmptyArrayWhenNoSecrets(): void
    {
        $mmResult = $this->createMock(Result::class);
        $mmResult->method('fetchFirstColumn')->willReturn([]);

        $this->setupQueryBuilderForSelect($mmResult);

        $result = $this->subject->findByGroups([1, 2]);

        self::assertSame([], $result);
    }

    #[Test]
    public function findExpiredReturnsExpiredSecrets(): void
    {
        $expiredRow = $this->createSecretRow('expired', 1);
        $expiredRow['expires_at'] = time() - 3600;

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([$expiredRow]);

        $this->setupQueryBuilderForSelect($result);

        $secrets = $this->subject->findExpired();

        self::assertCount(1, $secrets);
        self::assertSame('expired', $secrets[0]->getIdentifier());
    }

    #[Test]
    public function findExpiringSoonReturnsSecretsExpiringSoon(): void
    {
        $soonRow = $this->createSecretRow('expiring-soon', 1);
        $soonRow['expires_at'] = time() + 3600;

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn([$soonRow]);

        $this->setupQueryBuilderForSelect($result);

        $secrets = $this->subject->findExpiringSoon(7);

        self::assertCount(1, $secrets);
    }

    #[Test]
    public function countAllReturnsCount(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn(5);

        $this->setupQueryBuilderForCount($result);

        self::assertSame(5, $this->subject->countAll());
    }

    private function setupQueryBuilderForSelect(Result $result): void
    {
        $this->queryBuilder->method('select')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('andWhere')->willReturnSelf();
        $this->queryBuilder->method('orderBy')->willReturnSelf();
        $this->queryBuilder->method('executeQuery')->willReturn($result);
        $this->queryBuilder->method('createNamedParameter')->willReturn('?');

        $this->expressionBuilder->method('eq')->willReturn('field = ?');
        $this->expressionBuilder->method('in')->willReturn('field IN (?)');
        $this->expressionBuilder->method('gt')->willReturn('field > ?');
        $this->expressionBuilder->method('lt')->willReturn('field < ?');
        $this->expressionBuilder->method('lte')->willReturn('field <= ?');
    }

    private function setupQueryBuilderForCount(Result $result): void
    {
        $this->queryBuilder->method('count')->willReturnSelf();
        $this->queryBuilder->method('from')->willReturnSelf();
        $this->queryBuilder->method('where')->willReturnSelf();
        $this->queryBuilder->method('executeQuery')->willReturn($result);
        $this->queryBuilder->method('createNamedParameter')->willReturn('?');

        $this->expressionBuilder->method('eq')->willReturn('field = ?');
    }

    /**
     * @return array<string, mixed>
     */
    private function createSecretRow(string $identifier, int $uid = 1): array
    {
        return [
            'uid' => $uid,
            'pid' => 0,
            'identifier' => $identifier,
            'encrypted_value' => base64_encode('encrypted'),
            'nonce' => base64_encode('nonce123456789012'),
            'encryption_version' => 1,
            'context' => '',
            'label' => 'Test Secret',
            'description' => 'Test description',
            'owner_uid' => 0,
            'scope_pid' => 0,
            'expires_at' => 0,
            'allowed_groups' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'deleted' => 0,
        ];
    }
}
