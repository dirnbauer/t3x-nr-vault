<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use Netresearch\NrVault\Exception\EncryptionException;

/**
 * Envelope encryption service using AES-256-GCM or XChaCha20-Poly1305.
 */
final class EncryptionService implements EncryptionServiceInterface
{
    private const NONCE_LENGTH = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES; // 12 bytes

    public function __construct(
        private readonly MasterKeyProviderInterface $masterKeyProvider,
        private readonly ExtensionConfiguration $configuration,
    ) {
    }

    public function encrypt(string $plaintext, string $identifier): array
    {
        $masterKey = $this->masterKeyProvider->getMasterKey();

        try {
            // Generate unique DEK for this secret
            $dek = $this->generateDek();

            // Generate nonces
            $dekNonce = random_bytes(self::NONCE_LENGTH);
            $valueNonce = random_bytes(self::NONCE_LENGTH);

            // Encrypt the DEK with master key
            $encryptedDek = $this->encryptWithKey($dek, $masterKey, $dekNonce, $identifier);

            // Encrypt the value with DEK
            $encryptedValue = $this->encryptWithKey($plaintext, $dek, $valueNonce, $identifier);

            // Calculate checksum for change detection
            $checksum = $this->calculateChecksum($plaintext);

            // Securely wipe sensitive data
            sodium_memzero($dek);
            sodium_memzero($masterKey);
            sodium_memzero($plaintext);

            return [
                'encrypted_value' => base64_encode($encryptedValue),
                'encrypted_dek' => base64_encode($encryptedDek),
                'dek_nonce' => base64_encode($dekNonce),
                'value_nonce' => base64_encode($valueNonce),
                'value_checksum' => $checksum,
            ];
        } catch (\SodiumException $e) {
            throw EncryptionException::encryptionFailed($e->getMessage());
        }
    }

    public function decrypt(
        string $encryptedValue,
        string $encryptedDek,
        string $dekNonce,
        string $valueNonce,
        string $identifier
    ): string {
        $masterKey = $this->masterKeyProvider->getMasterKey();

        try {
            // Decode base64
            $encryptedValueBytes = base64_decode($encryptedValue, true);
            $encryptedDekBytes = base64_decode($encryptedDek, true);
            $dekNonceBytes = base64_decode($dekNonce, true);
            $valueNonceBytes = base64_decode($valueNonce, true);

            if ($encryptedValueBytes === false || $encryptedDekBytes === false ||
                $dekNonceBytes === false || $valueNonceBytes === false) {
                throw EncryptionException::decryptionFailed('Invalid base64 encoding');
            }

            // Decrypt the DEK with master key
            $dek = $this->decryptWithKey($encryptedDekBytes, $masterKey, $dekNonceBytes, $identifier);

            // Decrypt the value with DEK
            $plaintext = $this->decryptWithKey($encryptedValueBytes, $dek, $valueNonceBytes, $identifier);

            // Securely wipe sensitive data
            sodium_memzero($dek);
            sodium_memzero($masterKey);

            return $plaintext;
        } catch (\SodiumException $e) {
            throw EncryptionException::decryptionFailed($e->getMessage());
        }
    }

    public function generateDek(): string
    {
        if ($this->useAes256Gcm()) {
            return random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);
        }

        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    public function calculateChecksum(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function reEncryptDek(
        string $encryptedDek,
        string $dekNonce,
        string $identifier,
        string $oldMasterKey,
        string $newMasterKey
    ): array {
        try {
            // Decode
            $encryptedDekBytes = base64_decode($encryptedDek, true);
            $dekNonceBytes = base64_decode($dekNonce, true);

            if ($encryptedDekBytes === false || $dekNonceBytes === false) {
                throw EncryptionException::decryptionFailed('Invalid base64 encoding');
            }

            // Decrypt DEK with old master key
            $dek = $this->decryptWithKey($encryptedDekBytes, $oldMasterKey, $dekNonceBytes, $identifier);

            // Generate new nonce
            $newNonce = random_bytes(self::NONCE_LENGTH);

            // Encrypt DEK with new master key
            $newEncryptedDek = $this->encryptWithKey($dek, $newMasterKey, $newNonce, $identifier);

            // Securely wipe
            sodium_memzero($dek);

            return [
                'encrypted_dek' => base64_encode($newEncryptedDek),
                'nonce' => base64_encode($newNonce),
            ];
        } catch (\SodiumException $e) {
            throw EncryptionException::encryptionFailed($e->getMessage());
        }
    }

    /**
     * Encrypt data with a key using the configured algorithm.
     */
    private function encryptWithKey(string $plaintext, string $key, string $nonce, string $aad): string
    {
        if ($this->useAes256Gcm()) {
            return sodium_crypto_aead_aes256gcm_encrypt($plaintext, $aad, $nonce, $key);
        }

        // Pad nonce for XChaCha20 (needs 24 bytes)
        $xNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, "\0");

        return sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $xNonce, $key);
    }

    /**
     * Decrypt data with a key using the configured algorithm.
     */
    private function decryptWithKey(string $ciphertext, string $key, string $nonce, string $aad): string
    {
        if ($this->useAes256Gcm()) {
            $result = sodium_crypto_aead_aes256gcm_decrypt($ciphertext, $aad, $nonce, $key);
        } else {
            // Pad nonce for XChaCha20 (needs 24 bytes)
            $xNonce = str_pad($nonce, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, "\0");
            $result = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $xNonce, $key);
        }

        if ($result === false) {
            throw EncryptionException::decryptionFailed('Authentication failed - data may have been tampered with');
        }

        return $result;
    }

    /**
     * Determine if AES-256-GCM should be used.
     */
    private function useAes256Gcm(): bool
    {
        if ($this->configuration->preferXChaCha20()) {
            return false;
        }

        return sodium_crypto_aead_aes256gcm_is_available();
    }
}
