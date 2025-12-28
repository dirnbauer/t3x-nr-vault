# TYPO3 Secret/Credential Storage Extensions Analysis

**Research Date:** December 27, 2025
**Purpose:** Comprehensive analysis of existing TYPO3 extensions for secret/credential management
**Author:** Research for nr-vault project

---

## Executive Summary

This document analyzes the current landscape of TYPO3 extensions that handle secret storage, credential management, and encryption of sensitive data. The research reveals a **significant gap** in the TYPO3 ecosystem:

- **No dedicated vault integration** exists for TYPO3 (HashiCorp Vault, AWS Secrets Manager, Azure Key Vault)
- Most existing solutions focus on **configuration encryption** rather than runtime secret management
- Available extensions are often **outdated** or have **limited TYPO3 version support**
- The ecosystem lacks a **unified approach** to secret management across extensions

---

## Summary Table

| Extension | Package | TYPO3 Versions | Focus Area | Last Update | Maintenance | Security Rating |
|-----------|---------|----------------|------------|-------------|-------------|-----------------|
| typo3-config-handling | [helhum/typo3-config-handling](https://packagist.org/packages/helhum/typo3-config-handling) | 12.4, 13.4 | Config Encryption | Dec 2025 | Active | Good |
| dotenv-connector | [helhum/dotenv-connector](https://packagist.org/packages/helhum/dotenv-connector) | Any (Composer) | Environment Variables | Sep 2025 | Active | Good |
| Secrets | [wacon/secrets](https://packagist.org/packages/wacon/secrets) | 13.4, 14.0 | One-time Secret Sharing | Dec 2025 | Active | Moderate |
| Guard7 | [sudhaus7/guard7](https://github.com/sudhaus7/guard7) | 7.6, 8.x | Field Encryption (RSA) | ~2018 | Abandoned | Moderate |
| Extbase Encryption | [PeterSchuhmann/extbase_encryption](https://github.com/PeterSchuhmann/extbase_encryption) | 8.7 | Field Encryption | Oct 2018 | Abandoned | Low |
| GPG Admin | [sudhaus7/sudhaus7-gpgadmin](https://packagist.org/packages/sudhaus7/sudhaus7-gpgadmin) | 10, 11, 12.4 | Email Encryption | Aug 2023 | Active | Good |
| Powermail Encrypt | [vancado/vnc-powermail-encrypt](https://packagist.org/packages/vancado/vnc-powermail-encrypt) | 9.5 - 13.4 | Email S/MIME | Mar 2025 | Active | Good |
| nnhelpers | [nng/nnhelpers](https://packagist.org/packages/nng/nnhelpers) | 13.0+ | Utility Encryption | Dec 2025 | Active | Moderate |
| b13/typo3-config | [b13/typo3-config](https://packagist.org/packages/b13/typo3-config) | 11 - 14 | Environment Config | Dec 2025 | Active | Good |
| q3i_mailprivacy | [TER](https://extensions.typo3.org/extension/q3i_mailprivacy) | 6.2, 7 | GPG Mail Forms | Jul 2018 | Abandoned | Low |

---

## Detailed Extension Reviews

### 1. helhum/typo3-config-handling

**Package:** [packagist.org/packages/helhum/typo3-config-handling](https://packagist.org/packages/helhum/typo3-config-handling)
**GitHub:** [github.com/helhum/typo3-config-handling](https://github.com/helhum/typo3-config-handling)
**Version:** 2.1.0 (April 2025)
**Downloads:** 299,798
**Stars:** 36

#### Description
The most mature solution for configuration encryption in TYPO3. Enables environment-specific configuration with encrypted credentials stored in version control.

#### Key Features
- YAML-based configuration files
- Environment variable support via placeholders
- Strong encryption using `defuse/php-encryption`
- CLI commands for encryption/decryption
- Pluggable processor architecture

#### Security Approach
- Uses `defuse/php-encryption` (AES-256, authenticated encryption)
- Encryption key stored separately in target environment
- Values encrypted using `%encrypt(<value>)%` syntax
- Decrypted at runtime via `%decrypt(<encrypted>)%` placeholders

#### Encryption Implementation
```bash
# Encrypt configuration file
typo3cms settings:encrypt -c config/live.yaml

# Key management
SYS.settingsEncryptionKey in override.settings.yaml
```

#### Limitations
- Overrides TYPO3's ConfigurationManager (may miss upstream bugfixes)
- Requires Composer-based TYPO3 installation
- No vault integration (planned for future versions)
- Static encryption key (no rotation mechanism)

#### Verdict
**Recommended** for configuration/credential encryption in modern TYPO3 projects. The de-facto standard for environment-based configuration management.

---

### 2. helhum/dotenv-connector

**Package:** [packagist.org/packages/helhum/dotenv-connector](https://packagist.org/packages/helhum/dotenv-connector)
**Version:** 3.2.0 (September 2025)
**Downloads:** 4,262,262
**Stars:** 157

#### Description
Composer plugin that loads `.env` files at autoload initialization, making environment variables available early in the boot process.

#### Key Features
- Automatic `.env` parsing at Composer autoload time
- Production-safe (respects existing environment)
- Multiple Symfony dotenv version support
- Configurable file paths and adapters

#### Security Approach
- Environment variables for sensitive data (not encryption)
- Follows 12-factor app principles
- `.env` files should be excluded from version control

#### Limitations
- No encryption of values
- Relies on file system security for `.env` protection
- Not suitable for shared hosting environments

#### Verdict
**Essential** for environment variable management but not a security solution itself. Should be combined with proper secret management.

---

### 3. wacon/secrets

**Package:** [packagist.org/packages/wacon/secrets](https://packagist.org/packages/wacon/secrets)
**TER:** [extensions.typo3.org/extension/secrets](https://extensions.typo3.org/extension/secrets)
**GitHub:** [github.com/wacon-internet-gmbh/secrets](https://github.com/wacon-internet-gmbh/secrets)
**Version:** 3.0.1 (December 2025)
**Downloads:** 396 (TER) / 9 (Packagist)

#### Description
Frontend plugin for sharing encrypted messages via one-time links. Designed for organizational secret sharing, not application credential management.

#### Key Features
- One-time secret link generation
- Server-side encryption
- GDPR-compliant (no third-party services)
- Self-hosted on own domain

#### Security Approach
- Custom TypoScript-configured secret key
- Encryption method not documented in detail
- All communication stays on-premises

#### Limitations
- Not for application secret management
- Minimal documentation on encryption implementation
- Low adoption (minimal community engagement)
- Use case is end-user secret sharing, not developer tooling

#### Verdict
**Not suitable** for application credential management. Designed for different use case (sharing secrets with end users).

---

### 4. sudhaus7/guard7

**GitHub:** [github.com/sudhaus7/guard7](https://github.com/sudhaus7/guard7)
**Version:** No formal releases
**Last Activity:** ~2018 (93 commits)

#### Description
Field-level encryption using asymmetric RSA with multiple public keys. Designed for encrypting database fields containing sensitive data.

#### Key Features
- Asymmetric RSA encryption
- Multiple public key support
- AES256 cipher (configurable)
- Backend list module integration
- Password-protected private keys
- PageTS configuration

#### Security Approach
- Public-key cryptography for field encryption
- Private keys stored separately (administrator responsibility)
- Supports IRRE relations (non-MM)
- JavaScript and PHP encryption/decryption tools

#### Encryption Implementation
```typoscript
# Configure encrypted fields via PageTS
mod.guard7 {
  tables {
    tt_address {
      fields = email, name
    }
  }
}
```

#### Limitations
- **Outdated**: Only tested for TYPO3 7.6 and 8.x
- No formal releases or Packagist distribution
- Complex setup with key management challenges
- Unit tests acknowledged as needed (TODO in repo)
- No TYPO3 12/13 support

#### Verdict
**Obsolete** but conceptually valuable. The approach of field-level encryption with asymmetric keys is sound but implementation needs complete rewrite for modern TYPO3.

---

### 5. PeterSchuhmann/extbase_encryption

**GitHub:** [github.com/PeterSchuhmann/extbase_encryption](https://github.com/PeterSchuhmann/extbase_encryption)
**Version:** No releases
**Created:** November 2018

#### Description
Annotation-based encryption for Extbase domain model properties. Uses `@encrypted` docblock annotation to mark fields for automatic encryption.

#### Key Features
- `@encrypted` annotation support
- Automatic encryption/decryption in Extbase
- CLI bulk encrypt/decrypt commands
- Based on Symfony DoctrineEncryptBundle

#### Security Approach
- Extension manager-configured encryption key
- OpenSSL-based encryption (via DoctrineEncryptBundle)
- Requires TEXT database columns for encrypted data

#### Limitations
- **Outdated**: Only tested with TYPO3 8.7
- No key rotation support
- String properties only
- Zero community adoption (0 stars, 0 forks)
- No security policy or disclosure framework
- Encrypted data may corrupt if column too small

#### Verdict
**Not recommended**. Abandoned project with significant security concerns around key management and lack of updates.

---

### 6. sudhaus7/sudhaus7-gpgadmin

**Package:** [packagist.org/packages/sudhaus7/sudhaus7-gpgadmin](https://packagist.org/packages/sudhaus7/sudhaus7-gpgadmin)
**Version:** 4.0.1 (August 2023)
**Downloads:** 1,035

#### Description
TYPO3 extension adding GPG/PGP encryption capabilities for form email submissions.

#### Key Features
- GPG/PGP encrypted email sending
- EXT:form finisher integration
- OpenPGP key management
- Email signing capability

#### Security Approach
- Industry-standard GPG/PGP encryption
- Optional ext-gnupg PHP extension
- PHPStan level 9 code quality

#### Limitations
- Focused only on email encryption
- Not for credential/secret storage
- Requires GPG infrastructure

#### Verdict
**Good** for specific use case of encrypted email forms. Not a general secret management solution.

---

### 7. vancado/vnc-powermail-encrypt

**Package:** [packagist.org/packages/vancado/vnc-powermail-encrypt](https://packagist.org/packages/vancado/vnc-powermail-encrypt)
**Version:** 1.2.0 (March 2025)
**Downloads:** 21,333

#### Description
S/MIME encryption for Powermail recipient emails using certificates.

#### Key Features
- S/MIME certificate-based encryption
- PEM format certificate support
- Per-recipient certificate configuration
- TypoScript-based setup

#### Security Approach
- Industry-standard S/MIME encryption
- Certificate-based key management
- Per-recipient configuration via TypoScript

#### Limitations
- Only for email encryption (not storage)
- Requires certificate infrastructure
- Powermail-specific

#### Verdict
**Good** for S/MIME email encryption with Powermail. Not for general secret management.

---

### 8. nng/nnhelpers (Encrypt Class)

**Package:** [packagist.org/packages/nng/nnhelpers](https://packagist.org/packages/nng/nnhelpers)
**Documentation:** [docs.typo3.org/p/nng/nnhelpers/main/en-us/Helpers/Classes/encrypt.html](https://docs.typo3.org/p/nng/nnhelpers/main/en-us/Helpers/Classes/encrypt.html)
**Version:** 13.1.2 (December 2025)
**Downloads:** 175,475

#### Description
Developer helper library with encryption utilities including encode/decode, password hashing, and JWT handling.

#### Key Features
- `encode()`/`decode()` for reversible encryption
- Password hashing with TYPO3 standard algorithm
- JWT creation and validation
- Session ID hashing
- Installation-specific salting key

#### Security Approach
```php
// Encode/decode for reversible encryption
$encrypted = \nn\t3::Encrypt()->encode('secret data');
$decrypted = \nn\t3::Encrypt()->decode($encrypted);

// Password hashing (non-reversible)
$hash = \nn\t3::Encrypt()->password('password');
$valid = \nn\t3::Encrypt()->checkPassword('password', $hash);
```

- Unique salting key per installation
- Same data encrypts to different values
- JWT signature validation

#### Limitations
- General-purpose helper (not dedicated security)
- Salting key management unclear
- No formal security audit
- Documentation warns JWT payload is base64-readable

#### Verdict
**Useful** for development convenience but not a security-focused solution. Suitable for light encryption needs.

---

### 9. b13/typo3-config

**Package:** [packagist.org/packages/b13/typo3-config](https://packagist.org/packages/b13/typo3-config)
**Version:** 0.2.8 (December 2025)
**Downloads:** 52,703
**Stars:** 17

#### Description
Fluent API for environment-specific TYPO3 configuration with sensible defaults.

#### Key Features
- Context-dependent configuration loading
- DDEV-Local auto-detection
- Helper methods (Mailhog, Redis, etc.)
- Nested context support (Production/QA)

#### Security Approach
- Environment-based configuration
- No encryption features
- Relies on environment variable security

#### Limitations
- No encryption capabilities
- Configuration management only
- Relies on file system security

#### Verdict
**Good** for configuration management but not a security solution.

---

## PHP Libraries for Vault Integration (Not TYPO3-Specific)

No native TYPO3 vault integration exists. However, these PHP libraries could be used to build one:

### mittwald/vault-php

**Package:** [packagist.org/packages/mittwald/vault-php](https://packagist.org/packages/mittwald/vault-php)
**Version:** 3.0.2 (June 2025)
**Downloads:** 80,444
**Stars:** 50

The most mature PHP client for HashiCorp Vault:
- Token, AppRole, UserPass, Kubernetes authentication
- Transit engine support (encrypt/decrypt)
- PSR-18 HTTP client compatibility
- Strong-typed responses
- **Requires PHP 8.3+**

### defuse/php-encryption

**Package:** [packagist.org/packages/defuse/php-encryption](https://packagist.org/packages/defuse/php-encryption)
**Version:** 2.4.0 (June 2023)
**Downloads:** 152,143,063
**Stars:** 3,858

Industry-standard PHP encryption library:
- AES-256 authenticated encryption
- File encryption support
- Password-based key derivation
- **Used by helhum/typo3-config-handling**

---

## Security Assessment

### Encryption Standards Used

| Extension | Encryption Method | Standard | Key Management | Rating |
|-----------|------------------|----------|----------------|--------|
| typo3-config-handling | AES-256 (defuse) | Excellent | Config-based | Good |
| Guard7 | RSA + AES256 | Good | Manual PKI | Moderate |
| Extbase Encryption | OpenSSL (unspecified) | Unknown | Extension config | Poor |
| wacon/secrets | Unknown | Unknown | TypoScript | Unknown |
| GPG Admin | GPG/PGP | Excellent | GPG keyring | Good |
| Powermail Encrypt | S/MIME | Excellent | Certificate | Good |
| nnhelpers | Custom | Unknown | Auto-generated | Moderate |

### Common Security Concerns

1. **Key Storage**: Most extensions store encryption keys in configuration files or extension settings, which may be exposed in backups or version control.

2. **Key Rotation**: No extension provides key rotation mechanisms. Changing keys requires re-encrypting all data.

3. **No Vault Integration**: Complete absence of integration with enterprise secret managers (HashiCorp Vault, AWS Secrets Manager, Azure Key Vault).

4. **Outdated Extensions**: Several promising extensions (Guard7, Extbase Encryption) are abandoned and only support legacy TYPO3 versions.

5. **Audit Status**: No extension has undergone formal security audit.

---

## Ecosystem Gaps Analysis

### Critical Missing Features

1. **HashiCorp Vault Integration**
   - No TYPO3 extension exists for Vault
   - Would require custom development using [mittwald/vault-php](https://packagist.org/packages/mittwald/vault-php)

2. **Cloud Secret Manager Support**
   - No AWS Secrets Manager integration
   - No Azure Key Vault integration
   - No Google Cloud Secret Manager integration

3. **Runtime Secret Injection**
   - Current solutions focus on build-time configuration
   - No dynamic secret retrieval during runtime

4. **Secret Rotation**
   - No automatic rotation support
   - Manual key changes require data re-encryption

5. **Centralized Secret Management**
   - Extensions work in isolation
   - No unified API for extensions to consume secrets

### Recommended Architecture for nr-vault

Based on this research, a new TYPO3 secret management extension should:

1. **Provide Vault Integration**
   - HashiCorp Vault via [mittwald/vault-php](https://github.com/mittwald/vaultPHP)
   - AWS Secrets Manager via AWS SDK
   - Azure Key Vault via Azure SDK

2. **Support Multiple Backends**
   - Environment variables (baseline)
   - Encrypted file storage (using defuse/php-encryption)
   - External vault services

3. **Offer Runtime API**
   - Service for extensions to retrieve secrets
   - Caching layer for performance
   - Lazy loading of secrets

4. **Enable Configuration Integration**
   - TypoScript placeholder support
   - Site configuration integration
   - Extension configuration integration

5. **Implement Security Best Practices**
   - Secret rotation support
   - Audit logging
   - Access control per secret
   - Memory protection for sensitive values

---

## Recommendations

### For Immediate Use

| Need | Recommended Solution |
|------|---------------------|
| Configuration encryption | [helhum/typo3-config-handling](https://packagist.org/packages/helhum/typo3-config-handling) |
| Environment variables | [helhum/dotenv-connector](https://packagist.org/packages/helhum/dotenv-connector) |
| Email encryption (forms) | [sudhaus7/sudhaus7-gpgadmin](https://packagist.org/packages/sudhaus7/sudhaus7-gpgadmin) or [vancado/vnc-powermail-encrypt](https://packagist.org/packages/vancado/vnc-powermail-encrypt) |

### For New Development (nr-vault)

The research confirms a significant opportunity for a dedicated secret management extension that:

1. Fills the vault integration gap
2. Provides modern TYPO3 12/13/14 support
3. Offers unified API for all extensions
4. Supports multiple secret backends
5. Implements industry security standards

---

## References

### Official Documentation
- [TYPO3 Security Guide](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Security/GeneralGuidelines/)
- [TYPO3 Encryption Key Documentation](https://docs.typo3.org/m/typo3/guide-security/8.7/en-us/GuidelinesIntegrators/EncryptionKey/Index.html)
- [TYPO3 Environment Variables](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Deployment/EnvironmentStages/Configuration/Index.html)

### Security Advisories
- [TYPO3-CORE-SA-2024-004: Information Disclosure of Encryption Key](https://typo3.org/security/advisory/typo3-core-sa-2024-004)
- [TYPO3 Extension Security Bulletins](https://typo3.org/help/security-advisories/typo3-extensions)

### Related Projects
- [HashiCorp Vault](https://developer.hashicorp.com/vault)
- [defuse/php-encryption](https://github.com/defuse/php-encryption)
- [Symfony Secrets](https://symfony.com/doc/current/configuration/secrets.html)

---

*Document generated for nr-vault project research*
