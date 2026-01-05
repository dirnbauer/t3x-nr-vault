# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - Unreleased

### Added
- **Core Vault Service**: Secure secrets storage with CRUD operations
- **Envelope Encryption**: AES-256-GCM encryption with per-secret Data Encryption Keys (DEK)
- **Master Key Management**: Support for file-based, environment variable, and derived master keys
- **Access Control**: Backend user and group-based permission system
- **Context-based Scoping**: Organize secrets by context (e.g., "payment", "email")
- **Audit Logging**: Tamper-evident hash chain for all secret operations
- **CLI Commands**: Command-line tools for secret management and key rotation
- **Backend Module**: TYPO3 backend interface for secret management
- **TCA Integration**: Custom `vaultSecret` renderType for TCA fields
- **FlexForm Support**: Vault secrets in FlexForm configurations
- **Vault HTTP Client**: Make authenticated API calls without exposing secrets
- **OAuth 2.0 Support**: Token management with automatic refresh
- **Secret Versioning**: Track secret changes with version history
- **Expiration Support**: Optional expiration dates for secrets
- **Memory Safety**: Automatic wiping of sensitive data with `sodium_memzero()`
- **Enterprise Readiness**: CODEOWNERS, CODE_OF_CONDUCT.md, branch protection
- **Supply Chain Security**: SBOM generation, Cosign signing, SLSA provenance
- **Mermaid Diagrams**: Architecture diagrams in README

### Security
- Envelope encryption prevents master key exposure during normal operations
- Per-secret DEKs limit blast radius of key compromise
- Integrity verification with checksums on encrypted data
- Secure random nonce generation for each encryption operation
- Backend user group-based access control
- Audit trail with tamper-evident hash chain

### Technical
- PHP 8.5+ required
- TYPO3 v14 compatible
- PER Coding Style (latest)
- PHPStan level 10 (maximum)
- PHPat architecture tests
- Mutation testing with Infection
- Readonly classes and properties throughout
- Constructor property promotion
- Modern PHP 8.x patterns (match, named arguments, attributes)

[0.1.0]: https://github.com/netresearch/t3x-nr-vault/commits/main
