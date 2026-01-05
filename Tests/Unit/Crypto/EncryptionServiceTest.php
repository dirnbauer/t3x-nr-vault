<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EncryptionService;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Exception\EncryptionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncryptionService::class)]
final class EncryptionServiceTest extends TestCase
{
    private EncryptionService $subject;

    private MasterKeyProviderInterface&MockObject $masterKeyProvider;

    private ExtensionConfigurationInterface&MockObject $configuration;

    private string $testMasterKey;

    protected function setUp(): void
    {
        parent::setUp();

        // Generate a test master key (32 bytes)
        $this->testMasterKey = \random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

        $this->masterKeyProvider = $this->createMock(MasterKeyProviderInterface::class);
        $this->masterKeyProvider
            ->method('getMasterKey')
            ->willReturn($this->testMasterKey);

        $this->configuration = $this->createMock(ExtensionConfigurationInterface::class);
        $this->configuration
            ->method('preferXChaCha20')
            ->willReturn(false);

        $this->subject = new EncryptionService(
            $this->masterKeyProvider,
            $this->configuration,
        );
    }

    #[Test]
    public function encryptReturnsExpectedArrayStructure(): void
    {
        $plaintext = 'my-secret-api-key-12345';
        $identifier = 'test-secret';

        $result = $this->subject->encrypt($plaintext, $identifier);

        self::assertIsArray($result);
        self::assertArrayHasKey('encrypted_value', $result);
        self::assertArrayHasKey('encrypted_dek', $result);
        self::assertArrayHasKey('dek_nonce', $result);
        self::assertArrayHasKey('value_nonce', $result);
        self::assertArrayHasKey('value_checksum', $result);
    }

    #[Test]
    public function encryptedValueIsDifferentFromPlaintext(): void
    {
        $plaintext = 'sensitive-password-123';
        $identifier = 'password-secret';

        $result = $this->subject->encrypt($plaintext, $identifier);

        // Encrypted value should be base64 encoded and different from plaintext
        self::assertNotEquals($plaintext, $result['encrypted_value']);
        self::assertNotEmpty($result['encrypted_value']);

        // Verify it's valid base64
        $decoded = \base64_decode($result['encrypted_value'], true);
        self::assertNotFalse($decoded);
    }

    #[Test]
    public function encryptGeneratesUniqueNoncesPerCall(): void
    {
        $plaintext = 'test-secret';
        $identifier = 'nonce-test';

        $result1 = $this->subject->encrypt($plaintext, $identifier);
        $result2 = $this->subject->encrypt($plaintext, $identifier);

        // Each encryption should use unique nonces
        self::assertNotEquals($result1['dek_nonce'], $result2['dek_nonce']);
        self::assertNotEquals($result1['value_nonce'], $result2['value_nonce']);

        // Each encryption generates a new DEK
        self::assertNotEquals($result1['encrypted_dek'], $result2['encrypted_dek']);
    }

    #[Test]
    public function decryptReturnsOriginalPlaintext(): void
    {
        $plaintext = 'original-secret-value';
        $identifier = 'decrypt-test';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        $decrypted = $this->subject->decrypt(
            $encrypted['encrypted_value'],
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            $identifier,
        );

        self::assertEquals($plaintext, $decrypted);
    }

    #[Test]
    public function decryptWithWrongIdentifierThrowsException(): void
    {
        $plaintext = 'secret-with-aad';
        $identifier = 'correct-identifier';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        $this->expectException(EncryptionException::class);

        $this->subject->decrypt(
            $encrypted['encrypted_value'],
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            'wrong-identifier', // Different AAD
        );
    }

    #[Test]
    public function decryptWithTamperedDataThrowsException(): void
    {
        $plaintext = 'untampered-secret';
        $identifier = 'tamper-test';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        // Tamper with the encrypted value
        $tamperedValue = \base64_encode(
            \substr(\base64_decode($encrypted['encrypted_value'], true), 0, -1) . 'X',
        );

        $this->expectException(EncryptionException::class);

        $this->subject->decrypt(
            $tamperedValue,
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            $identifier,
        );
    }

