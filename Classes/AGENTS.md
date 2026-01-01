# AGENTS.md - Classes

> Source code guidelines for nr-vault.

## Architecture Overview

```
Classes/
├── Adapter/        # Vault backend adapters
├── Audit/          # Audit logging
├── Command/        # CLI commands (Symfony Console)
├── Configuration/  # Extension configuration
├── Controller/     # Backend module controllers (Extbase)
├── Crypto/         # Encryption services (libsodium)
├── Domain/         # Models and repositories
├── Event/          # PSR-14 events
├── EventListener/  # Event listeners
├── Exception/      # Custom exceptions
├── Form/           # FormEngine integration
├── Hook/           # TYPO3 hooks (DataHandler)
├── Http/           # HTTP client for external vaults
├── Security/       # Access control
├── Service/        # Core business logic
├── Task/           # Scheduler tasks
├── TCA/            # TCA field configuration
└── Utility/        # Helper utilities
```

## Design Principles

1. **Interface-driven**: All services have interfaces for testability
2. **Dependency Injection**: Constructor injection via `Services.yaml`
3. **Final by default**: Classes are `final` unless extension is needed
4. **Readonly properties**: Use `readonly` for immutable dependencies
5. **Strict types**: All files use `declare(strict_types=1)`

## Encryption Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Envelope Encryption                │
├─────────────────────────────────────────────────────┤
│  Master Key (MEK)                                   │
│    └── encrypts → Data Encryption Key (DEK)         │
│                      └── encrypts → Secret Value    │
└─────────────────────────────────────────────────────┘
```

- **Algorithm**: AES-256-GCM or XChaCha20-Poly1305 (configurable)
- **Library**: libsodium (PHP native)
- **Key storage**: Environment variable or file

## Service Layer

| Interface | Implementation | Purpose |
|-----------|----------------|---------|
| `VaultServiceInterface` | `VaultService` | Core CRUD operations |
| `EncryptionServiceInterface` | `EncryptionService` | Encrypt/decrypt |
| `MasterKeyProviderInterface` | `MasterKeyProvider` | Master key access |
| `AccessControlServiceInterface` | `AccessControlService` | Permission checks |
| `AuditLogServiceInterface` | `AuditLogService` | Audit trail |
| `VaultAdapterInterface` | `LocalEncryptionAdapter` | Storage backend |

## Adding New Features

### New CLI Command
```php
#[AsCommand(name: 'vault:example', description: 'Example command')]
final class VaultExampleCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Implementation
        return Command::SUCCESS;
    }
}
```
Register in `Configuration/Services.yaml` with `console.command` tag.

### New Event
```php
// Classes/Event/SecretExampleEvent.php
final readonly class SecretExampleEvent
{
    public function __construct(
        public string $identifier,
        public array $context = [],
    ) {}
}

// Dispatch from service
$this->eventDispatcher->dispatch(new SecretExampleEvent($identifier));
```

### New Exception
```php
final class CustomVaultException extends \RuntimeException {}
```
Exceptions don't need DI registration (excluded in Services.yaml).

## Security Patterns

```php
// ALWAYS use constant-time comparison for secrets
if (!hash_equals($expected, $actual)) { ... }

// ALWAYS clear sensitive data from memory
sodium_memzero($plaintext);

// NEVER log secret values
$this->logger->info('Secret accessed', ['identifier' => $identifier]);
// NOT: $this->logger->info('Secret value', ['value' => $secret]);
```

## Testing Approach

- **Unit tests**: Mock all dependencies, test in isolation
- **Functional tests**: Test TYPO3 integration with real database
- Use `#[CoversClass]` attribute for coverage tracking

---

*[n] Netresearch DTT GmbH*
