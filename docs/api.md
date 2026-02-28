# nr-vault API Reference

**Target:** TYPO3 v13.4+ | PHP 8.2+

## VaultServiceInterface

The primary interface for interacting with the vault.

```php
namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Http\VaultHttpClientInterface;

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
     *                           - context: string - Permission scoping (e.g., 'payment', 'hr')
     *                           - expiresAt: int|\DateTimeInterface|null - When secret expires
     *                           - metadata: array - Custom metadata (JSON-serializable)
     *                           - description: string - Human-readable description
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
     * @param string $reason     Required reason for deletion (compliance requirement)
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     */
    public function delete(string $identifier, string $reason = ''): void;

    /**
     * Rotate a secret.
     *
     * Updates the secret value and increments the version.
     * The old value is not retained.
     *
     * @param string $identifier The secret identifier
     * @param string $newSecret  The new secret value
     * @param string $reason     Required reason for rotation (compliance requirement)
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     * @throws EncryptionException If encryption fails
     */
    public function rotate(string $identifier, string $newSecret, string $reason = ''): void;

    /**
     * List all accessible secret identifiers.
     *
     * Returns identifiers only, not values. Filtered by current user's permissions.
     *
     * @param array $filters Optional filters:
     *                       - owner: int - Filter by owner UID
     *                       - prefix: string - Filter by identifier prefix
     *                       - context: string - Filter by permission context
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
     *     description: string,
     *     owner: int,
     *     groups: int[],
     *     context: string,
     *     version: int,
     *     createdAt: \DateTimeInterface,
     *     updatedAt: \DateTimeInterface,
     *     expiresAt: ?\DateTimeInterface,
     *     lastRotatedAt: ?\DateTimeInterface,
     *     metadata: array,
     * }
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If current user lacks permission
     */
    public function getMetadata(string $identifier): array;

    /**
     * Get the Vault HTTP Client for making authenticated API calls.
     *
     * The HTTP client injects secrets into requests without exposing
     * them to calling code.
     *
     * @return VaultHttpClientInterface
     */
    public function http(): VaultHttpClientInterface;
}
```

## VaultHttpClientInterface

Make authenticated HTTP requests with automatic secret injection.

```php
namespace Netresearch\NrVault\Http;

use Psr\Http\Message\ResponseInterface;

interface VaultHttpClientInterface
{
    /**
     * Attach a secret to be injected into requests.
     *
     * @param string          $identifier Secret identifier in vault
     * @param SecretPlacement $placement  How/where to inject the secret
     * @param string|null     $name       Custom header/param name (optional)
     *
     * @return self Fluent interface
     */
    public function withSecret(
        string $identifier,
        SecretPlacement $placement,
        ?string $name = null
    ): self;

    /**
     * Attach OAuth credentials for automatic token refresh.
     *
     * @param OAuthConfig $config OAuth configuration
     *
     * @return self Fluent interface
     */
    public function withOAuth(OAuthConfig $config): self;

    /**
     * Send a GET request.
     *
     * @param string $url     Request URL
     * @param array  $options Request options (headers, query, etc.)
     *
     * @return VaultHttpResponse
     */
    public function get(string $url, array $options = []): VaultHttpResponse;

    /**
     * Send a POST request.
     *
     * @param string       $url     Request URL
     * @param array|string $body    Request body
     * @param array        $options Request options
     *
     * @return VaultHttpResponse
     */
    public function post(string $url, array|string $body = [], array $options = []): VaultHttpResponse;

    /**
     * Send a PUT request.
     *
     * @param string       $url     Request URL
     * @param array|string $body    Request body
     * @param array        $options Request options
     *
     * @return VaultHttpResponse
     */
    public function put(string $url, array|string $body = [], array $options = []): VaultHttpResponse;

    /**
     * Send a PATCH request.
     *
     * @param string       $url     Request URL
     * @param array|string $body    Request body
     * @param array        $options Request options
     *
     * @return VaultHttpResponse
     */
    public function patch(string $url, array|string $body = [], array $options = []): VaultHttpResponse;

    /**
     * Send a DELETE request.
     *
     * @param string $url     Request URL
     * @param array  $options Request options
     *
     * @return VaultHttpResponse
     */
    public function delete(string $url, array $options = []): VaultHttpResponse;

    /**
     * Send a request with any HTTP method.
     *
     * @param string       $method  HTTP method
     * @param string       $url     Request URL
     * @param array|string $body    Request body (optional)
     * @param array        $options Request options
     *
     * @return VaultHttpResponse
     */
    public function request(
        string $method,
        string $url,
        array|string $body = [],
        array $options = []
    ): VaultHttpResponse;
}
```

