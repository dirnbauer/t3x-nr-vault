# nr-vault: Secure Secrets Management for TYPO3

A TYPO3 extension providing centralized, secure storage for API keys, credentials, and other secrets with encryption at rest, access control, and audit logging.

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

- **Envelope encryption** with AES-256-GCM
- **Master key management** (file, environment variable, or derived)
- **Per-secret access control** via backend user groups
- **Audit logging** of all secret access
- **Key rotation** support for both secrets and master key
- **TCA integration** via custom `vaultSecret` field type
- **Optional external vault adapters** (HashiCorp Vault, AWS Secrets Manager)

## Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    VaultServiceInterface                     │
├─────────────────────────────────────────────────────────────┤
│ store()  retrieve()  rotate()  delete()  list()             │
└─────────────────────────────────────────────────────────────┘
                              │
       ┌──────────────────────┼──────────────────────┐
       ▼                      ▼                      ▼
┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐
│ LocalEncryption │  │ HashiCorpVault  │  │ AWSSecrets      │
│ Adapter         │  │ Adapter         │  │ Adapter         │
│ (DEFAULT)       │  │ (Optional)      │  │ (Optional)      │
└─────────────────┘  └─────────────────┘  └─────────────────┘
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

```php
use Netresearch\NrVault\Service\VaultService;

class MyService
{
    public function __construct(
        private readonly VaultService $vault,
    ) {}

    public function storeApiKey(string $provider, string $apiKey): void
    {
        $this->vault->store(
            identifier: "my_extension_{$provider}_api_key",
            secret: $apiKey,
            options: [
                'owner' => $GLOBALS['BE_USER']->user['uid'],
                'groups' => [1, 2],  // Admin, Editor groups
            ]
        );
    }

    public function getApiKey(string $provider): ?string
    {
        return $this->vault->retrieve("my_extension_{$provider}_api_key");
    }
}
```

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

## Requirements

- TYPO3 v12.4 LTS or v13.x
- PHP 8.1+
- Sodium extension (included in PHP 7.2+)

## Documentation

- [Architecture](docs/architecture.md)
- [API Reference](docs/api.md)
- [Database Schema](docs/database.md)
- [Security Considerations](docs/security.md)
- [External Adapters](docs/adapters.md)
- [Migration Guide](docs/migration.md)

## Comparison with Other Systems

| Feature | nr-vault | Drupal Key | Laravel Secrets |
|---------|----------|------------|-----------------|
| Self-contained | Yes | Yes | Yes |
| External vault support | Pluggable | Pluggable | Limited |
| Access control | BE user groups | By key | N/A |
| Audit logging | Full | Limited | None |
| TCA integration | Native | Form API | N/A |
| Key rotation | CLI + API | Manual | CLI |

## License

GPL-2.0-or-later

## Author

Netresearch DTT GmbH
