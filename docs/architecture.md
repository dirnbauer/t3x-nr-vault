# nr-vault Architecture

## Overview

nr-vault is designed as a layered architecture with pluggable storage adapters, following the adapter pattern to support both self-contained operation and external vault services.

## Design Principles

1. **Secure by Default**: All secrets encrypted at rest using AES-256-GCM
2. **No External Dependencies Required**: Works out-of-the-box with local encryption
3. **Pluggable Adapters**: Support for external vaults (HashiCorp, AWS, etc.)
4. **Audit Everything**: Every secret access is logged
5. **Access Control**: Secrets scoped to backend user groups
6. **TYPO3 Native**: Integrates with TCA, Extbase, and TYPO3 patterns

## Component Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                           TYPO3 Backend / CLI                            │
├─────────────────────────────────────────────────────────────────────────┤
│                                                                          │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────────────────┐ │
│  │ TCA Field      │  │ Backend Module │  │ CLI Commands               │ │
│  │ (vaultSecret)  │  │ (Management UI)│  │ (vault:store, vault:rotate)│ │
│  └───────┬────────┘  └───────┬────────┘  └─────────────┬──────────────┘ │
│          │                   │                         │                 │
│          └───────────────────┼─────────────────────────┘                 │
│                              ▼                                           │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                      VaultService (Facade)                         │  │
│  │  - store(identifier, secret, options)                              │  │
│  │  - retrieve(identifier): ?string                                   │  │
│  │  - rotate(identifier, newSecret)                                   │  │
│  │  - delete(identifier)                                              │  │
│  │  - list(): array                                                   │  │
│  │  - getMetadata(identifier): array                                  │  │
│  └───────────────────────────────┬───────────────────────────────────┘  │
│                                  │                                       │
│          ┌───────────────────────┼───────────────────────┐              │
│          ▼                       ▼                       ▼              │
│  ┌───────────────┐  ┌────────────────────┐  ┌────────────────────────┐ │
│  │ AccessControl │  │ AuditLogService    │  │ VaultAdapterInterface  │ │
│  │ Service       │  │                    │  │                        │ │
│  └───────────────┘  └────────────────────┘  └───────────┬────────────┘ │
│                                                          │              │
└──────────────────────────────────────────────────────────┼──────────────┘
                                                           │
       ┌───────────────────────┬───────────────────────────┼───────────────┐
       ▼                       ▼                           ▼               ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ LocalEncryption │  │ HashiCorpVault  │  │ AWSSecrets      │  │ AzureKeyVault   │
│ Adapter         │  │ Adapter         │  │ Adapter         │  │ Adapter         │
│ (DEFAULT)       │  │                 │  │                 │  │                 │
└────────┬────────┘  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘
         │                    │                    │                    │
         ▼                    ▼                    ▼                    ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ TYPO3 Database  │  │ Vault Server    │  │ AWS API         │  │ Azure API       │
│ (encrypted)     │  │                 │  │                 │  │                 │
└─────────────────┘  └─────────────────┘  └─────────────────┘  └─────────────────┘
```

## Envelope Encryption Model

The local encryption adapter uses **envelope encryption**, the same pattern used by AWS KMS and Google Cloud KMS:

```
┌────────────────────────────────────────────────────────────────────┐
│                         MASTER KEY                                  │
│  - 256-bit random key                                               │
│  - Stored OUTSIDE database (file or env var)                        │
│  - Never touches database or application logs                       │
│  - Used only to encrypt/decrypt DEKs                                │
└────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ encrypts/decrypts
                                    ▼
┌────────────────────────────────────────────────────────────────────┐
│                    DATA ENCRYPTION KEY (DEK)                        │
│  - Unique 256-bit key per secret                                    │
│  - Generated when secret is created                                 │
│  - Stored encrypted in database (encrypted_dek column)              │
│  - Rotated when master key rotates                                  │
└────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ encrypts/decrypts
                                    ▼
