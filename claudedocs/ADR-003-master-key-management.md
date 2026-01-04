# ADR-003: Master Key Management

## Status
Accepted

## Date
2026-01-03

## Context
Envelope encryption requires a master key. The approach must work in various environments (development, production, cloud) and support key rotation.

## Decision
Pluggable provider system with three built-in providers:

1. **typo3** (default): HKDF-SHA256 derivation from TYPO3's encryptionKey
2. **file**: Read from filesystem with strict permissions (0o400)
3. **env**: Read from environment variable (NR_VAULT_MASTER_KEY)

## Implementation

```php
// TYPO3 provider - derives key with domain separation
return hash_hkdf('sha256', $encryptionKey, 32, 'nr-vault-master-key');

// Factory auto-detection
$provider = $factory->getAvailableProvider(); // typo3 -> env -> file
```

## Configuration

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'typo3',  // or 'file', 'env'
    'masterKeySource' => 'NR_VAULT_MASTER_KEY',
];
```

## Key Rotation

```bash
vendor/bin/typo3 vault:rotate-master-key --old-key=/path/old.key --new-key=/path/new.key --confirm
```

## Consequences

**Positive:**
- Zero-config default with TYPO3 provider
- Deployment flexibility for different environments
- Atomic key rotation with transaction

**Negative:**
- TYPO3 provider: changing encryptionKey breaks vault

## References
- `Classes/Crypto/MasterKeyProviderInterface.php`
- `Classes/Crypto/Typo3MasterKeyProvider.php`
- `Classes/Command/VaultRotateMasterKeyCommand.php`
