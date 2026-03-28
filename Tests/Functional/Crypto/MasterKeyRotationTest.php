<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Crypto;

use Netresearch\NrVault\Crypto\EncryptionService;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Crypto\FileMasterKeyProvider;
use Netresearch\NrVault\Domain\Repository\SecretRepositoryInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for master key rotation.
 *
 * Tests the full rotation workflow including storing secrets with one key,
 * rotating the master key, and verifying secrets remain accessible.
 */
#[CoversClass(EncryptionService::class)]
final class MasterKeyRotationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'nr_vault' => [
                'masterKeyProvider' => 'file',
                'enableCache' => false,
            ],
        ],
    ];

    private ?string $masterKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a temporary master key for testing at the default auto-key path
        $this->masterKeyPath = $this->instancePath . '/var/secrets/vault-master.key';
        $dir = \dirname($this->masterKeyPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0o600);

        // Also configure the source path via GLOBALS (read lazily by ExtensionConfiguration)
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault']['masterKeySource'] = $this->masterKeyPath;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault']['autoKeyPath'] = $this->masterKeyPath;

        // Create backend admin user
        $this->importCSVDataSet(__DIR__ . '/../Hook/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        // Clear the static key cache to prevent cross-test interference
        FileMasterKeyProvider::clearCachedKey();

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
    public function fullRotationWorkflowKeepsSecretsAccessible(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $encryptionService = $this->get(EncryptionServiceInterface::class);
        $secretRepository = $this->get(SecretRepositoryInterface::class);

        // Store multiple secrets with the original master key
        $secrets = [];
        for ($i = 0; $i < 3; ++$i) {
            $identifier = $this->generateUuidV7();
            $value = 'secret-value-' . $i;
            $vaultService->store($identifier, $value);
            $secrets[$identifier] = $value;
        }

        // Create a new key file
        $newKeyPath = $this->instancePath . '/master-new.key';
        $newKey = sodium_crypto_secretbox_keygen();
        file_put_contents($newKeyPath, $newKey);

        // Re-encrypt all DEKs with the new master key
        foreach (array_keys($secrets) as $identifier) {
            $secret = $secretRepository->findByIdentifier($identifier);
            self::assertNotNull($secret, 'Secret must exist: ' . $identifier);

            // Clear the static cache and re-read from file each iteration
            // because reEncryptDek zeroes the key parameters via sodium_memzero
            FileMasterKeyProvider::clearCachedKey();
            $oldKeyFromFile = $this->readKeyFromFile($this->masterKeyPath);
            $newKeyFromFile = $this->readKeyFromFile($newKeyPath);

            $reEncrypted = $encryptionService->reEncryptDek(
                $secret->getEncryptedDek(),
                $secret->getDekNonce(),
                $secret->getIdentifier(),
                $oldKeyFromFile,
                $newKeyFromFile,
            );

            $secret->setEncryptedDek($reEncrypted->encryptedDek);
            $secret->setDekNonce($reEncrypted->nonce);
            $secretRepository->save($secret);
        }

        // Switch to the new master key file
        copy($newKeyPath, $this->masterKeyPath);

        // Clear cached keys so the provider re-reads from disk
        FileMasterKeyProvider::clearCachedKey();
        $vaultService->clearCache();

        // Verify all secrets are still retrievable with the new key
        foreach ($secrets as $identifier => $expectedValue) {
            $retrieved = $vaultService->retrieve($identifier);
            self::assertSame($expectedValue, $retrieved, 'Secret must be retrievable after rotation: ' . $identifier);
        }

        // Cleanup
        foreach (array_keys($secrets) as $identifier) {
            $vaultService->delete($identifier, 'Test cleanup');
        }
        if (file_exists($newKeyPath)) {
            unlink($newKeyPath);
        }
    }

    #[Test]
    public function midRotationFailureKeepsSecretsAccessibleWithOriginalKey(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $encryptionService = $this->get(EncryptionServiceInterface::class);
        $secretRepository = $this->get(SecretRepositoryInterface::class);

        // Store secrets with original master key
        $identifier1 = $this->generateUuidV7();
        $identifier2 = $this->generateUuidV7();
        $vaultService->store($identifier1, 'secret-1');
        $vaultService->store($identifier2, 'secret-2');

        // Create a new key file
        $newKeyPath = $this->instancePath . '/master-new.key';
        $newKey = sodium_crypto_secretbox_keygen();
        file_put_contents($newKeyPath, $newKey);

        // Re-encrypt only the first secret (simulating mid-rotation failure)
        $secret1 = $secretRepository->findByIdentifier($identifier1);
        self::assertNotNull($secret1);

        FileMasterKeyProvider::clearCachedKey();
        $oldKeyFromFile = $this->readKeyFromFile($this->masterKeyPath);
        $newKeyFromFile = $this->readKeyFromFile($newKeyPath);

        $encryptionService->reEncryptDek(
            $secret1->getEncryptedDek(),
            $secret1->getDekNonce(),
            $secret1->getIdentifier(),
            $oldKeyFromFile,
            $newKeyFromFile,
        );

        // Do NOT save the re-encrypted DEK (simulating rollback after failure)
        // The original key file is still in place, so both secrets should be accessible

        // Clear caches and verify both secrets are still accessible with the original key
        FileMasterKeyProvider::clearCachedKey();
        $vaultService->clearCache();

        $retrieved1 = $vaultService->retrieve($identifier1);
        self::assertSame('secret-1', $retrieved1, 'First secret must remain accessible after failed rotation');

        $retrieved2 = $vaultService->retrieve($identifier2);
        self::assertSame('secret-2', $retrieved2, 'Second secret must remain accessible after failed rotation');

        // Cleanup
        $vaultService->delete($identifier1, 'Test cleanup');
        $vaultService->delete($identifier2, 'Test cleanup');
        if (file_exists($newKeyPath)) {
            unlink($newKeyPath);
        }
    }

    #[Test]
    public function keyRotationUpdatesAllSecretDeks(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $encryptionService = $this->get(EncryptionServiceInterface::class);
        $secretRepository = $this->get(SecretRepositoryInterface::class);

        // Store secrets
        $identifiers = [];
        for ($i = 0; $i < 3; ++$i) {
            $identifier = $this->generateUuidV7();
            $vaultService->store($identifier, 'value-' . $i);
            $identifiers[] = $identifier;
        }

        // Record original DEKs
        $originalDeks = [];
        foreach ($identifiers as $identifier) {
            $secret = $secretRepository->findByIdentifier($identifier);
            self::assertNotNull($secret);
            $originalDeks[$identifier] = $secret->getEncryptedDek();
        }

        // Create a new key file
        $newKeyPath = $this->instancePath . '/master-new.key';
        $newKey = sodium_crypto_secretbox_keygen();
        file_put_contents($newKeyPath, $newKey);

        // Re-encrypt all DEKs
        foreach ($identifiers as $identifier) {
            $secret = $secretRepository->findByIdentifier($identifier);
            self::assertNotNull($secret);

            FileMasterKeyProvider::clearCachedKey();
            $oldKeyFromFile = $this->readKeyFromFile($this->masterKeyPath);
            $newKeyFromFile = $this->readKeyFromFile($newKeyPath);

            $reEncrypted = $encryptionService->reEncryptDek(
                $secret->getEncryptedDek(),
                $secret->getDekNonce(),
                $secret->getIdentifier(),
                $oldKeyFromFile,
                $newKeyFromFile,
            );

            $secret->setEncryptedDek($reEncrypted->encryptedDek);
            $secret->setDekNonce($reEncrypted->nonce);
            $secretRepository->save($secret);
        }

        // Verify all DEKs have changed
        foreach ($identifiers as $identifier) {
            $secret = $secretRepository->findByIdentifier($identifier);
            self::assertNotNull($secret);
            self::assertNotSame(
                $originalDeks[$identifier],
                $secret->getEncryptedDek(),
                'Encrypted DEK must change after rotation for: ' . $identifier,
            );
        }

        // Switch to new key and verify retrieval works
        copy($newKeyPath, $this->masterKeyPath);
        FileMasterKeyProvider::clearCachedKey();
        $vaultService->clearCache();

        foreach ($identifiers as $i => $identifier) {
            $retrieved = $vaultService->retrieve($identifier);
            self::assertSame('value-' . $i, $retrieved, 'Secret must be retrievable after DEK rotation');
        }

        // Cleanup
        foreach ($identifiers as $identifier) {
            $vaultService->delete($identifier, 'Test cleanup');
        }
        if (file_exists($newKeyPath)) {
            unlink($newKeyPath);
        }
    }

    /**
     * Read a raw master key from a file.
     *
     * Mirrors the FileMasterKeyProvider logic for reading keys.
     */
    private function readKeyFromFile(string $path): string
    {
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'Key file must be readable: ' . $path);

        $trimmed = trim($raw);
        if (\strlen($trimmed) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $trimmed;
        }

        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && \strlen($decoded) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $decoded;
        }

        if (\strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $raw;
        }

        self::fail('Invalid key length in file: ' . $path);
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
