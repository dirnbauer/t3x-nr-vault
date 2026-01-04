# ADR-002: Envelope Encryption

## Status
Accepted

## Date
2026-01-03

## Context
The nr-vault extension needs to encrypt secrets at rest. The approach must protect secrets even if the database is compromised, allow efficient key rotation, and use modern cryptographic primitives.

## Decision
We use **envelope encryption** with AES-256-GCM (primary) or XChaCha20-Poly1305 (fallback):

1. Generate unique DEK (Data Encryption Key) per secret
2. Encrypt DEK with master key (KEK)
3. Encrypt secret value with DEK
4. Store: encryptedValue, encryptedDek, dekNonce, valueNonce, checksum

## Implementation

```php
// Encryption flow
$dek = random_bytes(32);
$encryptedDek = sodium_crypto_aead_aes256gcm_encrypt($dek, $aad, $dekNonce, $masterKey);
$encryptedValue = sodium_crypto_aead_aes256gcm_encrypt($secret, $aad, $valueNonce, $dek);
sodium_memzero($dek);
```

## Consequences

**Positive:**
- Fast key rotation (only re-encrypt DEKs)
- Unique key per secret limits blast radius
- Hardware acceleration with AES-NI

**Negative:**
- More storage (DEK + nonces per secret)
- Two-layer complexity

## References
- `Classes/Crypto/EncryptionService.php`
- [libsodium AEAD](https://doc.libsodium.org/secret-key_cryptography/aead)