## SecretPlacement Enum

Defines where secrets are injected in HTTP requests.

```php
namespace Netresearch\NrVault\Http;

enum SecretPlacement: string
{
    /**
     * Inject as Bearer token in Authorization header.
     * Authorization: Bearer <secret>
     */
    case BearerAuth = 'bearer_auth';

    /**
     * Inject as Basic auth password (username from options).
     * Authorization: Basic base64(username:secret)
     */
    case BasicAuthPassword = 'basic_auth_password';

    /**
     * Inject as custom header value.
     * X-Api-Key: <secret>
     */
    case Header = 'header';

    /**
     * Inject as query parameter.
     * ?api_key=<secret>
     */
    case QueryParam = 'query_param';

    /**
     * Inject into JSON body field.
     * {"api_key": "<secret>", ...}
     */
    case BodyField = 'body_field';

    /**
     * Replace placeholder in URL.
     * /api/{secret}/resource
     */
    case UrlSegment = 'url_segment';
}
```

## VaultHttpResponse

Response wrapper with convenience methods.

```php
namespace Netresearch\NrVault\Http;

use Psr\Http\Message\ResponseInterface;

final class VaultHttpResponse
{
    public function __construct(
        private readonly ResponseInterface $response,
    ) {}

    /**
     * Get the underlying PSR-7 response.
     */
    public function getResponse(): ResponseInterface;

    /**
     * Get HTTP status code.
     */
    public function getStatusCode(): int;

    /**
     * Check if response was successful (2xx).
     */
    public function isSuccessful(): bool;

    /**
     * Get response body as string.
     */
    public function getBody(): string;

    /**
     * Parse response body as JSON.
     *
     * @param bool $assoc Return associative array (default) or objects
     *
     * @return array|object
     *
     * @throws \JsonException If body is not valid JSON
     */
    public function json(bool $assoc = true): array|object;

    /**
     * Get a response header value.
     *
     * @param string $name Header name (case-insensitive)
     *
     * @return string|null Header value or null if not present
     */
    public function getHeader(string $name): ?string;

    /**
     * Get all response headers.
     *
     * @return array<string, string[]>
     */
    public function getHeaders(): array;
}
```

## OAuthConfig

Configuration for OAuth authentication with automatic token refresh.

```php
namespace Netresearch\NrVault\Http;

final class OAuthConfig
{
    public function __construct(
        /**
         * Vault identifier for client ID.
         */
        public readonly string $clientIdIdentifier,

        /**
         * Vault identifier for client secret.
         */
        public readonly string $clientSecretIdentifier,

        /**
         * Token endpoint URL.
         */
        public readonly string $tokenUrl,

        /**
         * OAuth grant type.
         */
        public readonly string $grantType = 'client_credentials',

        /**
         * OAuth scopes (space-separated or array).
         */
        public readonly string|array $scopes = [],

        /**
         * Vault identifier for storing/retrieving access token.
         * If set, tokens are cached in vault with expiration.
         */
        public readonly ?string $tokenIdentifier = null,

        /**
         * Token refresh buffer in seconds.
         * Refresh token this many seconds before expiry.
         */
        public readonly int $refreshBuffer = 60,
    ) {}
}
```

## VaultAdapterInterface

