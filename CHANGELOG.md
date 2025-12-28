# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial release preparation
- Project documentation files (CONTRIBUTING.md, SECURITY.md)
- Code quality configuration (PHPStan, PHP-CS-Fixer, EditorConfig)

## [1.0.0] - Unreleased

### Added
- **Core Vault Service**: Secure secrets storage with CRUD operations
- **Envelope Encryption**: AES-256-GCM encryption with per-secret Data Encryption Keys (DEK)
- **Master Key Management**: Support for file-based, environment variable, and derived master keys
- **Access Control**: Backend user and group-based permission system
- **Context-based Scoping**: Organize secrets by context (e.g., "payment", "email")
- **Audit Logging**: Tamper-evident hash chain for all secret operations
- **CLI Commands**: Command-line tools for secret management and key rotation
- **Backend Module**: TYPO3 backend interface for secret management
- **Secret Versioning**: Track secret changes with version history
- **Expiration Support**: Optional expiration dates for secrets
- **Memory Safety**: Automatic wiping of sensitive data with `sodium_memzero()`

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
- PSR-12 / PER-CS 2.0 coding standards
- PHPStan level 8 compliance
- Readonly classes and properties throughout
- Constructor property promotion
- Modern PHP 8.x patterns (match, named arguments, attributes)

[Unreleased]: https://github.com/netresearch/t3x-nr-vault/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/netresearch/t3x-nr-vault/releases/tag/v1.0.0
