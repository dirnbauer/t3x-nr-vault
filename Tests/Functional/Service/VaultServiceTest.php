<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Service;

use Override;
use Netresearch\NrVault\Service\VaultService;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    private VaultServiceInterface $subject;

    private ?string $masterKeyPath = null;

    private bool $setupCompleted = false;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompleted = true;

        // Create a temporary master key for testing
        $this->masterKeyPath = $this->instancePath . '/master.key';
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0o600);

        // Configure extension to use file-based master key
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
            'masterKeySource' => $this->masterKeyPath,
            'autoKeyPath' => $this->masterKeyPath,
            'enableCache' => false,
        ];

        // Import backend user for access control
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        // Get properly wired service from container
        $this->subject = $this->get(VaultServiceInterface::class);
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clean up master key (if setUp completed successfully)
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            // Securely wipe the file contents before deletion
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        // Only call parent tearDown if setUp completed (instancePath is initialized)
        if ($this->setupCompleted) {
            parent::tearDown();
        }
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
    public function secretsAreProperlyEncryptedAndDecrypted(): void
    {
        $identifier = 'encryption_test';
        $secretValue = 'This is my secret value that should be encrypted';

        // Store the secret
        $this->subject->store($identifier, $secretValue);

        // Verify secret was stored
        self::assertTrue($this->subject->exists($identifier));

        // Verify we can retrieve the plaintext correctly (proves encryption/decryption works)
        $retrieved = $this->subject->retrieve($identifier);
        self::assertEquals($secretValue, $retrieved);

        // Verify metadata is available
        $metadata = $this->subject->getMetadata($identifier);
        self::assertEquals($identifier, $metadata['identifier']);
        self::assertEquals(1, $metadata['version']);
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
