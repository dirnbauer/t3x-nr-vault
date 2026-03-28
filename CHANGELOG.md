# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Internationalization**: Translate all backend module templates to use XLF translation keys
- **Help Page**: Add help page with docheader tab menu to backend module

### Changed
- **Performance**: Fix N+1 queries in `VaultService::list()`
- **Performance**: Optimize frontend rendering and database operations
- **Refactoring**: Extract duplicated `generateUuid` and `looksLikeVaultIdentifier` methods

### Fixed
- **Security**: Address critical and high-severity security findings
- **Accessibility**: Improve frontend accessibility and error handling
- **Secret Reveal**: Fix `SecretReveal.js` GET to POST and `EnvironmentMasterKeyProvider` copy-on-write bug
- **Gitleaks**: Allowlist test fixtures and docs in gitleaks config

## [0.4.6] - 2026-03-07

### Added
- **Help Page**: Add help page with docheader tab menu to backend module

## [0.4.5] - 2026-03-07

### Fixed
- **TCA Element**: Implement AJAX reveal and copy for vault secret TCA element

## [0.4.4] - 2026-03-06

### Fixed
- **VaultSecretElement**: Fix missing label, broken form submission, and silent errors
- **CI**: Add `merge_group` trigger to CI workflow
- **README**: Correct broken badges

### Changed
- **Repo Hygiene**: Clean up files that should be gitignored

## [0.4.3] - 2026-03-02

### Fixed
- **TYPO3 v13**: Add Overview submodule for v13 module overview compatibility

## [0.4.2] - 2026-03-01

### Fixed
- **TYPO3 v13**: Use integer values for `f:be.infobox` state for v13 compatibility

## [0.4.1] - 2026-03-01

### Fixed
- **TYPO3 v13**: Use standard TYPO3 XLF label keys for backend modules
- **TYPO3 v13**: Use `tools` parent module for v13 compatibility
- **Documentation**: Fix documentation issues found by analysis

### Changed
- **CI**: Consolidate caller workflows into 4 grouped files

## [0.4.0] - 2026-02-28

### Added
- **Compatibility**: Widen support to PHP 8.2+ and TYPO3 v13.4+
- **CI**: Enable coverage uploads to Codecov
- **CI**: Expand test matrix to PHP 8.2-8.5 and TYPO3 v13.4/v14
- **CodeQL**: Add CodeQL security scanning for actions and JavaScript

### Changed
- **CI**: Migrate to centralized reusable workflows
- **CI**: Harmonize composer script naming to `ci:test:php:*` convention
- **Build**: Move build configs (`phpunit.xml`, `phpstan-baseline.neon`) into `Build/`
- **Licensing**: Add SPDX copyright and license headers to all PHP files
- **OpenSSF**: Improve Scorecard compliance

### Fixed
- **PHP 8.2**: Remove `#[Override]`, typed class constants, and `array_any()` for PHP 8.2 compatibility
- **TYPO3 v13**: Replace TYPO3 v14-only APIs with v13-compatible equivalents
- **TYPO3 v13**: Use `LLL:EXT:` module labels for v13 compatibility
- **PHP 8.5**: Fix MockObject property declarations for PHP 8.5 compatibility
- **i18n**: Localize user-facing hardcoded strings in controllers
- **CI**: Fix SLSA provenance generation and Renovate auto-merge configuration

## [0.3.1] - 2026-01-26

### Added
- **Documentation**: Add Secure Outbound HTTP Client PRD and ADRs
- **CI**: Add dedicated fuzzing workflow for OpenSSF Scorecard

### Changed
- **Code of Conduct**: Update to Contributor Covenant v3.0 and standardize contact methods

### Fixed
- **Security**: Fix scorecard workflow permissions for branch protection check
- **CI**: Use `workflow_run` trigger for SLSA provenance generation
- **OpenSSF**: Improve Scorecard token-permissions and pinned-dependencies

## [0.3.0] - 2026-01-12

