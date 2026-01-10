<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Domain\Repository;

use Netresearch\NrVault\Domain\Dto\SecretFilters;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Domain\Repository\SecretRepositoryInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for SecretRepository with real database operations.
 */
#[CoversClass(SecretRepository::class)]
#[CoversClass(Secret::class)]
#[CoversClass(SecretFilters::class)]
final class SecretRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private SecretRepositoryInterface $subject;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Functional/Service/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->subject = $this->get(SecretRepositoryInterface::class);
    }

    #[Test]
    public function saveCreatesNewSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test_secret_1');
        $secret->setEncryptedValue('encrypted_value_data');
        $secret->setEncryptedDek('encrypted_dek_data');
        $secret->setDekNonce('dek_nonce_data');
        $secret->setValueNonce('value_nonce_data');
        $secret->setVersion(1);
        $secret->setCruserId(1);

        $this->subject->save($secret);

        self::assertGreaterThan(0, $secret->getUid());

        // Verify we can retrieve it
        $retrieved = $this->subject->findByIdentifier('test_secret_1');
        self::assertNotNull($retrieved);
        self::assertSame('test_secret_1', $retrieved->getIdentifier());
    }

    #[Test]
    public function findByIdentifierReturnsNullForNonExistent(): void
    {
        $result = $this->subject->findByIdentifier('non_existent_secret');

        self::assertNull($result);
    }

    #[Test]
    public function findByUidReturnsCorrectSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('uid_test_secret');
        $secret->setEncryptedValue('encrypted');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('nonce1');
        $secret->setValueNonce('nonce2');
        $secret->setVersion(1);
        $secret->setCruserId(1);

        $this->subject->save($secret);
        $uid = $secret->getUid();

        $retrieved = $this->subject->findByUid($uid);

        self::assertNotNull($retrieved);
        self::assertSame('uid_test_secret', $retrieved->getIdentifier());
    }

    #[Test]
    public function findByUidReturnsNullForNonExistent(): void
    {
        $result = $this->subject->findByUid(999999);

        self::assertNull($result);
    }

    #[Test]
    public function existsReturnsTrueForExistingSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('exists_test');
        $secret->setEncryptedValue('value');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('n1');
        $secret->setValueNonce('n2');
        $secret->setVersion(1);
        $secret->setCruserId(1);

        $this->subject->save($secret);

        self::assertTrue($this->subject->exists('exists_test'));
    }

    #[Test]
    public function existsReturnsFalseForNonExistent(): void
    {
        self::assertFalse($this->subject->exists('does_not_exist'));
    }

    #[Test]
    public function deleteRemovesSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('to_delete');
        $secret->setEncryptedValue('value');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('n1');
        $secret->setValueNonce('n2');
        $secret->setVersion(1);
        $secret->setCruserId(1);

        $this->subject->save($secret);
        self::assertTrue($this->subject->exists('to_delete'));

        $this->subject->delete($secret);

        self::assertFalse($this->subject->exists('to_delete'));
    }

    #[Test]
    public function saveUpdatesExistingSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('update_test');
        $secret->setEncryptedValue('original_value');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('n1');
        $secret->setValueNonce('n2');
        $secret->setVersion(1);
        $secret->setCruserId(1);

        $this->subject->save($secret);
        $originalUid = $secret->getUid();

        // Update the secret
        $secret->setEncryptedValue('updated_value');
        $secret->setVersion(2);
        $this->subject->save($secret);

        // Verify the update
        $retrieved = $this->subject->findByIdentifier('update_test');
        self::assertNotNull($retrieved);
        self::assertSame($originalUid, $retrieved->getUid());
        self::assertSame('updated_value', $retrieved->getEncryptedValue());
        self::assertSame(2, $retrieved->getVersion());
    }

    #[Test]
    public function findIdentifiersReturnsAllIdentifiers(): void
    {
        // Create multiple secrets
        for ($i = 1; $i <= 3; $i++) {
            $secret = new Secret();
            $secret->setIdentifier("list_test_{$i}");
            $secret->setEncryptedValue("value_{$i}");
            $secret->setEncryptedDek('dek');
            $secret->setDekNonce('n1');
            $secret->setValueNonce('n2');
            $secret->setVersion(1);
            $secret->setCruserId(1);
            $this->subject->save($secret);
        }

        $identifiers = $this->subject->findIdentifiers();

        self::assertContains('list_test_1', $identifiers);
        self::assertContains('list_test_2', $identifiers);
        self::assertContains('list_test_3', $identifiers);
    }

    #[Test]
    public function findIdentifiersWithFiltersByContext(): void
    {
        // Create secrets with different contexts
        $secret1 = new Secret();
        $secret1->setIdentifier('context_api');
        $secret1->setEncryptedValue('v1');
        $secret1->setEncryptedDek('dek');
        $secret1->setDekNonce('n1');
        $secret1->setValueNonce('n2');
        $secret1->setContext('api');
        $secret1->setVersion(1);
        $secret1->setCruserId(1);
        $this->subject->save($secret1);

        $secret2 = new Secret();
        $secret2->setIdentifier('context_db');
        $secret2->setEncryptedValue('v2');
        $secret2->setEncryptedDek('dek');
        $secret2->setDekNonce('n1');
        $secret2->setValueNonce('n2');
        $secret2->setContext('database');
        $secret2->setVersion(1);
        $secret2->setCruserId(1);
        $this->subject->save($secret2);

        $apiSecrets = $this->subject->findIdentifiers(new SecretFilters(context: 'api'));

        self::assertContains('context_api', $apiSecrets);
        self::assertNotContains('context_db', $apiSecrets);
    }

    #[Test]
    public function secretModelSettersAndGettersWork(): void
    {
        $secret = new Secret();
        $now = time();

        $secret->setIdentifier('model_test');
        $secret->setEncryptedValue('encrypted');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('dek_nonce');
        $secret->setValueNonce('value_nonce');
        $secret->setVersion(5);
        $secret->setContext('testing');
        $secret->setDescription('Test description');
        $secret->setCruserId(42);
        $secret->setOwnerUid(43);
        $secret->setLastReadAt($now);
        $secret->setLastRotatedAt($now);
        $secret->setExpiresAt($now);
        $secret->setMetadata(['key' => 'value']);

        self::assertSame('model_test', $secret->getIdentifier());
        self::assertSame('encrypted', $secret->getEncryptedValue());
        self::assertSame('dek', $secret->getEncryptedDek());
        self::assertSame('dek_nonce', $secret->getDekNonce());
        self::assertSame('value_nonce', $secret->getValueNonce());
        self::assertSame(5, $secret->getVersion());
        self::assertSame('testing', $secret->getContext());
        self::assertSame('Test description', $secret->getDescription());
        self::assertSame(42, $secret->getCruserId());
        self::assertSame(43, $secret->getOwnerUid());
        self::assertSame($now, $secret->getLastReadAt());
        self::assertSame($now, $secret->getLastRotatedAt());
        self::assertSame($now, $secret->getExpiresAt());
        self::assertSame(['key' => 'value'], $secret->getMetadata());
    }

    #[Test]
    public function secretIsExpiredReturnsCorrectly(): void
    {
        $secret = new Secret();

        // No expiry set (expiresAt = 0)
        self::assertFalse($secret->isExpired());

        // Future expiry
        $secret->setExpiresAt(time() + 3600);
        self::assertFalse($secret->isExpired());

        // Past expiry
        $secret->setExpiresAt(time() - 3600);
        self::assertTrue($secret->isExpired());
    }

    #[Test]
    public function secretIncrementVersionWorks(): void
    {
        $secret = new Secret();
        $secret->setVersion(1);

        $secret->incrementVersion();
        self::assertSame(2, $secret->getVersion());

        $secret->incrementVersion();
        self::assertSame(3, $secret->getVersion());
    }

    #[Test]
    public function secretValueChecksumCanBeSetAndRetrieved(): void
    {
        $secret = new Secret();
        $checksum = hash('sha256', 'test_value');
        $secret->setValueChecksum($checksum);

        self::assertSame($checksum, $secret->getValueChecksum());
        self::assertSame(64, \strlen($secret->getValueChecksum())); // SHA-256 hex = 64 chars
    }

    #[Test]
    public function secretFromDatabaseRowPopulatesAllFields(): void
    {
        $row = [
            'uid' => 123,
            'scope_pid' => 0,
            'identifier' => 'test_identifier',
            'description' => 'Test description',
            'encrypted_value' => 'encrypted_data',
            'encrypted_dek' => 'dek_data',
            'dek_nonce' => 'dek_nonce',
            'value_nonce' => 'value_nonce',
            'encryption_version' => 2,
            'value_checksum' => 'abc123',
            'owner_uid' => 5,
            'context' => 'api',
            'frontend_accessible' => 1,
            'version' => 3,
            'expires_at' => 1700000000,
            'last_rotated_at' => 1699999999,
            'adapter' => 'hashicorp',
            'external_reference' => 'vault/path',
            'tstamp' => 1699999998,
            'crdate' => 1699999997,
            'cruser_id' => 1,
            'deleted' => 0,
            'hidden' => 0,
            'read_count' => 10,
            'last_read_at' => 1699999996,
            'metadata' => '{"key":"value"}',
            'allowed_groups' => '1,2,3',
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertSame(123, $secret->getUid());
        self::assertSame('test_identifier', $secret->getIdentifier());
        self::assertSame('Test description', $secret->getDescription());
        self::assertSame('encrypted_data', $secret->getEncryptedValue());
        self::assertSame(5, $secret->getOwnerUid());
        self::assertSame('api', $secret->getContext());
        self::assertTrue($secret->isFrontendAccessible());
        self::assertSame(3, $secret->getVersion());
        self::assertSame('hashicorp', $secret->getAdapter());
        self::assertSame(10, $secret->getReadCount());
        self::assertSame(['key' => 'value'], $secret->getMetadata());
        self::assertSame([1, 2, 3], $secret->getAllowedGroups());
    }

    #[Test]
    public function secretToDatabaseRowFormatsCorrectly(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('db_row_test');
        $secret->setDescription('Test');
        $secret->setEncryptedValue('encrypted');
        $secret->setEncryptedDek('dek');
        $secret->setDekNonce('nonce1');
        $secret->setValueNonce('nonce2');
        $secret->setOwnerUid(1);
        $secret->setContext('api');
        $secret->setVersion(2);
        $secret->setAllowedGroups([1, 2]);
        $secret->setMetadata(['foo' => 'bar']);

        $row = $secret->toDatabaseRow();

        self::assertSame('db_row_test', $row['identifier']);
        self::assertSame('Test', $row['description']);
        self::assertSame('encrypted', $row['encrypted_value']);
        self::assertSame(1, $row['owner_uid']);
        self::assertSame('api', $row['context']);
        self::assertSame(2, $row['version']);
        self::assertSame('1,2', $row['allowed_groups']);
        self::assertSame('{"foo":"bar"}', $row['metadata']);
    }
}
