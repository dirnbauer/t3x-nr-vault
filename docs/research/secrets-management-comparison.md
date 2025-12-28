# Secrets Management Research: CMS Platforms and Industry Solutions

## Executive Summary

This document provides a comprehensive analysis of secrets management approaches across major CMS platforms, dedicated secret management solutions, and industry standards. The goal is to identify patterns and best practices applicable to TYPO3.

---

## Part 1: CMS Platforms

### 1.1 WordPress

**Architecture/Approach:**
- Decentralized approach with multiple storage options
- No built-in secrets management system
- Relies on configuration files, database, or third-party services

**Storage Methods:**
1. **wp-config.php Constants** - Most common approach; API keys defined as PHP constants
2. **Environment Variables** - Server-level configuration accessible via `getenv()`
3. **Database with Options API** - Using `get_option()`/`update_option()` with optional encryption
4. **Third-Party Services** - Integration with AWS Secrets Manager, HashiCorp Vault

**Encryption Methods:**
- No native encryption for secrets
- Plugins may implement AES-256 encryption
- Relies on database encryption at rest

**Key Management:**
- Manual key management
- No built-in key rotation

**Access Control:**
- WordPress capabilities system
- No granular secret-level permissions

**Rotation Capabilities:**
- Manual only
- Best practice: 90-day rotation minimum

**Audit Logging:**
- No native audit logging for secrets
- Requires third-party plugins

**Pros:**
- Simple to implement
- Flexible storage options
- Large ecosystem of plugins

**Cons:**
- No centralized management
- No encryption by default
- No rotation automation
- Limited audit capabilities
- Secrets often end up in version control