### Added
- **CI**: Add TER upload to release workflow
- **Testing**: Enhance `runTests.sh` with mock OAuth, E2E DDEV support, and parallel tests
- **Testing**: Add coverage and E2E test suites to `runTests.sh`
- **Testing**: Support `MOCK_OAUTH_URL` env var in OAuth integration tests
- **Playwright**: Update to Playwright 1.57.0 with parallel execution

### Changed
- **Type Safety**: Replace shaped arrays with typed DTOs throughout codebase
- **Performance**: Enable opcache CLI and JIT for faster test execution
- **Performance**: Enable parallel execution for php-cs-fixer
- **Build**: Simplify Makefile with comprehensive test commands

### Fixed
- **PHPStan**: Add type guards and annotations for PHPStan level 10
- **Tests**: Update functional tests for DTO property access
- **PHPUnit 12**: Add `AllowMockObjectsWithoutExpectations` for PHPUnit 12
- **Codecov**: Improve integration with verification step

## [0.2.0] - 2026-01-09

### Added
- **Documentation**: Document all master key options in Installation
- **Documentation**: Add backend module screenshots
- **CI**: Add SLSA provenance workflow and badge
- **CI**: Add PR quality gates for Code-Review scorecard
- **Badges**: Add Contributor Covenant badge

### Changed
- **Type Safety**: Replace array returns with typed DTOs
- **Documentation**: Improve introduction with compelling value proposition

### Fixed
- **CI**: Remove duplicate Scorecard job from `security.yml`
- **DDEV**: Resolve `network_mode` conflict in mock-oauth-router

## [0.1.1] - 2026-01-08

### Added
- **Testing**: Add comprehensive unit tests to reach 80% coverage
- **Testing**: Add OAuth2 integration tests with mock server
- **Testing**: Add XChaCha20 encryption tests for algorithm coverage
- **Testing**: Add functional tests for repositories and services
- **CI**: Add OpenSSF Scorecard workflow
- **CI**: Add auto-merge workflow for dependency PRs
- **Badges**: Add OpenSSF Scorecard, Best Practices, and Codecov badges

### Changed
- **Supply Chain**: Update cosign to use bundle format for signing
- **OpenSSF**: Improve Scorecard compliance
- **Documentation**: Clarify external vault adapters are planned, not implemented

### Fixed
- **Tests**: Use SQLite-compatible SQL syntax in functional tests
- **Tests**: Resolve test failures and add interfaces for final class mocking

## [0.1.0] - 2026-01-05

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

### Security
- Envelope encryption prevents master key exposure during normal operations
- Per-secret DEKs limit blast radius of key compromise
- Integrity verification with checksums on encrypted data
- Secure random nonce generation for each encryption operation
- Backend user group-based access control
- Audit trail with tamper-evident hash chain

### Technical
- PHP 8.2+ required
- TYPO3 v13.4 / v14 compatible
- PER Coding Style (latest)
- PHPStan level 10 (maximum)
- PHPat architecture tests
- Mutation testing with Infection
- Readonly classes and properties throughout
- Constructor property promotion
- Modern PHP 8.x patterns (match, named arguments, attributes)

[Unreleased]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.6...HEAD
[0.4.6]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.5...v0.4.6
[0.4.5]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.4...v0.4.5
[0.4.4]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.3...v0.4.4
[0.4.3]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.2...v0.4.3
[0.4.2]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.1...v0.4.2
[0.4.1]: https://github.com/netresearch/t3x-nr-vault/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/netresearch/t3x-nr-vault/compare/v0.3.1...v0.4.0
[0.3.1]: https://github.com/netresearch/t3x-nr-vault/compare/v0.3.0...v0.3.1
[0.3.0]: https://github.com/netresearch/t3x-nr-vault/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/netresearch/t3x-nr-vault/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/netresearch/t3x-nr-vault/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/netresearch/t3x-nr-vault/releases/tag/v0.1.0