    #[Test]
    public function decryptWithInvalidBase64ThrowsException(): void
    {
        $this->expectException(EncryptionException::class);

        $this->subject->decrypt(
            '!!!invalid-base64!!!',
            'also-invalid',
            'not-valid',
            'nope',
            'test',
        );
    }

    #[Test]
    public function generateDekReturnsCorrectKeyLength(): void
    {
        $dek = $this->subject->generateDek();

        // AES-256-GCM key should be 32 bytes
        self::assertEquals(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES, \strlen($dek));
    }

    #[Test]
    public function generateDekReturnsRandomBytes(): void
    {
        $dek1 = $this->subject->generateDek();
        $dek2 = $this->subject->generateDek();

        // Each call should generate a unique key
        self::assertNotEquals($dek1, $dek2);
    }

    #[Test]
    public function calculateChecksumReturnsSha256Hash(): void
    {
        $plaintext = 'checksum-test-value';

        $checksum = $this->subject->calculateChecksum($plaintext);

        // SHA-256 produces 64 hex characters
        self::assertEquals(64, \strlen($checksum));
        self::assertEquals(\hash('sha256', $plaintext), $checksum);
    }

    #[Test]
    public function checksumIsDeterministic(): void
    {
        $plaintext = 'deterministic-value';

        $checksum1 = $this->subject->calculateChecksum($plaintext);
        $checksum2 = $this->subject->calculateChecksum($plaintext);

        self::assertEquals($checksum1, $checksum2);
    }

    #[Test]
    public function reEncryptDekWorksWithNewMasterKey(): void
    {
        $plaintext = 'reencrypt-test';
        $identifier = 'reencrypt-secret';
        $oldMasterKey = $this->testMasterKey;
        $newMasterKey = \random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

        // First encrypt with old master key
        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        // Re-encrypt DEK with new master key
        $reEncrypted = $this->subject->reEncryptDek(
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $identifier,
            $oldMasterKey,
            $newMasterKey,
        );

        self::assertArrayHasKey('encrypted_dek', $reEncrypted);
        self::assertArrayHasKey('nonce', $reEncrypted);

        // The new encrypted DEK should be different
        self::assertNotEquals($encrypted['encrypted_dek'], $reEncrypted['encrypted_dek']);
    }

    #[Test]
    public function encryptHandlesEmptyString(): void
    {
        $plaintext = '';
        $identifier = 'empty-secret';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        self::assertNotEmpty($encrypted['encrypted_value']);

        $decrypted = $this->subject->decrypt(
            $encrypted['encrypted_value'],
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            $identifier,
        );

        self::assertEquals('', $decrypted);
    }

    #[Test]
    public function encryptHandlesLargePayload(): void
    {
        // 1MB of random data
        $plaintext = \random_bytes(1024 * 1024);
        $identifier = 'large-secret';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        $decrypted = $this->subject->decrypt(
            $encrypted['encrypted_value'],
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            $identifier,
        );

        self::assertEquals($plaintext, $decrypted);
    }

    #[Test]
    public function encryptHandlesUnicodeContent(): void
    {
        $plaintext = 'Secret with emoji: 🔐🔑 and unicode: äöü 中文';
        $identifier = 'unicode-secret';

        $encrypted = $this->subject->encrypt($plaintext, $identifier);

        $decrypted = $this->subject->decrypt(
            $encrypted['encrypted_value'],
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['value_nonce'],
            $identifier,
        );

        self::assertEquals($plaintext, $decrypted);
    }

    #[Test]
    public function encryptWithXChaCha20WhenConfigured(): void
    {
        // Configure to prefer XChaCha20
        $this->configuration = $this->createMock(ExtensionConfigurationInterface::class);
        $this->configuration
            ->method('preferXChaCha20')
            ->willReturn(true);

        $subject = new EncryptionService(
            $this->masterKeyProvider,
            $this->configuration,
        );

        $plaintext = 'xchacha-test';
        $identifier = 'xchacha-secret';

        $encrypted = $subject->encrypt($plaintext, $identifier);

        // Should still work with XChaCha20
        self::assertArrayHasKey('encrypted_value', $encrypted);
        self::assertArrayHasKey('encrypted_dek', $encrypted);
    }
}