**Sources:**
- [Pantheon WordPress Secrets Management](https://docs.pantheon.io/guides/wordpress-developer/wordpress-secrets-management)
- [Felix Arntz - Storing Confidential Data](https://felix-arntz.me/blog/storing-confidential-data-in-wordpress/)
- [PHP Area - Where to Store API Keys](https://phparea.com/blog/where-to-store-api-keys-in-wordpress)

---

### 1.2 Drupal

**Architecture/Approach:**
- Modular approach via the **Key module**
- Abstraction layer between secrets and consuming code
- Support for multiple storage backends

**Key Module Features:**
1. **Configuration Storage** (Development only) - Stores in database
2. **File Storage** - Keys in files outside webroot
3. **Environment Variables** - Server-level storage
4. **External Providers** - Integration with AWS Secrets Manager, Lockr, etc.

**Encryption Methods:**
- Integrates with Encrypt module for AES encryption
- Supports external KMS providers
- Keys never stored in plaintext in database

**Key Management:**
- Centralized via Key module
- Key types: Authentication, Encryption, etc.
- Service class for programmatic access (`key.repository`)

**Access Control:**
- Drupal's permission system
- Per-key access control possible

**Rotation Capabilities:**
- Manual rotation
- External providers may support automation

**Audit Logging:**
- Standard Drupal logging
- Enhanced with external providers

**Pros:**
- Well-designed abstraction layer
- Multiple storage backends
- Good separation of concerns
- Mature ecosystem

**Cons:**
- Requires module installation
- No built-in rotation
- External dependencies for full features

**Sources:**
- [Drupal Key Module](https://www.drupal.org/project/key)
- [DrupalEasy - Securely Store API Credentials](https://www.drupaleasy.com/blogs/ultimike/2023/01/securely-store-api-credentials-drupal-key-module)
- [Drupal.org - Securing Authentication Credentials](https://www.drupal.org/docs/security-in-drupal/securing-authentication-credentials)

---

### 1.3 Laravel

**Architecture/Approach:**
- Environment-based configuration (`.env` files)
- Native encryption services
- Multiple Vault integration packages available

**Native Features:**
1. **Environment Configuration** - `.env` file with `env()` helper
2. **Encryption Service** - `Crypt` facade with AES-256-CBC
3. **Config Caching** - Production optimization

**Vault Integration Packages:**
- `mdma4d/laravel-vault` - HashiCorp Vault integration
- `DionTech/laravel-vault` - Personal vaults per model
- `laravel-vault-suite` - Multi-backend support (Vault, OpenBao)

**Encryption Methods:**
- AES-256-CBC (default)
- AES-128-CBC supported
- Automatic IV generation

**Key Management:**
- `APP_KEY` for encryption
- `php artisan key:generate` for key creation
- Keys stored in `.env`

**Access Control:**
- Application-level only
- No granular secret permissions

**Rotation Capabilities:**
- Manual key rotation
- Vault integration enables automation

**Audit Logging:**
- No native secret auditing
- Vault integration provides logging

**Pros:**
- Simple encryption API
- Good Vault ecosystem
- Clean configuration management

**Cons:**
- `.env` files can leak
- No built-in secret management
- Requires external tools for production

**Sources:**
- [Laravel Vault Suite](https://omar-karray.github.io/laravel-vault-suite/)
- [ElasticScale - HashiCorp Vault with Laravel](https://elasticscale.com/blog/how-to-run-hashicorp-vault-cloud-together-with-laravel/)
- [GitHub - mdma4d/laravel-vault](https://github.com/DionTech/laravel-vault)

---

### 1.4 Symfony

**Architecture/Approach:**
- **Built-in Secrets Vault** using public-key cryptography
- Per-environment vaults (dev, prod)
- Sodium PHP extension required

**Native Secrets System:**
1. **Asymmetric Encryption** - Public/private key pairs
2. **Environment Separation** - Separate vaults per environment
3. **Safe to Commit** - Encrypted files safe for version control
4. **CLI Tools** - `secrets:set`, `secrets:generate-keys`

**Encryption Methods:**
- Libsodium (public-key cryptography)
- Asymmetric key pairs per environment

**Key Management:**
- `secrets:generate-keys` command
- Private key for decryption (production only)
- Public key for encryption (developers)
- `--rotate` option for key rotation

**Access Control:**
- File-system based
- Only those with decrypt key can read

**Rotation Capabilities:**
- Built-in key rotation support
- `secrets:generate-keys --rotate`
- Re-encrypts all secrets with new keys

**Audit Logging:**
- No built-in auditing
- Application-level logging possible

**Deployment Options:**
1. Copy decrypt key to server
2. Set `SYMFONY_DECRYPTION_SECRET` environment variable

**HashiCorp Vault Integration:**
- Use `vault-agent` to populate `.env`
- No PHP code changes required

**Pros:**
- Built-in, well-designed system
- Safe for version control
- Per-environment separation
- Key rotation support
- No external dependencies

**Cons:**
- No centralized management for multi-app
- No audit logging
- Manual process for rotation

**Sources:**
- [Symfony Secrets Documentation](https://symfony.com/doc/current/configuration/secrets.html)
- [SymfonyCasts - The Secrets Vault](https://symfonycasts.com/screencast/symfony6-fundamentals/secrets-vault)
- [Stackademic - Configure Symfony with HashiCorp Vault](https://blog.stackademic.com/configure-symfony-secrets-with-hashicorp-vault-4006d07a2db)

---

### 1.5 Magento / Adobe Commerce

**Architecture/Approach:**
- Centralized encryption key management
- Strong focus on PCI compliance
- AWS integration for cloud deployments

**Encryption Methods:**
- **ChaCha20-Poly1305** with 256-bit key (current)
- **SHA-256** for hashing (non-reversible data)
- **AES-256** for EBS volumes (data at rest)

**Key Management:**
- Single encryption key during installation
- Install Tool for key management
- Key rotation functionality available
- Keys not stored in database

**Data Protection:**
- Credit card data encrypted
- Payment gateway credentials encrypted
- Customer passwords hashed
- Sensitive data encrypted at rest

**Access Control:**
- Two-Factor Authentication (2FA)
- MFA for SSH access
- Complex password policies

**Rotation Capabilities:**
- Built-in encryption key rotation
- Re-encryption of existing data
- Regular rotation recommended

**Audit Logging:**
- Admin action logging
- Integration with cloud logging services

**Cloud Integration:**
- AWS Secrets Manager for credentials
- Automatic secret rotation support
- AWS KMS integration

**Compliance:**
- PCI DSS Level 1 certified
- Comprehensive security features

**Pros:**
- Strong encryption standards
- PCI compliance built-in
- Key rotation support
- Cloud-native options

**Cons:**
- Complex configuration
- Enterprise features require licensing
- Limited flexibility in algorithms

**Sources:**
- [Adobe Commerce Encryption Key](https://experienceleague.adobe.com/en/docs/commerce-admin/systems/security/encryption-key)
- [MGT Commerce Security Features](https://www.mgt-commerce.com/blog/adobe-commerce-security-features/)
- [Adobe Commerce Data Encryption](https://developer.adobe.com/commerce/php/development/security/data-encryption/)

---

## Part 2: Dedicated Secret Management Solutions

### 2.1 HashiCorp Vault

**Architecture:**
- Identity-based secrets and encryption management
- Client-server architecture with REST API
- Storage backend agnostic (Consul, etcd, file, etc.)
- Seal/unseal mechanism for master key protection

**Encryption Methods:**
- AES-256-GCM for data encryption
- Shamir's Secret Sharing for unseal keys
- Transit secrets engine for encryption-as-a-service
- HSM/KMS support for auto-unseal

**Key Management:**
- Hierarchical key structure
- Master key protects encryption key
- Unseal keys protect master key (Shamir splitting)
- Key rotation for all key types
- Enterprise: Key Management Secrets Engine

**Access Control:**
- Policy-based (HCL policies)
- Multiple auth methods (LDAP, OIDC, AWS IAM, Kubernetes, etc.)
- Namespaces for multi-tenancy (Enterprise)
- Entity and groups for identity management

**Rotation Capabilities:**
- Automatic credential rotation for databases
- Dynamic secrets with TTL
- Lease-based access
- Manual rotation for static secrets

**Audit Logging:**
- Comprehensive audit logging
- Multiple audit backends (file, syslog, socket)
- All requests/responses logged
- Supports multiple redundant logs

**Pros:**
- Industry standard
- Extremely flexible
- Dynamic secrets
- Strong access control
- Comprehensive auditing
- Large ecosystem

**Cons:**
- Complex to operate
- Requires dedicated infrastructure
- Learning curve
- Enterprise features require license

**Sources:**
- [HashiCorp Vault Architecture](https://developer.hashicorp.com/vault/docs/internals/architecture)
- [Vault Security Model](https://developer.hashicorp.com/vault/docs/internals/security)
- [What is Vault?](https://developer.hashicorp.com/vault/docs/what-is-vault)

---

### 2.2 AWS Secrets Manager

**Architecture:**
- Fully managed cloud service
- Regional with cross-region replication
- Integrated with AWS IAM
- Lambda-based rotation

**Encryption Methods:**
- AES-256 encryption at rest
- AWS KMS integration
- Customer-managed keys (CMK) supported
- TLS for data in transit
- Unique data key per secret version

**Key Management:**
- AWS KMS managed keys
- Customer-managed KMS keys option
- Automatic key rotation (KMS)
- Data keys never written to disk

**Access Control:**
- IAM policies (identity-based)
- Resource-based policies
- Fine-grained permissions
- Cross-account access possible

**Rotation Capabilities:**
- Automatic rotation (as often as 4 hours)
- Native support for RDS, Redshift, DocumentDB
- Custom Lambda functions for other services
- Single-user and alternating-user strategies
- Managed rotation for supported services

**Audit Logging:**
- AWS CloudTrail integration
- All API calls logged
- CloudWatch Events for monitoring
- AWS Config rules for compliance

**Compliance:**
- SOC, PCI, HIPAA compliant
- AWS Config rules for policy enforcement
- Encryption verification rules

**Pros:**
- Fully managed
- Easy integration with AWS services
- Automatic rotation
- Strong compliance posture
- Cross-region replication

**Cons:**
- AWS lock-in
- Cost per secret/API call
- Limited to AWS ecosystem
- Less flexible than Vault

**Sources:**
- [AWS Secrets Manager Features](https://aws.amazon.com/secrets-manager/features/)
- [AWS Secrets Manager Best Practices](https://docs.aws.amazon.com/secretsmanager/latest/userguide/best-practices.html)
- [AWS Secrets Manager Overview](https://docs.aws.amazon.com/secretsmanager/latest/userguide/intro.html)

---

### 2.3 Azure Key Vault

**Architecture:**
- Cloud-native secrets, keys, and certificates
- Two tiers: Standard (software) and Premium (HSM)
- Managed HSM for highest security
- RESTful API

**Encryption Methods:**
- Standard: FIPS 140 Level 1 (software)
- Premium: FIPS 140-3 Level 3 (HSM-backed)
- Managed HSM: Single-tenant, dedicated HSMs
- AES, RSA, EC algorithms supported

**Key Management:**
- Centralized key management
- HSM-protected keys (Premium)
- Security Domain for HSM ownership
- Keys never leave HSM boundary (Premium)

**Access Control:**
- Azure RBAC for control plane
- Access policies for data plane
- Managed identities integration
- Private endpoints support
- Conditional access

**Rotation Capabilities:**
- Event Grid integration for near-expiry events
- Azure Functions for automated rotation
- Manual rotation supported
- Versioning for key history

**Audit Logging:**
- Azure Monitor integration
- Diagnostic logging
- Azure Security Center integration

**High Availability:**
- Triple-redundant HSM instances
- Automatic failover
- Disaster recovery support

**Pros:**
- Deep Azure integration
- HSM options for compliance
- Managed HSM for sovereignty
- Strong access control

**Cons:**
- Azure ecosystem lock-in
- Complexity with multiple tiers
- Cost for premium features

**Sources:**
- [Azure Key Vault Overview](https://learn.microsoft.com/en-us/azure/key-vault/general/overview)
- [Azure Managed HSM](https://learn.microsoft.com/en-us/azure/key-vault/managed-hsm/overview)
- [Azure Key Vault Keys](https://learn.microsoft.com/en-us/azure/key-vault/keys/about-keys)

---

### 2.4 Google Secret Manager

**Architecture:**
- Google Cloud native service
- Global service with regional replication
- Integration with Cloud IAM
- Version-based secret management

**Encryption Methods:**
- AES-256-bit encryption at rest
- TLS in transit
- Customer-Managed Encryption Keys (CMEK) option
- Google's hardened key management systems

**Key Management:**
- Google-managed keys (default)
- CMEK for customer control
- Automatic key rotation (Google-managed)

**Access Control:**
- Cloud IAM roles and permissions
- Granular resource-level permissions
- Conditional access (time-based, resource-based)
- Least privilege enforcement

**Rotation Capabilities:**
- Version-based management
- Manual rotation with versioning
- Rollback to previous versions
- No automatic rotation (manual process)

**Audit Logging:**
- Cloud Audit Logs integration
- Data access logs (must be enabled)
- Anomaly detection integration
- Logs Explorer access

**Replication:**
- Automatic replication (Google-managed)
- User-managed replication (custom regions)
- High availability built-in

**Pros:**
- Simple to use
- Strong versioning
- Google Cloud integration
- Flexible replication

**Cons:**
- No automatic secret rotation
- GCP ecosystem lock-in
- Data access logging not default

**Sources:**
- [Google Secret Manager Overview](https://docs.cloud.google.com/secret-manager/docs/overview)
- [Secret Manager Encryption](https://docs.cloud.google.com/secret-manager/docs/encryption)
- [Secret Manager Access Control](https://docs.cloud.google.com/secret-manager/docs/access-control)

---

### 2.5 Infisical

**Architecture:**
- Open-source secrets management platform
- Self-hosted or cloud options
- Multi-layer encryption architecture
- MIT license (enterprise features separate)

**Encryption Methods:**
- AES-256-GCM with 96-bit nonces
- Master key backed by operator key
- Hierarchical key structure (master -> KMS -> data keys)
- SRP for authentication
- Public-key cryptography for sharing
- External KMS/HSM integration optional

**Key Management:**
- Multi-layer key hierarchy
- External KMS/HSM support
- Per-project encryption
- Key rotation support

**Access Control:**
- RBAC with additional privileges
- Machine identities (Kubernetes, AWS, Azure, GCP, OIDC)
- Temporary access
- Access requests and approval workflows

**Rotation Capabilities:**
- Secret rotation support
- Point-in-time recovery
- Version history

**Audit Logging:**
- Comprehensive audit logs
- All actions tracked
- Compliance-ready

**Additional Features:**
- Internal CA / PKI
- Certificate lifecycle management
- Secret syncing to platforms
- Infisical Agent for injection

**Compliance:**
- SOC 2 compliant
- HIPAA compliant
- FIPS 140-3 compliant

**Pros:**
- Open-source
- Self-hostable
- Modern architecture
- PKI features
- Good compliance posture

**Cons:**
- Newer solution
- Enterprise features licensed
- Smaller community than Vault

**Sources:**
- [Infisical GitHub](https://github.com/Infisical/infisical)
- [Infisical Security](https://infisical.com/docs/internals/security)
- [Infisical Website](https://infisical.com/)

---

### 2.6 Doppler

**Architecture:**
- Cloud-native SaaS platform
- Centralized secrets management
- CLI and API access
- No self-hosted option

**Encryption Methods:**
- AES-GCM with 256-bit workspace keys
- Random IV per encryption
- HSM-backed key via GCP KMS
- Enterprise Key Management (EKM) option
- TLS 1.2+ minimum

**Key Management:**
- Per-workspace encryption keys
- Keys only in memory during requests
- Enterprise: Customer cloud KMS integration

**Access Control:**
- User roles
- Environment-based access
- Fine-grained permissions
- SSO integration

**Rotation Capabilities:**
- Supports rotation workflows
- No automatic rotation
- Version history

**Audit Logging:**
- Activity logs (who added/edited/deleted)
- Access logs (who accessed, when, how)
- First and last read timestamps

**CLI Features:**
- Local encrypted fallback
- OS keychain credential storage
- Offline capability

**Pros:**
- Easy to use
- Good developer experience
- Strong audit logging
- Quick setup

**Cons:**
- No self-hosting
- No end-to-end encryption
- SaaS dependency
- Vendor lock-in

**Sources:**
- [Doppler Security Fact Sheet](https://docs.doppler.com/docs/security-fact-sheet)
- [Doppler Website](https://www.doppler.com/)
- [Doppler vs Vault](https://thenewstack.io/secrets-management-doppler-or-hashicorp-vault/)

---

## Part 3: Industry Standards

### 3.1 OWASP Guidelines

**Core Principles:**
1. Centralize secret storage, provisioning, auditing, and rotation
2. Control access and prevent leaks
3. Separate environments (dev/prod secrets)
4. Encrypt secrets at rest and in transit

**CI/CD Pipeline Security:**
- Treat CI/CD as production environment
- Implement least-privilege access
- Security event monitoring
- Developers should not have prod secret access

**Secret Storage Best Practices:**
- Never store unencrypted secrets in config files
- Never commit secrets to version control
- Use encryption at rest
- Use purpose-built tools

**Secret Detection:**
- Pre-commit hooks to prevent secrets in code
- Regular scans for secrets in repos
- High-entropy string detection

**Memory Protection:**
- Use mutable structures (byte/char arrays, not Strings)
- Zero memory after use
- Consider process memory encryption

**Auditing Requirements:**
- Audit who requested secrets
- Track approval/rejection
- Log secret usage
- Track expiration attempts
- Monitor auth/authz errors

**Sources:**
- [OWASP Secrets Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Secrets_Management_Cheat_Sheet.html)
- [OWASP Key Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Key_Management_Cheat_Sheet.html)

---

### 3.2 NIST Recommendations (SP 800-57)

**Key Management Framework (Three Parts):**
1. **Part 1 - General**: Basic guidance, algorithms, key types, protection methods
2. **Part 2 - Organizations**: Policy, security planning, documentation
3. **Part 3 - Application**: Guidance for current systems

**Key Principles:**
- Keys must be protected in volatile and persistent memory
- Process keys in secure cryptographic modules when possible
- Never store keys in plaintext
- Store keys in cryptographic vaults (HSM or isolated service)
- Encrypt keys with equal or stronger keys

**Key Lifecycle:**
- Generation
- Establishment
- Storage
- Use
- Destruction

**Key Establishment Techniques:**
1. Asymmetric (public key) algorithms
2. Symmetric (secret key) algorithms
3. Hybrid approaches

**Sources:**
- [NIST SP 800-57 Part 1](https://csrc.nist.gov/pubs/sp/800/57/pt1/r5/final)
- [NIST Key Management Guidelines](https://csrc.nist.gov/projects/key-management/key-management-guidelines)

---

### 3.3 CIS Benchmarks

**Kubernetes Secrets:**
- CIS 5.4.2: Store secrets in external services
- Encrypt secrets at rest in etcd
- Use Vault or AWS Secrets Manager
- Restrict get/list/watch access to secrets
- Apply least-privilege RBAC

**General Guidelines:**
- Level 1: Basic security, minimal impact
- Level 2: Higher security, may affect performance

**Secrets Management Controls:**
- Centralized secret storage
- Encryption at rest and in transit
- Access control and monitoring
- Regular rotation

**Sources:**
- [CIS Benchmarks](https://www.cisecurity.org/cis-benchmarks)
- [Tigera - Kubernetes CIS Benchmark](https://www.tigera.io/learn/guides/kubernetes-security/kubernetes-cis-benchmark/)

---

### 3.4 Compliance Requirements (SOC 2, GDPR)

**SOC 2 Requirements:**
- CC6 - Logical and Physical Access Controls
- Use external secret managers for all credentials
- Never store credentials in code
- Implement RBAC for secrets access
- Detailed, immutable audit logs
- SIEM integration

**GDPR Implications:**
- Personal data encryption required
- Access logging mandatory
- Right to erasure applies to keys accessing personal data
- Breach notification requirements

**Audit Log Requirements:**
- Who accessed, when, what
- Successful and failed attempts
- System and application changes
- Security events
- Immutable logs

**Sources:**
- [Doppler - Secrets Management for Compliance](https://www.doppler.com/blog/devops-guide-to-secrets-management-for-compliance)
- [Secureframe - SOC 2 Requirements](https://secureframe.com/hub/soc-2/requirements)

---

## Part 4: Current TYPO3 Landscape

### 4.1 Existing Capabilities

**Native Features:**
- Global `encryptionKey` in Install Tool (96-character hex)
- Used for checksums and validations
- AES encryption available in extensions

**Extensions:**
- `helhum/typo3-config-handling` - Credential encryption with placeholders
- `nnhelpers` - Encryption helper with per-installation key
- Various payment gateway integrations with encryption

**Limitations:**
- No centralized secrets management
- No built-in secret rotation
- No audit logging for secrets
- Encryption key management is manual
- No external vault integration (native)

### 4.2 Known Security Issues

- TYPO3-CORE-SA-2024-004: Encryption key exposure in Install Tool
- Patched in TYPO3 8.7.57, 9.5.46, 10.4.43, 11.5.35, 12.4.11, 13.0.1

**Sources:**
- [TYPO3 Security Advisory 2024-004](https://typo3.org/security/advisory/typo3-core-sa-2024-004)
- [helhum/typo3-config-handling](https://packagist.org/packages/helhum/typo3-config-handling)

---

## Part 5: Comparative Analysis

### 5.1 Feature Comparison Matrix

| Feature | WordPress | Drupal | Laravel | Symfony | Magento | Vault | AWS SM | Azure KV | GCP SM | Infisical | Doppler |
|---------|-----------|--------|---------|---------|---------|-------|--------|----------|--------|-----------|---------|
| Built-in Secrets Mgmt | No | Module | No | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Encryption at Rest | Plugin | Module | Manual | Yes | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Key Rotation | No | No | No | Yes | Yes | Yes | Yes | Yes | No | Yes | No |
| Automatic Rotation | No | No | No | No | No | Yes | Yes | Yes | No | Yes | No |
| Audit Logging | No | Basic | No | No | Basic | Yes | Yes | Yes | Yes | Yes | Yes |
| External Vault Support | Plugin | Module | Package | Agent | Cloud | N/A | N/A | N/A | N/A | Yes | N/A |
| RBAC for Secrets | No | Basic | No | No | Basic | Yes | Yes | Yes | Yes | Yes | Yes |
| Self-Hostable | N/A | N/A | N/A | N/A | N/A | Yes | No | No | No | Yes | No |
| Dynamic Secrets | No | No | No | No | No | Yes | No | No | No | No | No |

### 5.2 Encryption Standards Comparison

| Solution | Symmetric | Asymmetric | HSM Support | FIPS Compliance |
|----------|-----------|------------|-------------|-----------------|
| Symfony | Sodium | Yes | No | No |
| Magento | ChaCha20-Poly1305 | No | No | No |
| Vault | AES-256-GCM | Yes | Yes | Yes |
| AWS SM | AES-256 | KMS | Yes | Yes |
| Azure KV | AES-256 | RSA/EC | Yes | Yes (Level 3) |
| GCP SM | AES-256 | CMEK | Yes | Yes |
| Infisical | AES-256-GCM | Yes | Yes | Yes |
| Doppler | AES-GCM | No | Yes | No |

---

## Part 6: Recommendations for TYPO3

### 6.1 What Works Well (Learn From)

1. **Drupal Key Module Pattern**
   - Abstract storage from consumption
   - Multiple backend support
   - Service class for programmatic access
   - **Recommendation**: Create a similar abstraction for TYPO3

2. **Symfony Secrets Vault**
   - Per-environment vaults
   - Safe for version control
   - Built-in key rotation
   - **Recommendation**: Consider native asymmetric encryption

3. **Magento Encryption Approach**
   - Strong algorithms (ChaCha20-Poly1305)
   - PCI compliance focus
   - Re-encryption on key rotation
   - **Recommendation**: Adopt modern algorithms

4. **HashiCorp Vault Integration**
   - Standard in Laravel/Symfony via agents
   - No code changes needed
   - **Recommendation**: Support vault-agent pattern

### 6.2 Common Pitfalls to Avoid

1. **WordPress Anti-Patterns**
   - Secrets in wp-config.php committed to VCS
   - No encryption by default
   - No centralized management

2. **Missing Audit Logging**
   - Most CMS platforms lack secret audit trails
   - Critical for compliance

3. **Manual Rotation Only**
   - Error-prone
   - Often skipped

4. **Single Encryption Key**
   - One key for all secrets
   - Compromised key = all secrets exposed

### 6.3 Recommended Architecture for TYPO3

#### Core Components

```
+------------------+
|  Secret Service  |  <- Drupal Key Module inspired API
+------------------+
        |
        v
+------------------+
|  Backend Layer   |  <- Pluggable storage backends
+------------------+
        |
   +----+----+----+----+
   |    |    |    |    |
   v    v    v    v    v
 File  Env  DB  Vault  AWS
```

#### Recommended Features

1. **Multi-Backend Support**
   - Environment variables (development)
   - Encrypted file storage
   - Database with encryption
   - External vaults (Vault, AWS, Azure, GCP)

2. **Encryption Standards**
   - AES-256-GCM or ChaCha20-Poly1305
   - Libsodium for asymmetric operations
   - Support for CMEK
   - HSM integration for enterprise

3. **Key Hierarchy**
   - Master key (protected by unseal mechanism)
   - Data encryption keys (per-secret or per-context)
   - Key derivation for related secrets

4. **Access Control**
   - TYPO3 backend user/group integration
   - Per-secret permissions
   - API token scoping

5. **Rotation Support**
   - Manual rotation with re-encryption
   - Rotation notifications
   - Version history
   - Rollback capability

6. **Audit Logging**
   - All access logged
   - Creation/modification/deletion
   - Failed access attempts
   - Integration with TYPO3 logging

7. **Developer Experience**
   - CLI commands for secret management
   - Configuration placeholders (`%vault(secret-name)%`)
   - Service injection for programmatic access

### 6.4 Implementation Phases

**Phase 1: Foundation**
- Secret service abstraction
- Environment variable backend
- Basic encryption (AES-256-GCM)
- TYPO3 configuration integration

**Phase 2: Enhanced Storage**
- Encrypted file backend
- Database backend with encryption
- Key hierarchy implementation
- Basic audit logging

**Phase 3: External Integration**
- HashiCorp Vault backend
- AWS Secrets Manager backend
- Azure Key Vault backend
- Full audit logging

**Phase 4: Enterprise Features**
- Automatic rotation
- HSM support
- RBAC integration
- Compliance reporting

### 6.5 Technical Recommendations

1. **Use Libsodium** (already in PHP 7.2+)
   - `sodium_crypto_secretbox()` for symmetric
   - `sodium_crypto_box()` for asymmetric
   - Proven, audited, fast

2. **Adopt Symfony Secrets Pattern** for file-based vaults
   - Per-environment separation
   - Safe for VCS

3. **Support Configuration Placeholders**
   ```yaml
   database:
     password: '%secret(db-password)%'
   ```

4. **Provide Migration Tools**
   - Import from environment variables
   - Import from plaintext config
   - Export for backup

5. **Document Security Model**
   - Threat model
   - Trust boundaries
   - Key protection requirements

---

## Conclusion

The research reveals a clear industry trend toward:

1. **Centralized secrets management** with abstraction layers
2. **Encryption at rest and in transit** using modern algorithms
3. **External vault integration** for production environments
4. **Comprehensive audit logging** for compliance
5. **Automatic rotation** to reduce exposure window

TYPO3 should adopt a modular approach similar to Drupal's Key module, with native encryption inspired by Symfony's secrets vault, while providing integration points for enterprise solutions like HashiCorp Vault. This will enable TYPO3 to meet modern security requirements while maintaining backward compatibility and ease of use.

The recommended implementation prioritizes:
- **Security**: Strong encryption, proper key management
- **Flexibility**: Multiple backends, external vault support
- **Usability**: CLI tools, configuration integration
- **Compliance**: Audit logging, rotation support
- **Maintainability**: Clean abstractions, extensible architecture
