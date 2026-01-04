# nr-vault Architecture Decision Records

This directory contains AI-readable versions of the Architecture Decision Records (ADRs) for the nr-vault TYPO3 extension.

## ADR Index

| ADR | Title | Status |
|-----|-------|--------|
| [ADR-001](ADR-001-uuid-v7-identifiers.md) | UUID v7 for secret identifiers | Accepted |
| [ADR-002](ADR-002-envelope-encryption.md) | Envelope encryption | Accepted |
| [ADR-003](ADR-003-master-key-management.md) | Master key management | Accepted |
| [ADR-004](ADR-004-tca-integration.md) | TCA integration | Accepted |
| [ADR-005](ADR-005-access-control.md) | Access control | Accepted |
| [ADR-006](ADR-006-audit-logging.md) | Audit logging | Accepted |
| [ADR-007](ADR-007-secret-metadata.md) | Secret metadata | Accepted |
| [ADR-008](ADR-008-http-client.md) | HTTP client | Accepted |

## Architecture Overview

nr-vault is a TYPO3 extension for secure secret management with:

- **Envelope encryption** (ADR-002): Two-layer encryption with AES-256-GCM
- **Pluggable key providers** (ADR-003): TYPO3, file, or environment-based master keys
- **TCA integration** (ADR-004): Transparent vault storage for any TCA field
- **Owner/group permissions** (ADR-005): TYPO3 backend user integration
- **Tamper-evident audit logs** (ADR-006): SHA-256 hash chain
- **Separated metadata** (ADR-007): Queryable without decryption
- **Secure HTTP client** (ADR-008): PSR-18 with secret injection

## Key Design Principles

1. **Zero plaintext in database**: Only encrypted values and UUIDs stored
2. **Memory safety**: `sodium_memzero()` clears secrets after use
3. **Standards compliance**: PSR-14 events, PSR-18 HTTP client
4. **TYPO3 integration**: Uses existing users, groups, FormEngine
5. **Audit everything**: Tamper-evident logs for compliance
