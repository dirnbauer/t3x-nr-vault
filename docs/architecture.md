# nr-vault Architecture

**Target:** TYPO3 v13.4+ | PHP 8.2+

## Overview

nr-vault is designed as a layered architecture with pluggable storage adapters, following the adapter pattern to support both self-contained operation and external vault services.

## Design Principles

1. **Secure by Default**: All secrets encrypted at rest using AES-256-GCM (XChaCha20-Poly1305 fallback)
2. **No External Dependencies Required**: Works out-of-the-box with local encryption
3. **Pluggable Adapters**: Support for external vaults (HashiCorp, AWS, etc.)
4. **Audit Everything**: Every secret access is logged with tamper-evident hash chain
5. **Access Control**: Secrets scoped to backend user groups with context-based permissions
6. **TYPO3 Native**: Integrates with TCA, DataHandler hooks, and TYPO3 v14 patterns
7. **Zero Secret Exposure**: HTTP client injects secrets without exposing to calling code

## Component Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           TYPO3 Backend / CLI                                │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌────────────────┐  ┌────────────────┐  ┌────────────────────────────────┐ │
│  │ TCA Field      │  │ Backend Module │  │ CLI Commands                   │ │
│  │ (vaultSecret)  │  │ (Management UI)│  │ (vault:store, vault:rotate)    │ │
│  └───────┬────────┘  └───────┬────────┘  └─────────────┬──────────────────┘ │
│          │                   │                         │                     │
│          └───────────────────┼─────────────────────────┘                     │
│                              ▼                                               │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │                      VaultService (Facade)                             │  │
│  │  - store(identifier, secret, options)                                  │  │
│  │  - retrieve(identifier): ?string                                       │  │
│  │  - rotate(identifier, newSecret, reason)                               │  │
│  │  - delete(identifier, reason)                                          │  │
│  │  - list(filters): array                                                │  │
│  │  - getMetadata(identifier): array                                      │  │
│  │  - http(): VaultHttpClient                                             │  │
│  └───────────────────────────────┬───────────────────────────────────────┘  │
│                                  │                                           │
│          ┌───────────────────────┼───────────────────────┐                  │
│          ▼                       ▼                       ▼                  │
│  ┌───────────────┐  ┌────────────────────┐  ┌────────────────────────────┐ │
│  │ AccessControl │  │ AuditLogService    │  │ VaultHttpClient            │ │
│  │ Service       │  │ (hash chain)       │  │ (secret injection)         │ │
│  └───────────────┘  └────────────────────┘  └────────────────────────────┘ │
│          │                                                                   │
│          ▼                                                                   │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │                      VaultAdapterInterface                             │  │
│  └───────────────────────────────┬───────────────────────────────────────┘  │
│                                  │                                           │
└──────────────────────────────────┼───────────────────────────────────────────┘
                                   │
       ┌───────────────────────┬───┴───────────────────────┬───────────────────┐
       ▼                       ▼                           ▼                   ▼
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

## Vault HTTP Client

The HTTP client enables making authenticated API calls without exposing secrets to calling code:

```
┌────────────────────────────────────────────────────────────────────────────┐
│                           Calling Code                                      │
│                                                                             │
│  $vault->http()                                                             │
│      ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)           │
│      ->post('https://api.stripe.com/v1/charges', $payload);                │
│                                                                             │
│  (Secret value NEVER visible to this code)                                  │
└──────────────────────────────────┬─────────────────────────────────────────┘
                                   │
                                   ▼
┌────────────────────────────────────────────────────────────────────────────┐
│                         VaultHttpClient                                     │
│                                                                             │
│  1. Retrieve secret from vault (internally)                                 │
│  2. Build request with secret injected via SecretPlacement                  │
│  3. Execute HTTP request                                                    │
│  4. Log HTTP call to audit trail                                            │
│  5. Securely wipe secret from memory (sodium_memzero)                       │
│  6. Return VaultHttpResponse                                                │
└────────────────────────────────────────────────────────────────────────────┘
```

### Secret Placement Options

