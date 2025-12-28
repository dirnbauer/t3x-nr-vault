<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Service;

use Netresearch\NrVault\Adapter\LocalEncryptionAdapter;
use Netresearch\NrVault\Audit\AuditLogService;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EncryptionService;
use Netresearch\NrVault\Crypto\MasterKeyProvider\FileMasterKeyProvider;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Security\AccessControlService;
use Netresearch\NrVault\Service\VaultService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for VaultService with real database operations.
 */
#[CoversClass(VaultService::class)]
final class VaultServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private VaultService $subject;
    private string $masterKeyPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary master key for testing
        $this->masterKeyPath = $this->instancePath . '/master.key';
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0600);

        // Set up services
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $masterKeyProvider = new FileMasterKeyProvider($this->masterKeyPath);
        $encryptionService = new EncryptionService($masterKeyProvider);
        $adapter = new LocalEncryptionAdapter($connectionPool);
        $accessControlService = $this->createMock(AccessControlService::class);
        $auditLogService = $this->createMock(AuditLogService::class);
        $configuration = $this->createMock(ExtensionConfigurationInterface::class);

        // Configure mocks
        $accessControlService->method('getCurrentActorUid')->willReturn(1);
        $accessControlService->method('getCurrentActorType')->willReturn('backend');
        $accessControlService->method('getCurrentActorUsername')->willReturn('admin');
        $accessControlService->method('canRead')->willReturn(true);
        $accessControlService->method('canWrite')->willReturn(true);
        $accessControlService->method('canDelete')->willReturn(true);
        $configuration->method('isCacheEnabled')->willReturn(false);

        $this->subject = new VaultService(
            $adapter,
            $encryptionService,
            $accessControlService,
            $auditLogService,
            $configuration,
        );
    }

    protected function tearDown(): void
    {
        // Clean up master key
        if (file_exists($this->masterKeyPath)) {
            // Securely wipe the file contents before deletion
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function storeAndRetrieveSecretWithDatabase(): void
    {
        $identifier = 'test_api_key';
        $secretValue = 'my-super-secret-api-key-12345';

        // Store the secret
        $this->subject->store($identifier, $secretValue);

        // Verify it exists
        self::assertTrue($this->subject->exists($identifier));

        // Retrieve and verify value
        $retrieved = $this->subject->retrieve($identifier);
        self::assertEquals($secretValue, $retrieved);
    }

    #[Test]
    public function storeUpdatesExistingSecret(): void
    {
        $identifier = 'updatable_secret';
        $originalValue = 'original-value';
        $updatedValue = 'updated-value';

        // Store original
        $this->subject->store($identifier, $originalValue);
        self::assertEquals($originalValue, $this->subject->retrieve($identifier));

        // Update with new value
        $this->subject->store($identifier, $updatedValue);
        self::assertEquals($updatedValue, $this->subject->retrieve($identifier));
    }

    #[Test]
    public function deleteRemovesSecretFromDatabase(): void
    {
        $identifier = 'to_be_deleted';
        $secretValue = 'delete-me';

        // Store and verify
        $this->subject->store($identifier, $secretValue);
        self::assertTrue($this->subject->exists($identifier));

        // Delete
        $this->subject->delete($identifier, 'Test cleanup');

        // Verify deleted
        self::assertFalse($this->subject->exists($identifier));
    }

    #[Test]
    public function rotateUpdatesSecretVersionAndValue(): void
    {
        $identifier = 'rotating_secret';
        $originalValue = 'original-rotating-value';
        $rotatedValue = 'rotated-new-value';

        // Store original
        $this->subject->store($identifier, $originalValue);
        $metadataBefore = $this->subject->getMetadata($identifier);
        self::assertEquals(1, $metadataBefore['version']);

        // Rotate
        $this->subject->rotate($identifier, $rotatedValue, 'Scheduled rotation');

        // Verify new value and incremented version
        $retrieved = $this->subject->retrieve($identifier);
        self::assertEquals($rotatedValue, $retrieved);

        $metadataAfter = $this->subject->getMetadata($identifier);
        self::assertEquals(2, $metadataAfter['version']);
        self::assertNotNull($metadataAfter['lastRotatedAt']);
    }

    #[Test]
    public function listReturnsStoredSecrets(): void
    {
        // Store multiple secrets
        $this->subject->store('list_test_1', 'value1');
        $this->subject->store('list_test_2', 'value2');
        $this->subject->store('list_test_3', 'value3');

        // List all
        $secrets = $this->subject->list();

        // Verify at least our test secrets are returned
        $identifiers = array_column($secrets, 'identifier');
        self::assertContains('list_test_1', $identifiers);
        self::assertContains('list_test_2', $identifiers);
        self::assertContains('list_test_3', $identifiers);
    }

    #[Test]
    public function getMetadataReturnsCorrectInformation(): void
    {
        $identifier = 'metadata_test';
        $secretValue = 'metadata-test-value';

        $this->subject->store($identifier, $secretValue, [
            'description' => 'Test secret for metadata',
            'context' => 'testing',
            'metadata' => ['environment' => 'test'],
        ]);

        $metadata = $this->subject->getMetadata($identifier);

        self::assertEquals($identifier, $metadata['identifier']);
        self::assertEquals('Test secret for metadata', $metadata['description']);
        self::assertEquals('testing', $metadata['context']);
        self::assertEquals(['environment' => 'test'], $metadata['metadata']);
        self::assertEquals(1, $metadata['version']);
    }

    #[Test]
    public function secretsAreProperlyEncryptedInDatabase(): void
    {
        $identifier = 'encryption_test';
        $secretValue = 'This is my secret value that should be encrypted';

        $this->subject->store($identifier, $secretValue);

        // Query database directly to verify encryption
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable('tx_nrvault_secrets');
        $row = $connection->select(
            ['encrypted_value', 'encrypted_dek', 'dek_nonce', 'value_nonce'],
            'tx_nrvault_secrets',
            ['identifier' => $identifier]
        )->fetchAssociative();

        // Verify that stored values are not plaintext
        self::assertNotEmpty($row['encrypted_value']);
        self::assertNotEmpty($row['encrypted_dek']);
        self::assertNotEquals($secretValue, $row['encrypted_value']);

        // Verify we can still retrieve the plaintext
        $retrieved = $this->subject->retrieve($identifier);
        self::assertEquals($secretValue, $retrieved);
    }

    #[Test]
    public function httpClientIsAccessible(): void
    {
        $httpClient = $this->subject->http();

        self::assertNotNull($httpClient);
    }

    #[Test]
    public function clearCacheDoesNotAffectStoredSecrets(): void
    {
        $identifier = 'cache_test';
        $secretValue = 'cache-test-value';

        $this->subject->store($identifier, $secretValue);
        $this->subject->clearCache();

        // Should still be retrievable from database
        $retrieved = $this->subject->retrieve($identifier);
        self::assertEquals($secretValue, $retrieved);
    }
}