┌────────────────────────────────────────────────────────────────────┐
│                         SECRET VALUE                                │
│  - The actual API key, password, or credential                      │
│  - Encrypted with DEK using AES-256-GCM                             │
│  - Stored as encrypted blob in database                             │
│  - Includes authentication tag (GCM provides AEAD)                  │
└────────────────────────────────────────────────────────────────────┘
```

### Why Envelope Encryption?

1. **Fast Master Key Rotation**: Only need to re-encrypt DEKs, not all secrets
2. **Unique Encryption per Secret**: Compromise of one DEK doesn't expose others
3. **Reduced Master Key Exposure**: Master key only used for DEK operations
4. **Industry Standard**: Same pattern used by AWS, Google, Azure

## Master Key Management

The master key is the root of trust. nr-vault supports multiple storage options:

### Option 1: File-Based (Recommended for Production)

```
/var/secrets/typo3/nr-vault-master.key
```

- File outside webroot with restrictive permissions (0400)
- Owned by web server user
- Backed up separately from database

### Option 2: Environment Variable

```bash
export NR_VAULT_MASTER_KEY="base64-encoded-32-byte-key"
```

- Good for containerized deployments
- Injected at runtime
- Never in git or configuration files

### Option 3: Derived Key (Shared Hosting Fallback)

```php
$masterKey = hash('sha256',
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
    . file_get_contents('/path/to/salt.key')
    . 'nr-vault-v1',
    true
);
```

- Uses combination of TYPO3 encryptionKey + file salt
- Less secure but works in restricted environments
- Compromise requires multiple components

## Access Control

Secrets can be scoped to specific backend user groups:

```php
$vault->store('my_api_key', $secret, [
    'owner' => $beUserUid,
    'groups' => [1, 2],  // Only these BE groups can access
]);
```

Access is checked on every `retrieve()` call:
1. User must be logged in (backend context)
2. User must be owner OR member of allowed groups
3. System maintainers bypass group restrictions
4. CLI access requires explicit permission flag

## Audit Logging

Every secret operation is logged:

```sql
tx_nrvault_audit_log
├── secret_identifier   -- Which secret
├── action              -- create, read, update, delete, rotate
├── actor_uid           -- Who performed action
├── actor_type          -- backend, cli, api, scheduler
├── ip_address          -- Request IP
├── success             -- Whether operation succeeded
├── error_message       -- If failed, why
└── timestamp           -- When
```

Audit logs are:
- Never deleted automatically
- Queryable via API and backend module
- Exportable for compliance

## Secret Lifecycle

```
┌─────────┐    store()     ┌─────────┐   retrieve()   ┌─────────┐
│ Created │ ─────────────► │ Active  │ ◄────────────► │ In Use  │
└─────────┘                └─────────┘                └─────────┘
                                │                          │
                                │ rotate()                 │
                                ▼                          │
                          ┌─────────┐                      │
                          │ Rotated │ ◄────────────────────┘
                          │ (new v) │
                          └─────────┘
                                │
                                │ delete()
                                ▼
                          ┌─────────┐
                          │ Deleted │
                          └─────────┘
```

## Caching Strategy

To avoid repeated decryption overhead:

1. **Request-scoped cache**: Secrets cached in memory for current request
2. **No persistent cache**: Secrets never written to file/Redis/APCu
3. **Cache key**: Based on identifier + version
4. **Invalidation**: On rotate/delete, cache cleared

## Error Handling

```php
namespace Netresearch\NrVault\Exception;

VaultException (base)
├── SecretNotFoundException      -- Secret doesn't exist
├── AccessDeniedException        -- User lacks permission
├── EncryptionException          -- Crypto operation failed
├── MasterKeyException           -- Master key not available
├── AdapterException             -- External vault error
└── ValidationException          -- Invalid identifier/options
```

## Configuration

```php
// ext_conf_template.txt or extension configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'adapter' => 'local',  // local, hashicorp, aws, azure
    'masterKeyProvider' => 'file',  // file, env, derived
    'masterKeyPath' => '/var/secrets/typo3/nr-vault-master.key',
    'auditLogRetention' => 365,  // days, 0 = forever
    'cacheEnabled' => true,
];
```

## Extension Points

### Custom Adapters

```php
class MyCustomAdapter implements VaultAdapterInterface
{
    public function store(string $identifier, string $secret, array $metadata): void;
    public function retrieve(string $identifier): ?string;
    public function delete(string $identifier): void;
    public function exists(string $identifier): bool;
    public function list(): array;
}

// Register in Services.yaml
services:
  Vendor\MyExtension\Vault\MyCustomAdapter:
    tags:
      - { name: 'nr_vault.adapter', identifier: 'my_custom' }
```

### Event Listeners

```php
// Events dispatched by VaultService
SecretStoredEvent        -- After secret created/updated
SecretRetrievedEvent     -- After secret read (not value, just identifier)
SecretDeletedEvent       -- After secret deleted
SecretRotatedEvent       -- After secret rotated
MasterKeyRotatedEvent    -- After master key rotation
AccessDeniedEvent        -- When access check fails
```