Interface for storage adapters (local encryption, HashiCorp Vault, AWS, etc.).

```php
namespace Netresearch\NrVault\Adapter;

use Netresearch\NrVault\Domain\Model\Secret;

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
     * Store a secret.
     *
     * @param Secret $secret The secret entity to store
     */
    public function store(Secret $secret): void;

    /**
     * Retrieve a secret by identifier.
     *
     * @param string $identifier The secret identifier
     *
     * @return Secret|null The secret entity or null if not found
     */
    public function retrieve(string $identifier): ?Secret;

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
     * Get metadata for a secret without decrypting value.
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

Low-level encryption operations using AES-256-GCM.

```php
namespace Netresearch\NrVault\Crypto;

interface EncryptionServiceInterface
{
    /**
     * Encrypt a secret value with a unique DEK.
     *
     * @param string $plaintext  The value to encrypt
     * @param string $identifier Secret identifier (used as AAD)
     *
     * @return array{
     *     encrypted_value: string,
     *     encrypted_dek: string,
     *     dek_nonce: string,
     *     value_nonce: string,
     *     value_checksum: string,
     * }
     *
     * @throws EncryptionException If encryption fails
     */
    public function encrypt(string $plaintext, string $identifier): array;

    /**
     * Decrypt a secret value.
     *
     * @param string $encryptedValue Base64-encoded ciphertext
     * @param string $encryptedDek   Base64-encoded encrypted DEK
     * @param string $dekNonce       Base64-encoded DEK nonce
     * @param string $valueNonce     Base64-encoded value nonce
     * @param string $identifier     Secret identifier (used as AAD)
     *
     * @return string The decrypted plaintext
     *
     * @throws EncryptionException If decryption fails (wrong key, tampered data)
     */
    public function decrypt(
        string $encryptedValue,
        string $encryptedDek,
        string $dekNonce,
        string $valueNonce,
        string $identifier
    ): string;

    /**
     * Generate a new Data Encryption Key.
     *
     * @return string 32-byte random key (SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES)
     */
    public function generateDek(): string;

    /**
     * Encrypt a DEK with the master key.
     *
     * @param string $dek        The data encryption key
     * @param string $identifier Secret identifier (used as AAD)
     *
     * @return array{encrypted_dek: string, nonce: string}
     */
    public function encryptDek(string $dek, string $identifier): array;

    /**
     * Decrypt a DEK with the master key.
     *
     * @param string $encryptedDek Base64-encoded encrypted DEK
     * @param string $nonce        Base64-encoded nonce
     * @param string $identifier   Secret identifier (used as AAD)
     *
     * @return string The plaintext DEK
     */
    public function decryptDek(string $encryptedDek, string $nonce, string $identifier): string;

    /**
     * Calculate value checksum for change detection.
     *
     * @param string $plaintext The secret value
     *
     * @return string SHA-256 hash (64 hex characters)
     */
    public function calculateChecksum(string $plaintext): string;

