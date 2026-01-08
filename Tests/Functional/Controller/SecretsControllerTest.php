<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\SecretsController;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for SecretsController.
 *
 * Tests the backend module controller for secrets management.
 * These tests verify that the controller's dependencies are properly configured
 * and that the underlying services work correctly.
 */
#[CoversClass(SecretsController::class)]
final class SecretsControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private ?string $masterKeyPath = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

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

        // Create backend user
        $this->importCSVDataSet(__DIR__ . '/../Hook/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clean up master key
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function vaultServiceIsInjectable(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);

        self::assertInstanceOf(VaultServiceInterface::class, $vaultService);
    }

    #[Test]
    public function vaultServiceCanStoreAndRetrieveSecrets(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();
        $secretValue = 'test-secret-value';

        $vaultService->store($identifier, $secretValue);
        $retrieved = $vaultService->retrieve($identifier);

        self::assertSame($secretValue, $retrieved);

        // Cleanup
        $vaultService->delete($identifier, 'Test cleanup');
    }

    #[Test]
    public function vaultServiceCanListSecrets(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier1 = $this->generateUuidV7();
        $identifier2 = $this->generateUuidV7();

        $vaultService->store($identifier1, 'secret-1');
        $vaultService->store($identifier2, 'secret-2');

        $list = $vaultService->list();

        self::assertIsArray($list);
        self::assertGreaterThanOrEqual(2, \count($list));

        // Cleanup
        $vaultService->delete($identifier1, 'Test cleanup');
        $vaultService->delete($identifier2, 'Test cleanup');
    }

    #[Test]
    public function vaultServiceCanDeleteSecrets(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();

        $vaultService->store($identifier, 'to-be-deleted');
        self::assertTrue($vaultService->exists($identifier));

        $vaultService->delete($identifier, 'Test deletion');
        self::assertFalse($vaultService->exists($identifier));
    }

    #[Test]
    public function vaultServiceCanRotateSecrets(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();

        $vaultService->store($identifier, 'original-secret');
        $vaultService->rotate($identifier, 'rotated-secret', 'Test rotation');

        $retrieved = $vaultService->retrieve($identifier);
        self::assertSame('rotated-secret', $retrieved);

        // Cleanup
        $vaultService->delete($identifier, 'Test cleanup');
    }

    #[Test]
    public function vaultServiceReturnsMetadata(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();

        $vaultService->store($identifier, 'secret-with-metadata');

        $metadata = $vaultService->getMetadata($identifier);

        self::assertIsArray($metadata);
        self::assertArrayHasKey('identifier', $metadata);
        self::assertArrayHasKey('version', $metadata);
        self::assertSame($identifier, $metadata['identifier']);

        // Cleanup
        $vaultService->delete($identifier, 'Test cleanup');
    }

    /**
     * Generate a UUID v7 for testing.
     */
    private function generateUuidV7(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $timestampHex = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);
        $randomBytes = random_bytes(10);
        $randomHex = bin2hex($randomBytes);

        return \sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($timestampHex, 0, 8),
            substr($timestampHex, 8, 4),
            substr($randomHex, 0, 3),
            dechex(8 + random_int(0, 3)),
            substr($randomHex, 3, 3),
            substr($randomHex, 6, 12),
        );
    }
}
