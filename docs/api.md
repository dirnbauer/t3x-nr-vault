# nr-vault API Reference

## VaultServiceInterface

The primary interface for interacting with the vault.

```php
namespace Netresearch\NrVault\Service;

interface VaultServiceInterface
{
    /**
     * Store a secret.
     *
     * Creates a new secret or updates an existing one.
     * If the secret exists, it will be overwritten (use rotate() for versioned updates).
     *
     * @param string $identifier Unique identifier for the secret (e.g., "myext_api_key_123")
     * @param string $secret     The secret value to store
     * @param array  $options    Optional configuration:
     *                           - owner: int - BE user UID who owns this secret
     *                           - groups: int[] - BE user group UIDs allowed to access
     *                           - expires: \DateTimeInterface|null - When secret expires
     *                           - metadata: array - Custom metadata (JSON-serializable)
     *                           - pid: int - Page ID for multi-site scoping
     *
     * @throws ValidationException If identifier is invalid
     * @throws EncryptionException If encryption fails
     */
    public function store(string $identifier, string $secret, array $options = []): void;

    /**
     * Retrieve a secret value.
     *
     * Returns the decrypted secret value or null if not found.
     * Automatically checks access permissions and logs the access.
     *
     * @param string $identifier The secret identifier
     *
     * @return string|null The secret value, or null if not found
     *
     * @throws AccessDeniedException If current user lacks permission
     * @throws EncryptionException If decryption fails
     * @throws SecretExpiredException If secret has expired
     */
    public function retrieve(string $identifier): ?string;

    /**
     * Check if a secret exists.
     *
     * Does not decrypt or log access, only checks existence.
     *
     * @param string $identifier The secret identifier
     *
     * @return bool True if secret exists (regardless of access permission)
     */
    public function exists(string $identifier): bool;

    /**
     * Delete a secret permanently.
     *
     * Removes the secret from storage. This action is logged.
     *
     * @param string $identifier The secret identifier
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     */
    public function delete(string $identifier): void;

    /**
     * Rotate a secret.
     *
     * Updates the secret value and increments the version.
     * The old value is not retained.
     *
     * @param string $identifier The secret identifier
     * @param string $newSecret  The new secret value
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     * @throws EncryptionException If encryption fails
     */
    public function rotate(string $identifier, string $newSecret): void;

    /**
     * List all accessible secret identifiers.
     *
     * Returns identifiers only, not values. Filtered by current user's permissions.
     *
     * @param array $filters Optional filters:
     *                       - owner: int - Filter by owner UID
     *                       - prefix: string - Filter by identifier prefix
     *                       - pid: int - Filter by page ID
     *
     * @return string[] Array of secret identifiers
     */
    public function list(array $filters = []): array;

    /**
     * Get metadata about a secret.
     *
     * Returns information about the secret without revealing its value.
     *
     * @param string $identifier The secret identifier
     *
     * @return array{
     *     identifier: string,
     *     owner: int,
     *     groups: int[],
     *     version: int,
     *     createdAt: \DateTimeInterface,
     *     updatedAt: \DateTimeInterface,
     *     expiresAt: ?\DateTimeInterface,
     *     metadata: array,
     * }
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     */
    public function getMetadata(string $identifier): array;
}
```

## VaultAdapterInterface

Interface for storage adapters (local encryption, HashiCorp Vault, AWS, etc.).

```php
namespace Netresearch\NrVault\Adapter;

interface VaultAdapterInterface
{
    /**
     * Get the adapter identifier.
     *
     * @return string e.g., "local", "hashicorp", "aws"
     */
    public function getIdentifier(): string;

    /**
     * Check if the adapter is available and configured.
     *
     * @return bool True if adapter can be used
     */
    public function isAvailable(): bool;

    /**
     * Store an encrypted secret.
     *
     * @param string $identifier Unique identifier
     * @param string $encryptedValue Already-encrypted value (for local) or plaintext (for external)
     * @param array  $metadata Secret metadata
     */
    public function store(string $identifier, string $encryptedValue, array $metadata): void;

    /**
     * Retrieve an encrypted secret.
     *
     * @param string $identifier The secret identifier
     *
     * @return string|null The encrypted value (for local) or plaintext (for external)
     */
    public function retrieve(string $identifier): ?string;

    /**
     * Delete a secret.
     *
     * @param string $identifier The secret identifier
     */
    public function delete(string $identifier): void;

    /**
     * Check if secret exists.
     *
     * @param string $identifier The secret identifier
     *
     * @return bool True if exists
     */
    public function exists(string $identifier): bool;

    /**
     * List all secret identifiers.
     *
     * @param array $filters Optional filters
     *
     * @return string[] Array of identifiers
     */
    public function list(array $filters = []): array;

    /**
     * Get metadata for a secret.
     *
     * @param string $identifier The secret identifier
     *
     * @return array|null Metadata or null if not found
     */
    public function getMetadata(string $identifier): ?array;

    /**
     * Update metadata without changing the secret value.
     *
     * @param string $identifier The secret identifier
     * @param array  $metadata New metadata (merged with existing)
     */
    public function updateMetadata(string $identifier, array $metadata): void;
}
```