    /**
     * Re-encrypt a DEK with a new master key.
     *
     * Used during master key rotation.
     *
     * @param string $encryptedDek   Current encrypted DEK
     * @param string $dekNonce       Current DEK nonce
     * @param string $identifier     Secret identifier
     * @param string $oldMasterKey   Previous master key
     * @param string $newMasterKey   New master key
     *
     * @return array{encrypted_dek: string, nonce: string}
     */
    public function reEncryptDek(
        string $encryptedDek,
        string $dekNonce,
        string $identifier,
        string $oldMasterKey,
        string $newMasterKey
    ): array;
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

Logging of all vault operations with tamper-evident hash chain.

```php
namespace Netresearch\NrVault\Audit;

interface AuditLogServiceInterface
{
    /**
     * Log a vault operation.
     *
     * @param string      $secretIdentifier The secret that was accessed
     * @param string      $action           One of: create, read, update, delete, rotate, access_denied, http_call
     * @param bool        $success          Whether operation succeeded
     * @param string|null $errorMessage     If failed, the error message
     * @param string|null $reason           Reason for operation (required for rotate/delete)
     * @param string|null $hashBefore       Secret's value_checksum before operation
     * @param string|null $hashAfter        Secret's value_checksum after operation
     * @param array       $context          Additional context (JSON-serializable)
     */
    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null,
        ?string $reason = null,
        ?string $hashBefore = null,
        ?string $hashAfter = null,
        array $context = []
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

    /**
     * Verify hash chain integrity.
     *
     * @param int|null $fromUid Starting UID (null = from beginning)
     * @param int|null $toUid   Ending UID (null = to latest)
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function verifyHashChain(?int $fromUid = null, ?int $toUid = null): array;

    /**
     * Get the hash of the most recent audit log entry.
     *
     * @return string|null SHA-256 hash or null if no entries exist
     */
    public function getLatestHash(): ?string;
}
```

## AccessControlServiceInterface

Access control for secrets based on ownership and group membership.

```php
namespace Netresearch\NrVault\Security;

use Netresearch\NrVault\Domain\Model\Secret;

interface AccessControlServiceInterface
{
    /**
     * Check if current user can read a secret.
     *
     * @param Secret $secret The secret to check
     *
     * @return bool True if access is allowed
     */
    public function canRead(Secret $secret): bool;

    /**
     * Check if current user can write/update a secret.
     *
     * @param Secret $secret The secret to check
     *
     * @return bool True if access is allowed
     */
    public function canWrite(Secret $secret): bool;

    /**
     * Check if current user can delete a secret.
     *
     * @param Secret $secret The secret to check
     *
     * @return bool True if access is allowed
     */
    public function canDelete(Secret $secret): bool;

    /**
     * Check if current user can create secrets.
     *
     * @return bool True if access is allowed
     */
    public function canCreate(): bool;

    /**
     * Get the current actor UID.
     *
     * @return int Backend user UID (0 for CLI/system)
     */
    public function getCurrentActorUid(): int;

    /**
     * Get the current actor type.
     *
     * @return string One of: 'backend', 'cli', 'api', 'scheduler'
     */
    public function getCurrentActorType(): string;

    /**
     * Get the current actor's username.
     *
     * @return string Username or 'CLI' for command line
     */
    public function getCurrentActorUsername(): string;

    /**
     * Get groups the current user belongs to.
     *
     * @return int[] Array of BE group UIDs
     */
    public function getCurrentUserGroups(): array;
}
```

## CLI Commands

### vault:init

```bash
# Initialize vault (create master key)
vendor/bin/typo3 vault:init

# Initialize with specific path
vendor/bin/typo3 vault:init --key-path=/var/secrets/typo3/vault-master.key
```

### vault:store

```bash
# Store a new secret (interactive)
vendor/bin/typo3 vault:store my_api_key

# Store with value from stdin
echo "secret123" | vendor/bin/typo3 vault:store my_api_key --stdin

# Store with options
vendor/bin/typo3 vault:store my_api_key \
    --owner=1 \
    --groups=1,2 \
    --context=payment \
    --expires="+30 days" \
    --description="Stripe API key for production"
```

### vault:retrieve

```bash
# Get a secret value (outputs to stdout)
vendor/bin/typo3 vault:retrieve my_api_key

# Check if secret exists (exit code 0 = exists, 1 = not found)
vendor/bin/typo3 vault:exists my_api_key
```

### vault:rotate

```bash
# Rotate a secret (interactive)
vendor/bin/typo3 vault:rotate my_api_key --reason="Scheduled rotation"

# Rotate with new value from stdin
echo "newsecret456" | vendor/bin/typo3 vault:rotate my_api_key --stdin --reason="Key compromised"
```

### vault:delete

```bash
# Delete a secret
vendor/bin/typo3 vault:delete my_api_key --reason="No longer needed"

# Force delete without confirmation
vendor/bin/typo3 vault:delete my_api_key --force --reason="Cleanup"
```

### vault:list

```bash
# List all secrets (identifiers only)
vendor/bin/typo3 vault:list

# List with filters
vendor/bin/typo3 vault:list --prefix=my_extension_ --context=payment

# List with owner filter and JSON output
vendor/bin/typo3 vault:list --owner=1 --format=json
```

### vault:audit

```bash
# Show recent audit logs
vendor/bin/typo3 vault:audit

# Filter by secret and days
vendor/bin/typo3 vault:audit --identifier=my_api_key --days=30

# Filter by action and time
vendor/bin/typo3 vault:audit --action=read --since="2024-01-01"

# Export to JSON
vendor/bin/typo3 vault:audit --format=json > audit.json

# Verify hash chain integrity
vendor/bin/typo3 vault:audit:verify
```

### vault:master-key

```bash
# Generate a new master key (outputs to stdout, doesn't store)
vendor/bin/typo3 vault:master-key:generate

# Rotate master key (re-encrypts all DEKs)
vendor/bin/typo3 vault:master-key:rotate --new-key-file=/path/to/new.key

# Export master key (for backup)
vendor/bin/typo3 vault:master-key:export
```

### vault:expire

```bash
# Check for expired secrets
vendor/bin/typo3 vault:expire:check

# List secrets expiring soon
vendor/bin/typo3 vault:expire:upcoming --days=30

# Send notifications for expiring secrets
vendor/bin/typo3 vault:expire:notify
```

## Usage Examples

### Storing an API Key with Context

```php
use Netresearch\NrVault\Service\VaultServiceInterface;

class PaymentService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function saveGatewayCredentials(int $gatewayUid, string $apiKey): void
    {
        $this->vault->store(
            identifier: "payment_gateway_{$gatewayUid}_api_key",
            secret: $apiKey,
            options: [
                'owner' => $GLOBALS['BE_USER']->user['uid'] ?? 0,
                'groups' => [1],  // Admin group only
                'context' => 'payment',  // Payment context
                'description' => 'Payment gateway API key',
                'metadata' => [
                    'gateway_uid' => $gatewayUid,
                    'type' => 'api_key',
                ],
            ]
        );
    }

    public function getGatewayCredentials(int $gatewayUid): ?string
    {
        return $this->vault->retrieve("payment_gateway_{$gatewayUid}_api_key");
    }
}
```

### Using the Vault HTTP Client

```php
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Http\SecretPlacement;

class StripeService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function createCharge(array $payload): array
    {
        // Secret is injected automatically - never visible to this code
        $response = $this->vault->http()
            ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
            ->post('https://api.stripe.com/v1/charges', $payload);

        if (!$response->isSuccessful()) {
            throw new PaymentException('Charge failed: ' . $response->getBody());
        }

        return $response->json();
    }
}
```

### Using OAuth with Token Refresh

```php
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Http\OAuthConfig;

class GoogleCalendarService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function getEvents(): array
    {
        $oauth = new OAuthConfig(
            clientIdIdentifier: 'google_client_id',
            clientSecretIdentifier: 'google_client_secret',
            tokenUrl: 'https://oauth2.googleapis.com/token',
            scopes: ['calendar.readonly'],
            tokenIdentifier: 'google_access_token',  // Cache token in vault
        );

        $response = $this->vault->http()
            ->withOAuth($oauth)
            ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events');

        return $response->json();
    }
}
```

### Rotating Secrets with Reason

```php
public function rotateApiKey(int $providerUid, string $newApiKey): void
{
    $identifier = "my_extension_provider_{$providerUid}_api_key";

    if (!$this->vault->exists($identifier)) {
        throw new \RuntimeException('API key not found');
    }

    $this->vault->rotate(
        $identifier,
        $newApiKey,
        reason: 'Scheduled quarterly rotation'
    );
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
                    // Permission context
                    'context' => 'payment',
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

---

*API Version: 2.0*
*Compatible with: TYPO3 v13.4+ | PHP 8.2+*