| Placement | Description | Example |
|-----------|-------------|---------|
| `BearerAuth` | Authorization header | `Authorization: Bearer <secret>` |
| `BasicAuthPassword` | Basic auth password | `Authorization: Basic base64(user:secret)` |
| `Header` | Custom header | `X-Api-Key: <secret>` |
| `QueryParam` | URL query parameter | `?api_key=<secret>` |
| `BodyField` | JSON body field | `{"api_key": "<secret>"}` |
| `UrlSegment` | URL path segment | `/api/<secret>/resource` |

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

### Encryption Algorithm Selection

nr-vault uses AES-256-GCM as the primary encryption algorithm with automatic fallback:

```php
if (sodium_crypto_aead_aes256gcm_is_available()) {
    // Use AES-256-GCM (hardware-accelerated on AES-NI CPUs)
    $algorithm = 'aes256gcm';
} else {
    // Fall back to XChaCha20-Poly1305 (software, constant-time)
    $algorithm = 'xchacha20poly1305';
}
```

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

### Master Key Provider Factory

```php
final class MasterKeyProviderFactory
{
    public function create(): MasterKeyProviderInterface
    {
        $provider = $this->configuration->getMasterKeyProvider();

        return match ($provider) {
            'file' => new FileMasterKeyProvider($this->configuration),
            'env' => new EnvironmentMasterKeyProvider($this->configuration),
            'derived' => new DerivedMasterKeyProvider($this->configuration),
            default => throw new ConfigurationException("Unknown provider: {$provider}"),
        };
    }
}
```

## Access Control

Secrets can be scoped to specific backend user groups with context-based permissions:

```php
$vault->store('my_api_key', $secret, [
    'owner' => $beUserUid,
    'groups' => [1, 2],  // Only these BE groups can access
    'context' => 'payment',  // Permission context
]);
```

Access is checked on every `retrieve()` call:

1. User must be logged in (backend context)
2. User must be owner OR member of allowed groups
3. Context-based permissions are evaluated
4. System maintainers bypass group restrictions
5. CLI access requires explicit `allowCliAccess` configuration

### CLI Access Control

```php
// CLI access is disabled by default
if (PHP_SAPI === 'cli') {
    if (!$this->configuration->isCliAccessAllowed()) {
        return false;  // Deny CLI access
    }
    // Optionally restrict to specific groups
    $cliAccessGroups = $this->configuration->getCliAccessGroups();
    if (!empty($cliAccessGroups)) {
        return !empty(array_intersect($secret->getAllowedGroups(), $cliAccessGroups));
    }
}
```

## Audit Logging

Every secret operation is logged with tamper-evident hash chain:

```sql
tx_nrvault_audit_log
├── secret_identifier   -- Which secret
├── action              -- create, read, update, delete, rotate, access_denied, http_call
├── actor_uid           -- Who performed action
├── actor_type          -- backend, cli, api, scheduler
├── actor_username      -- Denormalized for immutability
├── actor_role          -- User's role at time of action
├── ip_address          -- Request IP
├── user_agent          -- HTTP User-Agent
├── request_id          -- Unique request correlation ID
├── success             -- Whether operation succeeded
├── error_message       -- If failed, why
├── reason              -- Required reason for rotate/delete
├── hash_before         -- Secret's checksum before operation
├── hash_after          -- Secret's checksum after operation
├── previous_hash       -- Hash chain link (SHA-256)
├── entry_hash          -- This entry's hash
├── context             -- Additional JSON context
└── crdate              -- When
```

### Hash Chain for Tamper Detection

```php
public function calculateEntryHash(array $entry, string $previousHash): string
{
    $payload = json_encode([
        'uid' => $entry['uid'],
        'secret_identifier' => $entry['secret_identifier'],
        'action' => $entry['action'],
        'actor_uid' => $entry['actor_uid'],
        'crdate' => $entry['crdate'],
        'previous_hash' => $previousHash,
    ], JSON_THROW_ON_ERROR);

    return hash('sha256', $payload);
}
```

Audit logs are:
- Never deleted automatically
- Queryable via API and backend module
- Exportable for compliance
- Protected by database triggers (optional)

