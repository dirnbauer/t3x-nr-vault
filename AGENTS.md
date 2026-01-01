# AGENTS.md - nr-vault

> AI agent guidelines for the nr-vault TYPO3 extension.

## Precedence

1. This file (root)
2. Directory-specific AGENTS.md files:
   - `Classes/AGENTS.md` - Source code patterns
   - `Configuration/AGENTS.md` - TYPO3 configuration
   - `Documentation/AGENTS.md` - RST documentation
   - `Resources/AGENTS.md` - Templates, language, assets
   - `Tests/AGENTS.md` - Testing patterns
   - `Tests/E2E/AGENTS.md` - E2E testing specifics
3. TYPO3 coding standards

## Project Overview

**nr-vault** is a TYPO3 v14 extension providing secure secrets management with envelope encryption, access control, and audit logging.

- **Stack**: PHP 8.5, TYPO3 v14, libsodium (AES-256-GCM)
- **Environment**: DDEV for local development
- **License**: GPL-2.0-or-later

## Quick Reference

```bash
# Environment
make up                    # Start DDEV + install TYPO3 v14
make down                  # Stop DDEV
make shell                 # Open container shell

# Testing
make test                  # Run all tests (unit + functional)
make unit                  # Unit tests only
make functional            # Functional tests only

# Quality
make cs                    # Check code style
make fix                   # Fix code style
make phpstan               # Static analysis
make lint                  # PHP syntax check

# CI
make ci                    # Run all CI checks locally

# Documentation
make docs                  # Render documentation
```

## Code Style

- **Standard**: PSR-12 + TYPO3 CGL
- **Tool**: PHP-CS-Fixer (`.php-cs-fixer.dist.php`)
- **Run before commit**: `make fix`

Key conventions:
- `declare(strict_types=1);` in all PHP files
- `final` classes by default (extend only when necessary)
- `readonly` properties where possible
- Constructor property promotion
- No `@author` tags in docblocks
- Use dependency injection via constructor

## Architecture

```
Classes/
├── Adapter/       # Vault backend adapters (local, external)
├── Audit/         # Audit logging service
├── Command/       # CLI commands (vault:init, vault:rotate, etc.)
├── Configuration/ # Extension configuration
├── Controller/    # Backend module controllers
├── Crypto/        # Encryption/decryption services
├── Domain/        # Domain models (Secret, Repository)
├── Event/         # PSR-14 events
├── EventListener/ # Event listeners (TypoScript, SiteConfig)
├── Exception/     # Custom exceptions
├── Form/          # FormEngine integration (NodeFactory, elements)
├── Hook/          # TYPO3 hooks
├── Http/          # HTTP middleware
├── Security/      # Access control services
├── Service/       # Core VaultService
├── Task/          # Scheduler tasks (TCA-based registration)
├── TCA/           # TCA field configuration
└── Utility/       # Helper utilities
```

## Security Requirements

This extension handles sensitive data. Follow these rules:

1. **Never log secrets** - Use `[REDACTED]` placeholders
2. **Constant-time comparisons** - Use `hash_equals()` for secret comparison
3. **Memory clearing** - Use `sodium_memzero()` after processing secrets
4. **No plaintext storage** - All secrets encrypted with envelope encryption
5. **Audit all access** - Every read/write creates an audit log entry
6. **Access control** - Respect backend user groups and ownership

## Testing

| Type | Location | Purpose |
|------|----------|---------|
| Unit | `Tests/Unit/` | Isolated class testing |
| Functional | `Tests/Functional/` | TYPO3 integration |
| E2E | `Tests/E2E/` | Playwright browser tests |
| Architecture | `Tests/Architecture/` | PHPat dependency rules |
| Fuzz | `Tests/Fuzz/` | Input fuzzing |

Run tests in container:
```bash
ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Unit
```

## Pre-Commit Checklist

Before committing:

- [ ] `make fix` - Code style fixed
- [ ] `make phpstan` - No new errors
- [ ] `make test` - All tests pass
- [ ] No secrets in code or tests
- [ ] Audit logging added for new operations

## Commit Format

Use conventional commits:
```
feat: add OAuth token refresh support
fix: prevent timing attacks in secret comparison
refactor: extract encryption to dedicated service
test: add unit tests for OrphanCleanupTask
docs: update TCA integration examples
```

## CLI Commands

```bash
# Initialization
vault:init                  # Initialize vault with master key

# Secret management
vault:store                 # Store a new secret
vault:retrieve              # Retrieve a secret by identifier
vault:delete                # Delete a secret
vault:list                  # List all secrets (identifiers only)
vault:rotate                # Rotate a secret value

# Key management
vault:rotate-master-key     # Rotate the master encryption key

# Maintenance
vault:cleanup-orphans       # Remove orphaned secrets
vault:scan                  # Scan for vault placeholder usage
vault:migrate-field         # Migrate field values to vault
vault:audit                 # View audit log entries
```

## Key Interfaces

```php
// Core vault operations
VaultServiceInterface::store(string $identifier, string $secret, array $options): void
VaultServiceInterface::retrieve(string $identifier): ?string
VaultServiceInterface::rotate(string $identifier, string $newSecret, ?string $reason): void
VaultServiceInterface::delete(string $identifier, ?string $reason): void

// Access control
AccessControlServiceInterface::canRead(Secret $secret): bool
AccessControlServiceInterface::canWrite(Secret $secret): bool
AccessControlServiceInterface::canCreate(): bool

// Encryption
EncryptionServiceInterface::encrypt(string $plaintext, string $dataKey): string
EncryptionServiceInterface::decrypt(string $ciphertext, string $dataKey): string
MasterKeyProviderInterface::getMasterKey(): string

// Audit logging
AuditLogServiceInterface::log(string $operation, string $identifier, array $context): void
```

## When Stuck

1. Check TYPO3 v14 documentation: https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/
2. Review existing tests for patterns
3. Run `make phpstan` for type hints
4. Check audit logs for access issues

## Files to Never Modify

- `composer.lock` (auto-generated)
- `.Build/` (vendor directory)
- `Documentation-GENERATED-temp/` (rendered docs)
- `.php-cs-fixer.cache`

---

*[n] Netresearch DTT GmbH*