## EncryptionServiceInterface

Low-level encryption operations.

```php
namespace Netresearch\NrVault\Crypto;

interface EncryptionServiceInterface
{
    /**
     * Encrypt a value using the master key.
     *
     * @param string $plaintext The value to encrypt
     *
     * @return string Base64-encoded ciphertext (includes nonce and auth tag)
     *
     * @throws EncryptionException If encryption fails
     */
    public function encrypt(string $plaintext): string;

    /**
     * Decrypt a value using the master key.
     *
     * @param string $ciphertext Base64-encoded ciphertext
     *
     * @return string The decrypted plaintext
     *
     * @throws EncryptionException If decryption fails (wrong key, tampered data)
     */
    public function decrypt(string $ciphertext): string;

    /**
     * Generate a new Data Encryption Key.
     *
     * @return string 32-byte random key
     */
    public function generateDek(): string;

    /**
     * Encrypt a DEK with the master key.
     *
     * @param string $dek The data encryption key
     *
     * @return string Encrypted DEK
     */
    public function encryptDek(string $dek): string;

    /**
     * Decrypt a DEK with the master key.
     *
     * @param string $encryptedDek The encrypted DEK
     *
     * @return string The plaintext DEK
     */
    public function decryptDek(string $encryptedDek): string;

    /**
     * Encrypt a secret value using a DEK.
     *
     * @param string $plaintext The secret value
     * @param string $dek       The data encryption key
     *
     * @return string Encrypted secret
     */
    public function encryptWithDek(string $plaintext, string $dek): string;

    /**
     * Decrypt a secret value using a DEK.
     *
     * @param string $ciphertext The encrypted secret
     * @param string $dek        The data encryption key
     *
     * @return string Decrypted secret
     */
    public function decryptWithDek(string $ciphertext, string $dek): string;
}
```

## MasterKeyProviderInterface

Provides the master encryption key from various sources.

```php
namespace Netresearch\NrVault\Crypto;

interface MasterKeyProviderInterface
{
    /**
     * Get the provider identifier.
     *
     * @return string e.g., "file", "env", "derived"
     */
    public function getIdentifier(): string;

    /**
     * Check if the provider is configured and key is available.
     *
     * @return bool True if master key can be retrieved
     */
    public function isAvailable(): bool;

    /**
     * Get the master key.
     *
     * @return string 32-byte master key
     *
     * @throws MasterKeyException If key cannot be retrieved
     */
    public function getMasterKey(): string;

    /**
     * Store a new master key (for rotation).
     *
     * @param string $key The new 32-byte master key
     *
     * @throws MasterKeyException If key cannot be stored
     */
    public function storeMasterKey(string $key): void;

    /**
     * Generate a new random master key.
     *
     * @return string 32-byte random key (not stored, just generated)
     */
    public function generateMasterKey(): string;
}
```

## AuditLogServiceInterface

Logging of all vault operations.

```php
namespace Netresearch\NrVault\Audit;

interface AuditLogServiceInterface
{
    /**
     * Log a vault operation.
     *
     * @param string $secretIdentifier The secret that was accessed
     * @param string $action           One of: create, read, update, delete, rotate
     * @param bool   $success          Whether operation succeeded
     * @param string|null $errorMessage If failed, the error message
     */
    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null
    ): void;

    /**
     * Query audit logs.
     *
     * @param array $filters Filters:
     *                       - secretIdentifier: string
     *                       - action: string
     *                       - actorUid: int
     *                       - since: \DateTimeInterface
     *                       - until: \DateTimeInterface
     *                       - success: bool
     * @param int $limit Maximum records to return
     * @param int $offset Offset for pagination
     *
     * @return AuditLogEntry[]
     */
    public function query(array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Get audit log count for filters.
     *
     * @param array $filters Same as query()
     *
     * @return int Total count
     */
    public function count(array $filters = []): int;

    /**
     * Export audit logs to array.
     *
     * @param array $filters Same as query()
     *
     * @return array Exportable audit data
     */
    public function export(array $filters = []): array;
}
```

