<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Exception\MasterKeyException;

/**
 * TYPO3 encryption key-based master key provider.
 *
 * Derives the master key from TYPO3's encryption key using HKDF-SHA256.
 * This is the default provider as it requires no additional configuration.
 */
final class Typo3MasterKeyProvider implements MasterKeyProviderInterface
{
    private const KEY_LENGTH = 32; // 256 bits
    private const HKDF_INFO = 'nr-vault-master-key';

    public function getIdentifier(): string
    {
        return 'typo3';
    }

    public function isAvailable(): bool
    {
        return $this->getEncryptionKey() !== '';
    }

    public function getMasterKey(): string
    {
        $encryptionKey = $this->getEncryptionKey();

        if ($encryptionKey === '') {
            throw MasterKeyException::notFound('TYPO3 encryption key is not set');
        }

        // Derive master key using HKDF-SHA256 with nr-vault-specific context
        return hash_hkdf(
            'sha256',
            $encryptionKey,
            self::KEY_LENGTH,
            self::HKDF_INFO,
        );
    }

    public function storeMasterKey(string $key): void
    {
        // Cannot store - the key is derived from TYPO3's encryption key
        throw MasterKeyException::cannotStore(
            'TYPO3 provider derives the key from encryptionKey. To change it, rotate TYPO3\'s encryption key.',
        );
    }

    public function generateMasterKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }

    private function getEncryptionKey(): string
    {
        $typo3ConfVars = $GLOBALS['TYPO3_CONF_VARS'] ?? [];
        if (!\is_array($typo3ConfVars)) {
            return '';
        }

        $sysConfig = $typo3ConfVars['SYS'] ?? [];
        if (!\is_array($sysConfig)) {
            return '';
        }

        $encryptionKey = $sysConfig['encryptionKey'] ?? '';

        return \is_string($encryptionKey) ? $encryptionKey : '';
    }
}