## Secret Lifecycle

```
┌─────────┐    store()     ┌─────────┐   retrieve()   ┌─────────┐
│ Created │ ─────────────► │ Active  │ ◄────────────► │ In Use  │
└─────────┘                └─────────┘                └─────────┘
                                │                          │
                                │ rotate(reason)           │
                                ▼                          │
                          ┌─────────┐                      │
                          │ Rotated │ ◄────────────────────┘
                          │ (new v) │
                          └─────────┘
                                │
                                │ delete(reason)
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
5. **Memory wiping**: `sodium_memzero()` used after operations

## Error Handling

```php
namespace Netresearch\NrVault\Exception;

VaultException (base)
├── SecretNotFoundException      -- Secret doesn't exist
├── SecretExpiredException       -- Secret has expired
├── AccessDeniedException        -- User lacks permission
├── EncryptionException          -- Crypto operation failed
├── MasterKeyException           -- Master key not available
├── AdapterException             -- External vault error
├── ValidationException          -- Invalid identifier/options
└── ConfigurationException       -- Extension misconfigured
```

## Configuration

```php
// ext_conf_template.txt or extension configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    // Storage adapter: 'local', 'hashicorp', 'aws', 'azure'
    'adapter' => 'local',

    // Master key provider: 'file', 'env', 'derived'
    'masterKeyProvider' => 'file',
    'masterKeyPath' => '/var/secrets/typo3/nr-vault-master.key',
    'masterKeyEnvVar' => 'NR_VAULT_MASTER_KEY',

    // Audit settings
    'auditLogRetention' => 365,  // days, 0 = forever

    // CLI settings
    'allowCliAccess' => false,  // Disabled by default
    'cliAccessGroups' => [],    // Empty = all accessible if CLI allowed

    // Cache settings
    'cacheEnabled' => true,  // Request-scoped only

    // Encryption fallback
    'preferXChaCha20' => false,  // Force XChaCha20 even on AES-NI systems
];
```

## Extension Points

### Custom Adapters

```php
class MyCustomAdapter implements VaultAdapterInterface
{
    public function getIdentifier(): string { return 'my_custom'; }
    public function isAvailable(): bool { /* ... */ }
    public function store(Secret $secret): void { /* ... */ }
    public function retrieve(string $identifier): ?Secret { /* ... */ }
    public function delete(string $identifier): void { /* ... */ }
    public function exists(string $identifier): bool { /* ... */ }
    public function list(array $filters = []): array { /* ... */ }
    public function getMetadata(string $identifier): ?array { /* ... */ }
    public function updateMetadata(string $identifier, array $metadata): void { /* ... */ }
}

// Register in Services.yaml
services:
  Vendor\MyExtension\Vault\MyCustomAdapter:
    tags:
      - { name: 'nr_vault.adapter', identifier: 'my_custom' }
```

### PSR-14 Event Listeners

```php
// Events dispatched by VaultService
namespace Netresearch\NrVault\Event;

SecretStoredEvent        -- After secret created/updated
SecretRetrievedEvent     -- After secret read (identifier only, not value)
SecretDeletedEvent       -- After secret deleted
SecretRotatedEvent       -- After secret rotated
MasterKeyRotatedEvent    -- After master key rotation
AccessDeniedEvent        -- When access check fails
HttpCallEvent            -- After HTTP call with secret (identifier only)
```

### DataHandler Hooks

```php
// ext_localconf.php
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Netresearch\NrVault\Hook\DataHandlerHook::class;

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = \Netresearch\NrVault\Hook\DataHandlerHook::class;
```

## Future: Service Registry (Phase 8)

Abstract away both credentials AND endpoints:

```php
// Current: Developer manages URL + credentials
$response = $vault->http()
    ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
    ->post('https://api.stripe.com/v1/charges', $payload);

// Future: Service registry handles everything
$response = $vault->service('stripe')->post('charges', $payload);
// Automatically resolves: URL + API version + credentials + rate limiting
```

---

*Architecture Version: 2.0*
*Compatible with: TYPO3 v13.4+ | PHP 8.2+*
