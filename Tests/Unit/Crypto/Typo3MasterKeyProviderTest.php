<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Crypto;

use Netresearch\NrVault\Crypto\Typo3MasterKeyProvider;
use Netresearch\NrVault\Exception\MasterKeyException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Typo3MasterKeyProvider::class)]
final class Typo3MasterKeyProviderTest extends TestCase
{
    private mixed $originalGlobals;

    protected function setUp(): void
    {
        $this->originalGlobals = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalGlobals !== null) {
            $GLOBALS['TYPO3_CONF_VARS'] = $this->originalGlobals;
        } else {
            unset($GLOBALS['TYPO3_CONF_VARS']);
        }
    }

    #[Test]
    public function getIdentifierReturnsTypo3(): void
    {
        $provider = new Typo3MasterKeyProvider();

        self::assertEquals('typo3', $provider->getIdentifier());
    }

    #[Test]
    public function isAvailableReturnsTrueWhenEncryptionKeySet(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 'a-very-long-encryption-key-for-typo3-that-is-at-least-32-chars',
            ],
        ];

        $provider = new Typo3MasterKeyProvider();

        self::assertTrue($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenEncryptionKeyEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => '',
            ],
        ];

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenEncryptionKeyNotSet(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [],
        ];

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenSysNotArray(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => 'not-an-array',
        ];

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function isAvailableReturnsFalseWhenGlobalsNotSet(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function getMasterKeyDerives32ByteKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 'test-encryption-key-for-unit-testing-purposes',
            ],
        ];

        $provider = new Typo3MasterKeyProvider();
        $key = $provider->getMasterKey();

        self::assertEquals(32, \strlen($key));
    }

    #[Test]
    public function getMasterKeyReturnsSameKeyForSameInput(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 'consistent-encryption-key-value',
            ],
        ];

        $provider = new Typo3MasterKeyProvider();
        $key1 = $provider->getMasterKey();
        $key2 = $provider->getMasterKey();

        self::assertEquals($key1, $key2);
    }

    #[Test]
    public function getMasterKeyReturnsDifferentKeyForDifferentInput(): void
    {
        $provider = new Typo3MasterKeyProvider();

        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 'encryption-key-one',
            ],
        ];
        $key1 = $provider->getMasterKey();

        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 'encryption-key-two',
            ],
        ];
        $key2 = $provider->getMasterKey();

        self::assertNotEquals($key1, $key2);
    }

    #[Test]
    public function getMasterKeyThrowsWhenEncryptionKeyEmpty(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => '',
            ],
        ];

        $provider = new Typo3MasterKeyProvider();

        $this->expectException(MasterKeyException::class);
        $this->expectExceptionMessage('TYPO3 encryption key is not set');

        $provider->getMasterKey();
    }

    #[Test]
    public function storeMasterKeyThrowsException(): void
    {
        $provider = new Typo3MasterKeyProvider();

        $this->expectException(MasterKeyException::class);
        $this->expectExceptionMessage('TYPO3 provider derives the key');

        $provider->storeMasterKey(random_bytes(32));
    }

    #[Test]
    public function generateMasterKeyReturns32Bytes(): void
    {
        $provider = new Typo3MasterKeyProvider();

        $key = $provider->generateMasterKey();

        self::assertEquals(32, \strlen($key));
    }

    #[Test]
    public function handlesNonStringEncryptionKey(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [
            'SYS' => [
                'encryptionKey' => 12345,
            ],
        ];

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }

    #[Test]
    public function handlesNonArrayTypo3ConfVars(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = 'not-an-array';

        $provider = new Typo3MasterKeyProvider();

        self::assertFalse($provider->isAvailable());
    }
}