## CLI Commands

### vault:store

```bash
# Store a new secret (interactive)
./vendor/bin/typo3 vault:store my_api_key

# Store with value from stdin
echo "secret123" | ./vendor/bin/typo3 vault:store my_api_key --stdin

# Store with options
./vendor/bin/typo3 vault:store my_api_key --owner=1 --groups=1,2 --expires="+30 days"
```

### vault:retrieve

```bash
# Get a secret value (outputs to stdout)
./vendor/bin/typo3 vault:retrieve my_api_key

# Check if secret exists (exit code 0 = exists, 1 = not found)
./vendor/bin/typo3 vault:exists my_api_key
```

### vault:rotate

```bash
# Rotate a secret (interactive)
./vendor/bin/typo3 vault:rotate my_api_key

# Rotate with new value from stdin
echo "newsecret456" | ./vendor/bin/typo3 vault:rotate my_api_key --stdin
```

### vault:delete

```bash
# Delete a secret
./vendor/bin/typo3 vault:delete my_api_key

# Force delete without confirmation
./vendor/bin/typo3 vault:delete my_api_key --force
```

### vault:list

```bash
# List all secrets (identifiers only)
./vendor/bin/typo3 vault:list

# List with prefix filter
./vendor/bin/typo3 vault:list --prefix=my_extension_

# List with owner filter
./vendor/bin/typo3 vault:list --owner=1
```

### vault:audit

```bash
# Show recent audit logs
./vendor/bin/typo3 vault:audit

# Filter by secret
./vendor/bin/typo3 vault:audit --secret=my_api_key

# Filter by action and time
./vendor/bin/typo3 vault:audit --action=read --since="2024-01-01"

# Export to JSON
./vendor/bin/typo3 vault:audit --format=json > audit.json
```

### vault:master-key

```bash
# Generate a new master key (outputs to stdout, doesn't store)
./vendor/bin/typo3 vault:master-key:generate

# Rotate master key (re-encrypts all DEKs)
./vendor/bin/typo3 vault:master-key:rotate

# Export master key (for backup)
./vendor/bin/typo3 vault:master-key:export
```

## Usage Examples

### Storing an API Key

```php
use Netresearch\NrVault\Service\VaultService;

class MyApiService
{
    public function __construct(
        private readonly VaultService $vault,
    ) {}

    public function saveApiKey(int $providerUid, string $apiKey): void
    {
        $this->vault->store(
            identifier: "my_extension_provider_{$providerUid}_api_key",
            secret: $apiKey,
            options: [
                'owner' => $GLOBALS['BE_USER']->user['uid'] ?? 0,
                'groups' => [1],  // Admin group only
                'metadata' => [
                    'provider_uid' => $providerUid,
                    'type' => 'api_key',
                ],
            ]
        );
    }

    public function getApiKey(int $providerUid): ?string
    {
        return $this->vault->retrieve("my_extension_provider_{$providerUid}_api_key");
    }
}
```

### Rotating Secrets

```php
public function rotateApiKey(int $providerUid, string $newApiKey): void
{
    $identifier = "my_extension_provider_{$providerUid}_api_key";

    if (!$this->vault->exists($identifier)) {
        throw new \RuntimeException('API key not found');
    }

    $this->vault->rotate($identifier, $newApiKey);
}
```

### Listing User's Secrets

```php
public function getUserSecrets(int $userUid): array
{
    $identifiers = $this->vault->list([
        'owner' => $userUid,
        'prefix' => 'my_extension_',
    ]);

    $secrets = [];
    foreach ($identifiers as $identifier) {
        $secrets[$identifier] = $this->vault->getMetadata($identifier);
    }

    return $secrets;
}
```

### TCA Integration

```php
// Configuration/TCA/tx_myext_config.php
return [
    'columns' => [
        'api_key' => [
            'label' => 'API Key',
            'config' => [
                'type' => 'user',
                'renderType' => 'vaultSecret',
                'parameters' => [
                    // {uid} is replaced with record UID
                    'vaultIdentifier' => 'myext_config_{uid}_api_key',
                    // Show button to generate new key
                    'showRotateButton' => true,
                    // Show button to reveal key (temporarily)
                    'showRevealButton' => true,
                    // Allowed BE groups (empty = owner only)
                    'allowedGroups' => [1],
                ],
            ],
        ],
    ],
];
```
