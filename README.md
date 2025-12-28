# nr-vault: Secure Secrets Management for TYPO3

A TYPO3 v14 extension providing centralized, secure storage for API keys, credentials, and other secrets with encryption at rest, access control, audit logging, and a secure HTTP client.

## Problem Statement

TYPO3 lacks a proper secrets management solution. Current approaches are inadequate:

| Approach | Problem |
|----------|---------|
| TCA `type=password` | Hashes by default (irreversible) or stores plaintext |
| Extension configuration | Stored in `LocalConfiguration.php` (often in git) |
| Environment variables | Not suitable for multi-user, runtime-configurable secrets |
| Database plaintext | No encryption, exposed in backups, SQL injection risk |

Every extension that needs to store API keys reinvents this wheel, often insecurely.

## Solution

nr-vault provides:

- **Envelope encryption** with AES-256-GCM via libsodium
- **Master key management** (file, environment variable, or derived)
- **Per-secret access control** via backend user groups with context scoping
- **Audit logging** of all secret access with tamper-evident hash chain
- **Key rotation** support for both secrets and master key
- **TCA integration** via custom `vaultSecret` field type
- **Vault HTTP Client** - make authenticated API calls without exposing secrets
- **CLI commands** for DevOps automation
- **Optional external vault adapters** (HashiCorp Vault, AWS Secrets Manager, Azure Key Vault)

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                         TYPO3 Backend                                    │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐          │
│  │  TCA Field      │  │ Backend Module  │  │  CLI Commands   │          │
│  │  (vaultSecret)  │  │  (Secrets Mgr)  │  │                 │          │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘          │
│           └────────────────────┼─────────────────────┘                   │
│                                ▼                                          │
│  ┌───────────────────────────────────────────────────────────────────┐   │
│  │                        VaultService                                │   │
│  │  store() │ retrieve() │ rotate() │ delete() │ list() │ http()     │   │
│  └───────────────────────────────────────────────────────────────────┘   │
│                                │                                          │
│      ┌─────────────────────────┼─────────────────────────┐               │
│      ▼                         ▼                         ▼               │
│  ┌────────────────┐   ┌────────────────┐   ┌────────────────┐           │
│  │ AccessControl  │   │ EncryptionSvc  │   │  AuditLogSvc   │           │
│  │ Service        │   │                │   │                │           │
│  └────────────────┘   └───────┬────────┘   └────────────────┘           │
│                               │                                           │
│                               ▼                                           │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │                      Vault Adapters                                 │  │
│  │  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │  │
│  │  │ LocalDatabase│  │ HashiCorp    │  │ AWS Secrets  │              │  │
│  │  │ (DEFAULT)    │  │ Vault        │  │ Manager      │              │  │
│  │  └──────────────┘  └──────────────┘  └──────────────┘              │  │
│  └────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────┘
```

## Encryption Model

Uses **envelope encryption** (same pattern as AWS KMS, Google Cloud KMS):

```
Master Key (stored outside database)
    │
    ▼ encrypts
Data Encryption Key (DEK) - unique per secret
    │
    ▼ encrypts
Secret Value (API key, password, token)
```

Benefits:
- Master key rotation only requires re-encrypting DEKs (fast)
- Each secret has unique encryption
- Compromise of one secret doesn't expose others

## Quick Start

### Store and Retrieve Secrets

```php
use Netresearch\NrVault\Service\VaultServiceInterface;

class MyService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function storeApiKey(string $provider, string $apiKey): void
    {
        $this->vault->store(
            identifier: "my_extension_{$provider}_api_key",
            secret: $apiKey,
            options: [
                'owner' => $GLOBALS['BE_USER']->user['uid'],
                'groups' => [1, 2],  // Admin, Editor groups
                'context' => 'payment',  // Permission scoping
                'expiresAt' => time() + 86400 * 90,  // 90 days
            ]
        );
    }

    public function getApiKey(string $provider): ?string
    {
        return $this->vault->retrieve("my_extension_{$provider}_api_key");
    }
}
```

### Vault HTTP Client

Make authenticated API calls without exposing secrets to your code:

```php
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Http\SecretPlacement;

class PaymentService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function chargeCustomer(array $payload): array
    {
        // Secret is injected by vault - never visible to this code
        $response = $this->vault->http()
            ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
            ->post('https://api.stripe.com/v1/charges', $payload);

        return $response->json();
    }
}
```

Secret placement options: `BearerAuth`, `BasicAuthPassword`, `Header`, `QueryParam`, `BodyField`, `UrlSegment`.

## TCA Integration

```php
'api_key' => [
    'label' => 'API Key',
    'config' => [
        'type' => 'user',
        'renderType' => 'vaultSecret',
        'parameters' => [
            'vaultIdentifier' => 'my_extension_{uid}_api_key',
            'showRotateButton' => true,
        ],
    ],
],
```

## CLI Commands

```bash
# Initialize vault (create master key)
vendor/bin/typo3 vault:init

# List secrets (respects access control)
vendor/bin/typo3 vault:list

# Rotate a secret
vendor/bin/typo3 vault:rotate my_secret_id --reason="Scheduled rotation"

# Rotate master key (re-encrypts all DEKs)
vendor/bin/typo3 vault:master-key:rotate --new-key-file=/path/to/new.key

# View audit log
vendor/bin/typo3 vault:audit --identifier=my_secret_id --days=30

# Export secrets (encrypted backup)
vendor/bin/typo3 vault:export --output=secrets.enc
```

## Requirements

- **TYPO3**: v14.0+
- **PHP**: ^8.5
- **Extensions**: `ext-sodium` (bundled with PHP)
- **CPU**: AES-NI support recommended (XChaCha20-Poly1305 fallback available)

## Documentation

- [Architecture](docs/architecture.md)
- [API Reference](docs/api.md)
- [Database Schema](docs/database.md)
- [Security Considerations](docs/security.md)
- [Implementation Plan](docs/implementation-plan.md)
- [Delivery Plan](docs/delivery-plan.md)
- [Use Cases](docs/use-cases.md)

## Feature Comparison

| Feature | nr-vault | Drupal Key | Laravel Secrets | Symfony Secrets |
|---------|----------|------------|-----------------|-----------------|
| Envelope encryption | Yes | No | No | No |
| Per-secret DEKs | Yes | No | No | No |
| External vault support | Pluggable | Pluggable | Limited | HashiCorp |
| Access control | BE groups + context | By key | N/A | N/A |
| Audit logging | Full + hash chain | Limited | None | None |
| TCA/Form integration | Native | Form API | N/A | N/A |
| Key rotation CLI | Yes | Manual | Yes | Yes |
| HTTP client | Yes | No | No | No |
| OAuth auto-refresh | Yes | No | No | No |

## Roadmap

- **Phase 1-5**: Core functionality (current focus)
- **Phase 6**: External adapters (HashiCorp, AWS, Azure) + Optional Rust FFI for zero-PHP-exposure
- **Phase 7**: Service Registry - abstract away both credentials AND endpoints

## License

GPL-2.0-or-later

## Author

Netresearch DTT GmbH
