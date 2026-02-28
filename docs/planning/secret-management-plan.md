# Secret Management System: Ideal vs Reality Planning Document

## Executive Summary

This document defines the theoretically ideal secret management system, then systematically adapts it to the practical constraints of TYPO3 v13.4+/v14, PHP 8.2+, and real-world hosting environments. Each compromise is documented with clear reasoning, threat assessment, and risk mitigation strategies.

---

## Part 1: The Theoretical Ideal

### 1.1 Perfect Encryption Architecture

```
                    IDEAL ENCRYPTION HIERARCHY

    ┌─────────────────────────────────────────────────────────────┐
    │                  HARDWARE SECURITY MODULE (HSM)              │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │  Root Key Material - Never Extractable                  │ │
    │  │  - FIPS 140-3 Level 3+ certified                        │ │
    │  │  - Tamper-evident/tamper-responsive                     │ │
    │  │  - Cryptographic operations happen inside HSM           │ │
    │  └─────────────────────────────────────────────────────────┘ │
    └─────────────────────────────────────────────────────────────┘
                                │
                                │ Key Wrapping (AES-KWP or similar)
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │              KEY ENCRYPTION KEY (KEK) TIER                   │
    │  - Region/Environment-specific KEKs                          │
    │  - Wrapped by HSM root key                                   │
    │  - Rotatable without re-encrypting all data                  │
    └─────────────────────────────────────────────────────────────┘
                                │
                                │ Key Wrapping
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │              DATA ENCRYPTION KEY (DEK) TIER                  │
    │  - Unique DEK per secret or secret group                     │
    │  - Wrapped by KEK                                            │
    │  - Short-lived (rotated frequently)                          │
    │  - Never persisted in plaintext                              │
    └─────────────────────────────────────────────────────────────┘
                                │
                                │ Authenticated Encryption
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                      SECRET DATA                             │
    │  - Encrypted with DEK using AES-256-GCM                      │
    │  - Additional Authenticated Data (AAD) includes metadata     │
    │  - Unique nonce per encryption                               │
    └─────────────────────────────────────────────────────────────┘
```

#### Why This Is Ideal

| Component | Security Benefit | Implementation Complexity |
|-----------|-----------------|---------------------------|
| HSM Root Key | Key material never exists in software; resistant to memory dumps, cold boot attacks | High: Requires physical HSM or cloud HSM service |
| KEK Tier | Allows key rotation at scale; limits blast radius of KEK compromise | Medium: Additional key management layer |
| Per-Secret DEK | Cryptographic isolation; one compromised DEK = one compromised secret | Low: Standard envelope encryption pattern |
| AES-256-GCM | AEAD provides confidentiality + integrity + authenticity | Low: Native in PHP sodium |

---

### 1.2 Perfect Key Management

```
                       IDEAL KEY LIFECYCLE

    ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
    │ GENERATE │───>│  STORE   │───>│   USE    │───>│  ROTATE  │
    │          │    │          │    │          │    │          │
    │ - HSM    │    │ - HSM    │    │ - HSM    │    │ - Auto   │
    │ - CSPRNG │    │ - Secure │    │ - Audit  │    │ - Crypto │
    │ - Entropy│    │   enclave│    │ - Access │    │   agility│
    └──────────┘    └──────────┘    └──────────┘    └──────────┘
         │                                               │
         │                                               │
         └───────────────────────────────────────────────┘
                              │
                              ▼
                        ┌──────────┐
                        │ DESTROY  │
                        │          │
                        │ - Crypto │
                        │   erase  │
                        │ - Verify │
                        │   destroy│
                        └──────────┘
```

#### Ideal Key Generation

- **Source**: Hardware True Random Number Generator (TRNG) or quantum random
- **Entropy**: Minimum 256 bits of entropy for 256-bit keys
- **Location**: Key material generated inside HSM, never exposed
- **Verification**: Statistical tests on RNG output

#### Ideal Key Storage

- **At Rest**: Key material encrypted by HSM, stored in separate security domain
- **In Memory**: Keys held in protected memory (SGX enclave, ARM TrustZone)
- **Key Separation**: Different keys for different tenants/purposes
- **Backup**: Shamir's Secret Sharing with quorum reconstruction

#### Ideal Key Rotation

- **Automatic**: Time-based rotation (90 days for DEKs, annually for KEKs)
- **Triggered**: On personnel changes, suspected compromise, policy updates
- **Seamless**: Zero-downtime rotation with version tracking
- **Cryptographic Agility**: Ability to switch algorithms without data loss

---

### 1.3 Perfect Access Control

```
                    IDEAL ACCESS CONTROL MODEL

    ┌─────────────────────────────────────────────────────────────┐
    │                    POLICY ENGINE                             │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │  Attribute-Based Access Control (ABAC)                  │ │
    │  │  - User attributes (role, department, clearance)        │ │
    │  │  - Resource attributes (classification, owner, type)    │ │
    │  │  - Environment attributes (time, location, device)      │ │
    │  │  - Action attributes (read, write, delete, admin)       │ │
    │  └─────────────────────────────────────────────────────────┘ │
    └─────────────────────────────────────────────────────────────┘
                                │
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                  MULTI-FACTOR AUTHENTICATION                 │
    │  - Something you know (password/passphrase)                  │
    │  - Something you have (hardware token, smart card)           │
    │  - Something you are (biometric)                             │
    │  - Somewhere you are (geolocation, network)                  │
    └─────────────────────────────────────────────────────────────┘
                                │
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │              JUST-IN-TIME (JIT) ACCESS                       │
    │  - Access granted only when needed                           │
    │  - Time-bounded access windows                               │
    │  - Automatic access revocation                               │
    │  - Approval workflows for sensitive secrets                  │
    └─────────────────────────────────────────────────────────────┘
                                │
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                    BREAK-GLASS ACCESS                        │
    │  - Emergency access with elevated logging                    │
    │  - Requires multi-party authorization                        │
    │  - Triggers immediate security review                        │
    └─────────────────────────────────────────────────────────────┘
```

#### Why ABAC Over RBAC

| Aspect | RBAC | ABAC |
|--------|------|------|
| Granularity | Role-based (coarse) | Attribute-based (fine) |
| Context | Static | Dynamic (time, location) |
| Scalability | Role explosion problem | Scales with attributes |
| Flexibility | Predefined roles | Policy-based decisions |

---

### 1.4 Perfect Audit Logging

```
                    IDEAL AUDIT ARCHITECTURE

    ┌─────────────────────────────────────────────────────────────┐
    │                 IMMUTABLE AUDIT TRAIL                        │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │  Cryptographic Log Integrity                            │ │
    │  │  - Hash chain (each entry includes hash of previous)    │ │
    │  │  - Signed log blocks                                    │ │
    │  │  - Merkle tree for efficient verification               │ │
    │  └─────────────────────────────────────────────────────────┘ │
    └─────────────────────────────────────────────────────────────┘
                                │
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                 TAMPER-EVIDENT STORAGE                       │
    │  - Write-once storage (WORM)                                 │
    │  - Third-party attestation                                   │
    │  - Geographic distribution                                   │
    │  - Legal hold capabilities                                   │
    └─────────────────────────────────────────────────────────────┘
                                │
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │              REAL-TIME MONITORING & ALERTING                 │
    │  - Anomaly detection (ML-based)                              │
    │  - Pattern matching for known attack signatures              │
    │  - Immediate notification channels                           │
    │  - Automated response capabilities                           │
    └─────────────────────────────────────────────────────────────┘
```

#### Ideal Log Entry Structure

```json
{
  "id": "uuid-v7-with-timestamp",
  "timestamp": "2024-01-15T10:30:45.123456789Z",
  "sequence": 12345,
  "previous_hash": "sha256:abc123...",
  "current_hash": "sha256:def456...",
  "signature": "ed25519:...",

  "event": {
    "type": "secret.read",
    "secret_identifier": "api_key_provider_123",
    "secret_version": 3,
    "outcome": "success"
  },

  "actor": {
    "type": "user",
    "id": "user:456",
    "authentication_method": "saml_mfa",
    "session_id": "session:789"
  },

  "context": {
    "ip_address": "192.168.1.100",
    "user_agent": "...",
    "request_id": "req:abc",
    "correlation_id": "corr:xyz",
    "geo_location": "EU-DE",
    "client_application": "myext v2.1.0"
  },

  "access_decision": {
    "policy_version": "2024-01-01",
    "evaluated_rules": ["rule:123", "rule:456"],
    "matched_rule": "rule:456"
  }
}
```

---

### 1.5 Perfect Secret Rotation

```
                    IDEAL ROTATION WORKFLOW

    ┌──────────┐
    │  START   │
    └────┬─────┘
         │
         ▼
    ┌─────────────────────────────────────────────────────────────┐
    │            ROTATION TRIGGER                                  │
    │  - Scheduled (time-based)                                    │
    │  - Event-driven (employee departure, breach, policy)         │
    │  - Manual (admin-initiated)                                  │
    │  - Automated (API from external system)                      │
    └────┬────────────────────────────────────────────────────────┘
         │
         ▼
    ┌─────────────────────────────────────────────────────────────┐
    │            GENERATE NEW SECRET                               │
    │  - For API keys: Call provider API to generate new key       │
    │  - For passwords: Generate cryptographically secure password │
    │  - For certificates: CSR, CA signing, chain validation       │
    └────┬────────────────────────────────────────────────────────┘
         │
         ▼
    ┌─────────────────────────────────────────────────────────────┐
    │            DUAL-WRITE PERIOD                                 │
    │  - Both old and new secrets valid                            │
    │  - Applications gradually migrate to new secret              │
    │  - Monitoring for old secret usage                           │
    └────┬────────────────────────────────────────────────────────┘
         │
         ▼
    ┌─────────────────────────────────────────────────────────────┐
    │            VERIFICATION                                      │
    │  - New secret tested in production                           │
    │  - Health checks pass                                        │
    │  - No errors with new secret                                 │
    └────┬────────────────────────────────────────────────────────┘
         │
         ▼
    ┌─────────────────────────────────────────────────────────────┐
    │            REVOKE OLD SECRET                                 │
    │  - Old secret invalidated at provider                        │
    │  - Old version marked as superseded                          │
    │  - Cryptographic deletion of old encrypted material          │
    └────┬────────────────────────────────────────────────────────┘
         │
         ▼
    ┌──────────┐
    │  DONE    │
    └──────────┘
```

---

### 1.6 Zero-Knowledge Architecture

```
                IDEAL ZERO-KNOWLEDGE DESIGN

    ┌─────────────────────────────────────────────────────────────┐
    │                    CLIENT APPLICATION                        │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │  - Secret decrypted only in client memory               │ │
    │  │  - Server never sees plaintext                          │ │
    │  │  - User holds decryption key material                   │ │
    │  └─────────────────────────────────────────────────────────┘ │
    └─────────────────────────────────────────────────────────────┘
                                │
                                │ Encrypted blob only
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                      SERVER                                  │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │  - Stores encrypted blobs                               │ │
    │  │  - Cannot decrypt (lacks key material)                  │ │
    │  │  - Provides access control & audit                      │ │
    │  │  - Even administrator cannot read secrets               │ │
    │  └─────────────────────────────────────────────────────────┘ │
    └─────────────────────────────────────────────────────────────┘
```

#### True Zero-Knowledge Requirements

1. **Client-Side Encryption**: All encryption/decryption happens in client
2. **Key Derivation**: Key derived from user-held material (password, hardware token)
3. **No Key Escrow**: Server cannot recover keys (user responsible for backup)
4. **Verifiable Computation**: Server can prove it hasn't tampered with encrypted data

---

### 1.7 HSM Integration

```
                    IDEAL HSM DEPLOYMENT

    ┌─────────────────────────────────────────────────────────────┐
    │                  PHYSICAL HSM CLUSTER                        │
    │  ┌──────────┐  ┌──────────┐  ┌──────────┐                   │
    │  │  HSM 1   │  │  HSM 2   │  │  HSM 3   │  (HA/DR)         │
    │  │ Primary  │  │ Standby  │  │ DR Site  │                   │
    │  └──────────┘  └──────────┘  └──────────┘                   │
    │       │             │             │                          │
    │       └─────────────┴─────────────┘                          │
    │                     │                                        │
    │                     ▼                                        │
    │              Synchronous Replication                         │
    └─────────────────────────────────────────────────────────────┘
                                │
                                │ PKCS#11 / KMIP
                                ▼
    ┌─────────────────────────────────────────────────────────────┐
    │                   HSM FUNCTIONS                              │
    │  - Key generation (inside HSM)                               │
    │  - Key wrapping/unwrapping                                   │
    │  - Signing/verification                                      │
    │  - Random number generation                                  │
    │  - Key attestation (prove key properties)                    │
    └─────────────────────────────────────────────────────────────┘
```

#### HSM Compliance Levels

| Level | Description | Use Case |
|-------|-------------|----------|
| FIPS 140-3 Level 1 | Software-only security | Development/Testing |
| FIPS 140-3 Level 2 | Tamper-evident | Standard commercial |
| FIPS 140-3 Level 3 | Tamper-resistant | Financial, Healthcare |
| FIPS 140-3 Level 4 | Environmental protection | Military, Critical infrastructure |

---

## Part 2: TYPO3 v13.4+/v14 Reality Check

### 2.1 What TYPO3 v13.4+/v14 Provides Natively

```
                    TYPO3 v13.4+/v14 SECURITY BASELINE

    ┌─────────────────────────────────────────────────────────────┐
    │                  TYPO3 v13.4+/v14 PROVIDES                          │
    ├─────────────────────────────────────────────────────────────┤
    │  [x] Backend User Authentication                             │
    │  [x] Backend User Groups (RBAC-style)                        │
    │  [x] encryptionKey for general crypto operations             │
    │  [x] Password hashing (Argon2id)                             │
    │  [x] CSRF protection                                         │
    │  [x] XSS protection (Fluid escaping)                         │
    │  [x] Prepared statements (SQL injection prevention)          │
    │  [x] Session security (secure cookies)                       │
    │  [x] Rate limiting (via extensions)                          │
    ├─────────────────────────────────────────────────────────────┤
    │                  TYPO3 v13.4+/v14 DOES NOT PROVIDE                  │
    ├─────────────────────────────────────────────────────────────┤
    │  [ ] Secret encryption at rest                               │
    │  [ ] Secret access control                                   │
    │  [ ] Secret audit logging                                    │
    │  [ ] Key management                                          │
    │  [ ] Secret rotation                                         │
    │  [ ] Zero-knowledge architecture                             │
    │  [ ] HSM integration                                         │
    └─────────────────────────────────────────────────────────────┘
```

#### TYPO3 v13.4+/v14 Crypto Primitives Available

| Primitive | Source | Capability |
|-----------|--------|------------|
| `encryptionKey` | LocalConfiguration.php | 96-char random string for HMAC/derivation |
| Password hashing | Core | Argon2id (one-way, not for secrets) |
| `GeneralUtility::makeInstance()` | Core | DI for services |
| `hash_hmac()` | PHP | HMAC operations |
| Event system | Core | PSR-14 events for extensibility |

**Critical Gap**: TYPO3 has no native secret management. TCA `type=password` hashes values (irreversible) or stores plaintext.

---

### 2.2 PHP 8.2+ Capabilities and Limitations

```
                    PHP 8.2+ CRYPTO CAPABILITIES

    ┌─────────────────────────────────────────────────────────────┐
    │                     AVAILABLE                                │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Libsodium (sodium extension - bundled since PHP 7.2)        │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  sodium_crypto_secretbox()      - XSalsa20-Poly1305  │   │
    │  │  sodium_crypto_aead_*_encrypt() - AES-256-GCM        │   │
    │  │  sodium_crypto_pwhash()         - Argon2id           │   │
    │  │  sodium_crypto_kdf_derive_*()   - Key derivation     │   │
    │  │  sodium_randombytes_buf()       - CSPRNG             │   │
    │  │  sodium_memzero()               - Secure memory wipe │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  OpenSSL (openssl extension)                                 │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  openssl_encrypt/decrypt()      - AES-256-GCM       │   │
    │  │  openssl_random_pseudo_bytes()  - CSPRNG            │   │
    │  │  openssl_pkey_*()               - Asymmetric ops    │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    ├─────────────────────────────────────────────────────────────┤
    │                   NOT AVAILABLE IN PHP                       │
    ├─────────────────────────────────────────────────────────────┤
    │  [ ] HSM integration (no PKCS#11 binding)                    │
    │  [ ] SGX/TrustZone enclaves                                  │
    │  [ ] Memory protection (mlock, secure heap)                  │
    │  [ ] True zero-knowledge proofs                              │
    │  [ ] Key attestation                                         │
    └─────────────────────────────────────────────────────────────┘
```

#### PHP 8.2+ Crypto Recommendations

| Use Case | Recommended | Avoid |
|----------|-------------|-------|
| Symmetric encryption | `sodium_crypto_aead_aes256gcm_encrypt()` | `mcrypt_*`, `openssl_encrypt()` with CBC |
| Random bytes | `random_bytes()` or `sodium_randombytes_buf()` | `rand()`, `mt_rand()`, `uniqid()` |
| Key derivation | `sodium_crypto_pwhash()` | `md5()`, `sha1()`, simple `hash()` |
| Memory safety | `sodium_memzero()` | Hoping GC cleans up |

#### PHP Limitation: No Protected Memory

```php
// PHP CANNOT do this (C-style protected memory):
// mlock(key_material, key_size);  // Pin in RAM, prevent swap
// mprotect(key_material, key_size, PROT_NONE);  // Prevent read

// Best we can do:
$key = sodium_crypto_secretbox_keygen();
// ... use key ...
sodium_memzero($key);  // Overwrite with zeros
unset($key);           // Remove reference
// BUT: PHP's GC may have copied the value elsewhere in memory
```

---

### 2.3 Database Constraints

```
                    DATABASE REALITY

    ┌─────────────────────────────────────────────────────────────┐
    │                  TYPICAL TYPO3 SCENARIOS                     │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Shared Hosting                                              │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - MySQL 5.7 / 8.0                                   │   │
    │  │  - No encryption at rest (host controls)             │   │
    │  │  - Backups may be unencrypted                        │   │
    │  │  - No column-level encryption                        │   │
    │  │  - Limited user permissions                          │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  Managed Cloud                                               │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Aurora/RDS/Cloud SQL                              │   │
    │  │  - Encryption at rest (provider manages)             │   │
    │  │  - Automated backups (encrypted)                     │   │
    │  │  - IAM integration possible                          │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  Self-Managed                                                │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Any database version                              │   │
    │  │  - Full control over encryption                      │   │
    │  │  - Can enable TDE, SSL connections                   │   │
    │  │  - Backup encryption configurable                    │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

#### Database Security Implications

| Scenario | Database Encryption | Our Responsibility |
|----------|--------------------|--------------------|
| Shared hosting | Unlikely | Application-level encryption (mandatory) |
| Managed cloud | Provider handles TDE | Application-level for defense-in-depth |
| Self-managed | Admin choice | Application-level + recommend TDE |

**Key Insight**: We MUST encrypt at application level because we cannot rely on database-level encryption in all scenarios.

---

### 2.4 User Expectation Constraints

```
                    TYPO3 USER PERSONAS

    ┌─────────────────────────────────────────────────────────────┐
    │  PERSONA 1: Small Agency Developer                           │
    ├─────────────────────────────────────────────────────────────┤
    │  - Manages 5-20 TYPO3 sites                                  │
    │  - Shared hosting or small VPS                               │
    │  - Limited security expertise                                │
    │  - Wants "it just works"                                     │
    │  - No dedicated ops team                                     │
    │                                                              │
    │  EXPECTATIONS:                                               │
    │  - Install extension, works immediately                      │
    │  - No complex key management                                 │
    │  - TCA integration                                           │
    │  - Documentation for basics                                  │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  PERSONA 2: Enterprise IT Department                         │
    ├─────────────────────────────────────────────────────────────┤
    │  - Large TYPO3 installation (multi-site)                     │
    │  - Dedicated infrastructure team                             │
    │  - Compliance requirements (GDPR, SOC2)                      │
    │  - Security audits                                           │
    │  - Budget for enterprise tooling                             │
    │                                                              │
    │  EXPECTATIONS:                                               │
    │  - Integration with existing vault (HashiCorp, AWS)          │
    │  - Audit logging for compliance                              │
    │  - Role-based access control                                 │
    │  - Key rotation capabilities                                 │
    │  - Incident response procedures                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  PERSONA 3: Extension Developer                              │
    ├─────────────────────────────────────────────────────────────┤
    │  - Builds extensions that need API keys                      │
    │  - Ships to diverse environments                             │
    │  - Cannot assume any particular infrastructure               │
    │  - Wants simple, reliable API                                │
    │                                                              │
    │  EXPECTATIONS:                                               │
    │  - Simple store/retrieve API                                 │
    │  - Graceful degradation                                      │
    │  - TCA integration for backend forms                         │
    │  - Clear documentation                                       │
    └─────────────────────────────────────────────────────────────┘
```

#### Usability vs Security Trade-off

```
    SECURITY ─────────────────────────────────────────────► USABILITY

    HSM + MFA          File-based key      Derived key        Plaintext
    + ABAC             + Group access      + Basic audit      (BAD!)
    + Full audit

    ████████████████████████████████████████░░░░░░░░░░░░░░░░░░░░░░░░

    Enterprise         Standard            Minimum            Unacceptable
    (Persona 2)        (All)               (Persona 1)
```

---

### 2.5 Hosting Environment Limitations

```
                    HOSTING ENVIRONMENT MATRIX

    ┌─────────────────────────────────────────────────────────────┐
    │                     SHARED HOSTING                           │
    ├─────────────────────────────────────────────────────────────┤
    │  Available:                                                  │
    │  [x] PHP with sodium extension                               │
    │  [x] MySQL database                                          │
    │  [x] File system (web root + limited outside)                │
    │  [x] Environment variables (sometimes)                       │
    │                                                              │
    │  NOT Available:                                              │
    │  [ ] HSM access                                              │
    │  [ ] Custom services (Redis, Vault)                          │
    │  [ ] Root file system access                                 │
    │  [ ] Network configuration                                   │
    │  [ ] Secret management services                              │
    │                                                              │
    │  Constraints:                                                │
    │  - Cannot store files outside limited paths                  │
    │  - Cannot install additional software                        │
    │  - May share IP with other tenants                          │
    │  - Limited PHP extensions                                    │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                       VPS / DEDICATED                        │
    ├─────────────────────────────────────────────────────────────┤
    │  Available:                                                  │
    │  [x] Full file system access                                 │
    │  [x] Install any software                                    │
    │  [x] Configure network/firewall                              │
    │  [x] Environment variables                                   │
    │  [x] Run additional services                                 │
    │                                                              │
    │  NOT Available:                                              │
    │  [ ] HSM (unless dedicated hardware)                         │
    │                                                              │
    │  Possible with effort:                                       │
    │  [~] Self-hosted HashiCorp Vault                            │
    │  [~] Cloud KMS integration                                   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                    KUBERNETES / CLOUD                        │
    ├─────────────────────────────────────────────────────────────┤
    │  Available:                                                  │
    │  [x] Kubernetes Secrets (base64, not encrypted)              │
    │  [x] Cloud provider secret managers                          │
    │  [x] Service mesh (mTLS between pods)                        │
    │  [x] IAM/RBAC integration                                    │
    │  [x] Environment injection                                   │
    │                                                              │
    │  Possible with integration:                                  │
    │  [~] AWS Secrets Manager                                     │
    │  [~] Azure Key Vault                                         │
    │  [~] Google Secret Manager                                   │
    │  [~] HashiCorp Vault                                         │
    │  [~] Cloud HSM (AWS CloudHSM, Azure Dedicated HSM)           │
    └─────────────────────────────────────────────────────────────┘
```

---

### 2.6 Performance Considerations

```
                    PERFORMANCE IMPACT ANALYSIS

    ┌─────────────────────────────────────────────────────────────┐
    │  ENCRYPTION OVERHEAD                                         │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  AES-256-GCM Performance (typical PHP server):               │
    │  - Encrypt 1KB: ~0.02ms                                      │
    │  - Decrypt 1KB: ~0.02ms                                      │
    │  - Key derivation (Argon2id): 50-500ms (tunable)             │
    │                                                              │
    │  Impact on secret retrieval:                                 │
    │  1. Database query: ~1-5ms                                   │
    │  2. DEK decryption: ~0.02ms                                  │
    │  3. Secret decryption: ~0.02ms                               │
    │  4. Access check: ~1-2ms                                     │
    │  5. Audit log write: ~1-5ms                                  │
    │  ─────────────────────────────                               │
    │  Total: ~3-12ms per retrieval                                │
    │                                                              │
    │  Mitigation: Request-scoped caching                          │
    │  - First access: ~5ms                                        │
    │  - Subsequent access (same request): ~0.01ms                 │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  EXTERNAL VAULT OVERHEAD                                     │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  HashiCorp Vault (local):        ~5-20ms per request         │
    │  HashiCorp Vault (network):      ~20-100ms per request       │
    │  AWS Secrets Manager:            ~50-200ms per request       │
    │  Azure Key Vault:                ~50-200ms per request       │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Request-scoped caching (mandatory)                        │
    │  - TTL-based caching (optional, security trade-off)          │
    │  - Batch retrieval where possible                            │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

#### Performance Recommendations

| Scenario | Caching Strategy | Rationale |
|----------|-----------------|-----------|
| Local encryption | Request-scoped only | Low overhead, no persistent cache needed |
| External vault | Request-scoped + optional TTL | High latency requires caching |
| High-security | Request-scoped only | No persistent storage of decrypted secrets |

---

## Part 3: Tiered Implementation Plan

### Overview

```
                    IMPLEMENTATION TIERS

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │   TIER 4: EXTERNAL VAULT INTEGRATION                         │
    │   ┌─────────────────────────────────────────────────────┐   │
    │   │  + HashiCorp Vault adapter                          │   │
    │   │  + AWS Secrets Manager adapter                      │   │
    │   │  + Azure Key Vault adapter                          │   │
    │   │  + Cloud HSM delegation                             │   │
    │   └─────────────────────────────────────────────────────┘   │
    │                                                              │
    │   TIER 3: ENTERPRISE FEATURES                                │
    │   ┌─────────────────────────────────────────────────────┐   │
    │   │  + Master key rotation with zero downtime           │   │
    │   │  + Secret expiration and alerts                     │   │
    │   │  + Advanced audit analytics                         │   │
    │   │  + Multi-site secret scoping                        │   │
    │   │  + Export/import for DR                             │   │
    │   └─────────────────────────────────────────────────────┘   │
    │                                                              │
    │   TIER 2: ENHANCED SECURITY                                  │
    │   ┌─────────────────────────────────────────────────────┐   │
    │   │  + File-based master key                            │   │
    │   │  + Granular access control (groups)                 │   │
    │   │  + Full audit logging                               │   │
    │   │  + Secret versioning                                │   │
    │   │  + CLI tools                                        │   │
    │   └─────────────────────────────────────────────────────┘   │
    │                                                              │
    │   TIER 1: MINIMUM VIABLE SECURITY                            │
    │   ┌─────────────────────────────────────────────────────┐   │
    │   │  + Envelope encryption (AES-256-GCM)                │   │
    │   │  + Derived master key (from encryptionKey + salt)   │   │
    │   │  + Basic access control (owner only)                │   │
    │   │  + Basic audit log (create/read/delete)             │   │
    │   │  + TCA integration                                  │   │
    │   └─────────────────────────────────────────────────────┘   │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

---

### Tier 1: Minimum Viable Secure Solution

**Target Audience**: Shared hosting, small agencies, extension developers

**Goal**: Provide meaningful security improvement over plaintext with zero configuration

#### Components

```
    TIER 1 ARCHITECTURE

    ┌─────────────────────────────────────────────────────────────┐
    │                    VaultService                              │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  store(identifier, secret)                           │   │
    │  │  retrieve(identifier): ?string                       │   │
    │  │  delete(identifier)                                  │   │
    │  │  exists(identifier): bool                            │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘
                                │
                ┌───────────────┼───────────────┐
                ▼               ▼               ▼
    ┌────────────────┐ ┌────────────────┐ ┌────────────────┐
    │ LocalAdapter   │ │ AccessControl  │ │ AuditLog       │
    │ (encryption)   │ │ (owner-based)  │ │ (basic)        │
    └────────────────┘ └────────────────┘ └────────────────┘
                │
                ▼
    ┌────────────────────────────────────────────────────────────┐
    │              DerivedKeyProvider                             │
    │  ┌──────────────────────────────────────────────────────┐  │
    │  │  masterKey = HKDF(                                   │  │
    │  │      encryptionKey ||                                │  │
    │  │      file_salt ||                                    │  │
    │  │      "nr-vault-v1"                                   │  │
    │  │  )                                                   │  │
    │  └──────────────────────────────────────────────────────┘  │
    └────────────────────────────────────────────────────────────┘
```

#### Security Properties

| Property | Implementation | Strength |
|----------|---------------|----------|
| Encryption at rest | AES-256-GCM | Strong |
| Key management | Derived from encryptionKey + salt file | Acceptable |
| Access control | Owner UID only | Basic |
| Audit logging | Create/read/delete events | Basic |
| Key rotation | Manual (Tier 2+) | Not available |

#### Trade-offs

| What We Accept | Why |
|---------------|-----|
| Derived key (not random) | Works on shared hosting without file system access |
| No key rotation | Complexity; users can still rotate secrets |
| Owner-only access | Group access adds complexity; sufficient for many use cases |
| No expiration | Adds maintenance burden; manual rotation preferred |

#### Installation

```bash
composer require netresearch/nr-vault

# Create salt file (one-time setup)
openssl rand -base64 32 > /var/www/html/private/vault-salt.key
chmod 0400 /var/www/html/private/vault-salt.key
```

```php
// Configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'tier' => 1,  // Auto-detect if not specified
    'derivedKeySaltPath' => '/var/www/html/private/vault-salt.key',
];
```

---

### Tier 2: Enhanced Security Features

**Target Audience**: VPS/dedicated hosting, agencies with security requirements

**Goal**: Production-ready security with proper key management and access control

#### Components (adds to Tier 1)

```
    TIER 2 ADDITIONS

    ┌─────────────────────────────────────────────────────────────┐
    │                  FileKeyProvider                             │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Random 256-bit key in file                        │   │
    │  │  - File outside webroot                              │   │
    │  │  - Permissions: 0400                                 │   │
    │  │  - Owner: web server user                            │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              GroupAccessControl                              │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Per-secret group permissions                      │   │
    │  │  - Owner always has access                           │   │
    │  │  - System maintainers bypass                         │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              SecretVersioning                                │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Version number tracked                            │   │
    │  │  - rotate() increments version                       │   │
    │  │  - Old versions not retained (security)              │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                   CLI Tools                                  │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  vault:store      - Store new secret                 │   │
    │  │  vault:retrieve   - Get secret value                 │   │
    │  │  vault:rotate     - Rotate secret                    │   │
    │  │  vault:delete     - Delete secret                    │   │
    │  │  vault:list       - List secrets                     │   │
    │  │  vault:audit      - View audit logs                  │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘
```

#### Security Properties

| Property | Implementation | Strength |
|----------|---------------|----------|
| Encryption at rest | AES-256-GCM | Strong |
| Key management | File-based random key | Strong |
| Access control | Owner + BE groups | Good |
| Audit logging | All operations + context | Good |
| Secret rotation | Via CLI and API | Good |
| Key rotation | DEK rotation only | Acceptable |

#### Installation

```bash
composer require netresearch/nr-vault

# Generate master key (IMPORTANT: backup this file!)
openssl rand -base64 32 > /var/secrets/typo3/nr-vault-master.key
chmod 0400 /var/secrets/typo3/nr-vault-master.key
chown www-data:www-data /var/secrets/typo3/nr-vault-master.key
```

```php
// Configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'tier' => 2,
    'masterKeyProvider' => 'file',
    'masterKeyPath' => '/var/secrets/typo3/nr-vault-master.key',
];
```

---

### Tier 3: Enterprise-Grade Features

**Target Audience**: Enterprise IT, compliance-driven organizations

**Goal**: Comprehensive secret management with rotation, expiration, and advanced auditing

#### Components (adds to Tier 2)

```
    TIER 3 ADDITIONS

    ┌─────────────────────────────────────────────────────────────┐
    │              MasterKeyRotation                               │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Generate new master key                           │   │
    │  │  - Re-encrypt all DEKs (atomic transaction)          │   │
    │  │  - Zero-downtime rotation                            │   │
    │  │  - Old key kept for rollback window                  │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              SecretExpiration                                │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Expiration timestamp per secret                   │   │
    │  │  - Warning alerts before expiration                  │   │
    │  │  - Expired secrets throw exception                   │   │
    │  │  - Scheduler task for cleanup                        │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              AdvancedAudit                                   │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Hash chain for tamper detection                   │   │
    │  │  - External log forwarding (syslog, SIEM)            │   │
    │  │  - Compliance reports (PDF export)                   │   │
    │  │  - Anomaly detection (unusual access patterns)       │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              MultiSiteScoping                                │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Secrets scoped to page tree (pid)                 │   │
    │  │  - Site-specific isolation                           │   │
    │  │  - Cross-site access via explicit permission         │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │              DisasterRecovery                                │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  - Export all secrets (encrypted bundle)             │   │
    │  │  - Import with key validation                        │   │
    │  │  - Cross-environment migration                       │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘
```

#### Security Properties

| Property | Implementation | Strength |
|----------|---------------|----------|
| Encryption at rest | AES-256-GCM + envelope | Strong |
| Key management | File-based + rotation | Strong |
| Access control | Owner + groups + site scope | Strong |
| Audit logging | Hash-chained + forwarding | Strong |
| Secret rotation | Automated + manual | Strong |
| Key rotation | Master key + DEKs | Strong |
| Compliance | Export + retention | Good |

---

### Tier 4: External Vault Integration

**Target Audience**: Cloud-native, enterprise with existing vault infrastructure

**Goal**: Delegate secret storage to specialized vault services

#### Components (adds to Tier 3)

```
    TIER 4 ARCHITECTURE

    ┌─────────────────────────────────────────────────────────────┐
    │                    VaultService                              │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Adapter selection based on configuration            │   │
    │  │  Fallback: Local -> External                         │   │
    │  └──────────────────────────────────────────────────────┘   │
    └─────────────────────────────────────────────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
    ┌────────────────┐ ┌────────────────┐ ┌────────────────┐
    │ LocalAdapter   │ │ HashiCorpVault │ │ AWSSecrets     │
    │ (default)      │ │ Adapter        │ │ Adapter        │
    └────────────────┘ └────────────────┘ └────────────────┘
                             │                   │
                             ▼                   ▼
                    ┌────────────────┐ ┌────────────────┐
                    │ Vault Server   │ │ AWS Secrets    │
                    │ (self/cloud)   │ │ Manager API    │
                    └────────────────┘ └────────────────┘
```

#### Adapter Implementations

**HashiCorp Vault Adapter**

```php
class HashiCorpVaultAdapter implements VaultAdapterInterface
{
    private VaultClient $client;

    public function store(string $identifier, string $secret, array $metadata): void
    {
        $this->client->write("secret/data/typo3/{$identifier}", [
            'data' => [
                'value' => $secret,
                'metadata' => $metadata,
            ],
        ]);
    }

    public function retrieve(string $identifier): ?string
    {
        $response = $this->client->read("secret/data/typo3/{$identifier}");
        return $response['data']['data']['value'] ?? null;
    }
}
```

**AWS Secrets Manager Adapter**

```php
class AwsSecretsAdapter implements VaultAdapterInterface
{
    private SecretsManagerClient $client;

    public function store(string $identifier, string $secret, array $metadata): void
    {
        $this->client->createSecret([
            'Name' => "typo3/{$identifier}",
            'SecretString' => $secret,
            'Tags' => $this->metadataToTags($metadata),
        ]);
    }

    public function retrieve(string $identifier): ?string
    {
        $result = $this->client->getSecretValue([
            'SecretId' => "typo3/{$identifier}",
        ]);
        return $result['SecretString'] ?? null;
    }
}
```

#### Security Properties

| Property | Implementation | Strength |
|----------|---------------|----------|
| Encryption at rest | Delegated to vault | Very Strong |
| Key management | Vault-managed (HSM possible) | Very Strong |
| Access control | Vault policies + TYPO3 | Strong |
| Audit logging | Vault audit + TYPO3 | Very Strong |
| Secret rotation | Vault-native + TYPO3 triggers | Very Strong |
| Key rotation | Vault-managed | Very Strong |
| HA/DR | Vault clustering | Very Strong |

---

## Part 4: Trade-offs and Compromises

### 4.1 What We Will NOT Implement

```
                EXPLICITLY OUT OF SCOPE

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  1. TRUE ZERO-KNOWLEDGE ARCHITECTURE                         │
    │  ───────────────────────────────────                         │
    │  Why not: TYPO3 backend needs to use secrets for API calls.  │
    │           Zero-knowledge requires client-side decryption,    │
    │           which is impossible when the server needs the      │
    │           secret to call external APIs.                      │
    │                                                              │
    │  Trade-off: Server decrypts secrets. Mitigated by:           │
    │           - Request-scoped memory only                       │
    │           - sodium_memzero() after use                       │
    │           - No persistent caching of decrypted values        │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  2. NATIVE HSM INTEGRATION                                   │
    │  ─────────────────────────────                               │
    │  Why not: PHP has no PKCS#11 binding. HSM integration        │
    │           requires native code or external service.          │
    │                                                              │
    │  Alternative: External vault adapters (HashiCorp Vault with  │
    │               HSM backend, AWS CloudHSM via Secrets Manager) │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  3. MULTI-PARTY APPROVAL FOR SECRET ACCESS                   │
    │  ───────────────────────────────────────────                 │
    │  Why not: TYPO3 backend is single-user session. No mechanism │
    │           for "wait for second approver" in request cycle.   │
    │                                                              │
    │  Alternative: Use external vault with approval workflows     │
    │               if this is required.                           │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  4. ATTRIBUTE-BASED ACCESS CONTROL (ABAC)                    │
    │  ─────────────────────────────────────────                   │
    │  Why not: TYPO3's access model is RBAC (user groups).        │
    │           Implementing ABAC would require custom policy      │
    │           engine, UI for policy authoring, and significant   │
    │           complexity.                                        │
    │                                                              │
    │  Trade-off: Use RBAC (owner + groups). Sufficient for most   │
    │             TYPO3 use cases. Enterprise can use external     │
    │             vault for ABAC requirements.                     │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  5. SECRET VERSION HISTORY RETENTION                         │
    │  ─────────────────────────────────────                       │
    │  Why not: Keeping old secret versions is a security risk.    │
    │           Compromised version history exposes all past       │
    │           credentials.                                       │
    │                                                              │
    │  Decision: Rotation overwrites. No history. If rollback is   │
    │            needed, user must have external backup.           │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  6. AUTOMATIC SECRET DISCOVERY                               │
    │  ────────────────────────────────                            │
    │  Why not: Scanning database for "looks like an API key" is   │
    │           unreliable and creates false positives. Cannot     │
    │           distinguish password from API key from random.     │
    │                                                              │
    │  Alternative: Provide migration guide for extension authors  │
    │               to move their secrets to vault.                │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  7. FRONTEND/JAVASCRIPT DECRYPTION                           │
    │  ──────────────────────────────────                          │
    │  Why not: Secrets should never be sent to browser. Exposing  │
    │           decryption capability in JS opens attack surface.  │
    │                                                              │
    │  Decision: All secret operations are server-side only.       │
    │            TCA field shows masked value or "set" indicator.  │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

---

### 4.2 Security vs Usability Trade-offs

```
                TRADE-OFF DECISIONS

    ┌─────────────────────────────────────────────────────────────┐
    │  DECISION 1: Derived Key as Tier 1 Default                   │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Security Cost:                                              │
    │  - Key derived from encryptionKey (may be in git)           │
    │  - Single compromise path if encryptionKey + salt leaked     │
    │                                                              │
    │  Usability Benefit:                                          │
    │  - Works on shared hosting with no file access               │
    │  - Zero configuration for basic security                     │
    │  - Still infinitely better than plaintext                    │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Salt file adds second factor                              │
    │  - Clear upgrade path to Tier 2 (file-based key)             │
    │  - Documentation warns about encryptionKey exposure          │
    │                                                              │
    │  Risk Level: MEDIUM (acceptable for Tier 1)                  │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  DECISION 2: Request-Scoped Caching Only                     │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Security Benefit:                                           │
    │  - Decrypted secrets never in Redis/APCu/files               │
    │  - Memory-only exposure, cleared at request end              │
    │                                                              │
    │  Performance Cost:                                           │
    │  - Each request decrypts needed secrets again                │
    │  - External vault adds latency per request                   │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Local encryption is fast (~5ms per secret)                │
    │  - Batch retrieval API for multiple secrets                  │
    │  - Optional TTL cache for external vaults (user choice)      │
    │                                                              │
    │  Risk Level: LOW (performance acceptable)                    │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  DECISION 3: No Encryption of Metadata                       │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Security Cost:                                              │
    │  - Secret identifiers visible in database                    │
    │  - Metadata (owner, groups, timestamps) visible              │
    │                                                              │
    │  Usability Benefit:                                          │
    │  - Can query/filter secrets by metadata                      │
    │  - Access control checks don't require decryption            │
    │  - Audit logs are readable without key                       │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Use generic identifiers (hash or UUID instead of name)    │
    │  - Value is encrypted (the important part)                   │
    │  - Consider encrypted search (future enhancement)            │
    │                                                              │
    │  Risk Level: LOW (metadata exposure is acceptable)           │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  DECISION 4: RBAC Instead of ABAC                            │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Security Cost:                                              │
    │  - Coarser access control (groups, not attributes)           │
    │  - No time/location-based restrictions                       │
    │                                                              │
    │  Usability Benefit:                                          │
    │  - Aligns with TYPO3's existing model                        │
    │  - Simple to understand and configure                        │
    │  - No policy language to learn                               │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Owner + groups covers 95% of use cases                    │
    │  - External vault provides ABAC if needed                    │
    │  - Extensible via events/middleware                          │
    │                                                              │
    │  Risk Level: LOW (RBAC is sufficient)                        │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  DECISION 5: CLI Access Disabled by Default                  │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Security Benefit:                                           │
    │  - CLI bypass requires explicit opt-in                       │
    │  - Reduces attack surface from shell access                  │
    │                                                              │
    │  Usability Cost:                                             │
    │  - DevOps scripts need configuration change                  │
    │  - Cannot use CLI tools without setup                        │
    │                                                              │
    │  Mitigation:                                                 │
    │  - Single config flag to enable                              │
    │  - Can scope CLI access to specific groups                   │
    │  - Clear documentation                                       │
    │                                                              │
    │  Risk Level: NONE (usability impact minimal)                 │
    └─────────────────────────────────────────────────────────────┘
```

---

### 4.3 Complexity vs Benefit Analysis

| Feature | Implementation Effort | Security Benefit | Verdict |
|---------|----------------------|-----------------|---------|
| AES-256-GCM encryption | Low | High | IMPLEMENT |
| Envelope encryption | Medium | High | IMPLEMENT |
| Per-secret DEKs | Low | Medium | IMPLEMENT |
| File-based master key | Low | High | IMPLEMENT |
| Group access control | Medium | Medium | IMPLEMENT |
| Audit logging | Medium | High | IMPLEMENT |
| Hash-chained logs | High | Medium | TIER 3 |
| Master key rotation | High | Medium | TIER 3 |
| Secret expiration | Medium | Low | TIER 3 |
| HSM integration | Very High | High | TIER 4 (DELEGATE) |
| ABAC policies | Very High | Medium | OUT OF SCOPE |
| Zero-knowledge | Very High | Very High | OUT OF SCOPE |

---

## Part 5: Threat Modeling and Risk Assessment

### 5.1 Threat Model

```
                    THREAT ACTORS

    ┌─────────────────────────────────────────────────────────────┐
    │  EXTERNAL ATTACKERS                                          │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  SQL Injection Attacker                                      │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Read/write database via SQL injection    │   │
    │  │  Goal: Extract API keys, credentials                  │   │
    │  │                                                       │   │
    │  │  Without nr-vault: Full access to plaintext secrets   │   │
    │  │  With nr-vault: Gets encrypted blobs only             │   │
    │  │                                                       │   │
    │  │  Additional attack needed:                            │   │
    │  │  - Obtain master key (file system or memory)          │   │
    │  │  - Brute force encryption (infeasible for AES-256)    │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: HIGH                                 │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  RCE/Shell Access Attacker                                   │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Execute code on server                   │   │
    │  │  Goal: Extract secrets, establish persistence         │   │
    │  │                                                       │   │
    │  │  Without nr-vault: Database query exposes all secrets │   │
    │  │  With nr-vault: Must also read master key file        │   │
    │  │                                                       │   │
    │  │  Mitigation:                                          │   │
    │  │  - Master key file permissions (0400)                 │   │
    │  │  - Key file outside webroot                           │   │
    │  │  - Audit logging reveals access patterns              │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: MEDIUM (defense in depth)            │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  Backup Theft Attacker                                       │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Access to database backups               │   │
    │  │  Goal: Extract historical secrets                     │   │
    │  │                                                       │   │
    │  │  Without nr-vault: All secrets in backup              │   │
    │  │  With nr-vault: Encrypted blobs, useless without key  │   │
    │  │                                                       │   │
    │  │  Requirement:                                         │   │
    │  │  - Master key MUST be backed up separately            │   │
    │  │  - Backup the key with different security controls    │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: HIGH                                 │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────┐
    │  INSIDER THREATS                                             │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  Curious Database Administrator                              │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Full database access                     │   │
    │  │  Goal: View secrets they shouldn't see                │   │
    │  │                                                       │   │
    │  │  Without nr-vault: SELECT * FROM my_table (exposed)   │   │
    │  │  With nr-vault: Encrypted blobs only                  │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: HIGH                                 │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  Privileged Backend User                                     │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Authenticated TYPO3 backend access       │   │
    │  │  Goal: Access secrets outside their scope             │   │
    │  │                                                       │   │
    │  │  Without nr-vault: No access control on secrets       │   │
    │  │  With nr-vault: Group-based access control            │   │
    │  │                                                       │   │
    │  │  Mitigation:                                          │   │
    │  │  - Per-secret group permissions                       │   │
    │  │  - Audit logging of all access                        │   │
    │  │  - Alert on access denial events                      │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: MEDIUM                               │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    │  Malicious Developer                                         │
    │  ┌──────────────────────────────────────────────────────┐   │
    │  │  Capability: Code deployment access                   │   │
    │  │  Goal: Exfiltrate secrets via code changes            │   │
    │  │                                                       │   │
    │  │  Attack vector:                                       │   │
    │  │  - Add code to log secrets on retrieval               │   │
    │  │  - Email secrets to external address                  │   │
    │  │                                                       │   │
    │  │  Mitigation:                                          │   │
    │  │  - Code review (out of scope)                         │   │
    │  │  - Audit logs show which secrets were accessed        │   │
    │  │  - External vault (code can't bypass vault policies)  │   │
    │  │                                                       │   │
    │  │  RISK REDUCTION: LOW (code access is privileged)      │   │
    │  └──────────────────────────────────────────────────────┘   │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

---

### 5.2 Attack Surface Analysis

```
                    ATTACK SURFACE

    ┌─────────────────────────────────────────────────────────────┐
    │  ENTRY POINTS                                                │
    ├─────────────────────────────────────────────────────────────┤
    │                                                              │
    │  1. VaultService API                                         │
    │     - store(), retrieve(), rotate(), delete()                │
    │     - Protected by: Access control, audit logging            │
    │     - Risk: Authorized user accesses wrong secret            │
    │                                                              │
    │  2. TCA vaultSecret field                                    │
    │     - Backend form for secret input                          │
    │     - Protected by: TYPO3 backend auth, CSRF tokens          │
    │     - Risk: XSS in backend (would need other vuln)           │
    │                                                              │
    │  3. CLI commands                                             │
    │     - vault:store, vault:retrieve, etc.                      │
    │     - Protected by: Disabled by default, shell access needed │
    │     - Risk: Shell access already implies compromise          │
    │                                                              │
    │  4. Master key file                                          │
    │     - File containing encryption key                         │
    │     - Protected by: File permissions (0400), location        │
    │     - Risk: File system access exposes all secrets           │
    │                                                              │
    │  5. Database tables                                          │
    │     - tx_nrvault_secret, tx_nrvault_audit_log                │
    │     - Protected by: Application-level encryption             │
    │     - Risk: SQL injection gets encrypted blobs only          │
    │                                                              │
    │  6. Memory during decryption                                 │
    │     - Secrets briefly in PHP memory                          │
    │     - Protected by: Request-scope only, memzero              │
    │     - Risk: Memory dump during request could expose          │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

---

### 5.3 Risk Assessment Matrix

```
                    RISK ASSESSMENT

    Likelihood →     LOW          MEDIUM         HIGH
    Impact ↓     ─────────────────────────────────────────
                 │             │              │             │
       HIGH      │  Master key │  RCE with    │             │
                 │  backup     │  key access  │             │
                 │  exposure   │              │             │
                 │─────────────│──────────────│─────────────│
                 │             │              │             │
       MEDIUM    │  Insider    │  SQL inj.    │             │
                 │  developer  │  (encrypted  │             │
                 │  exfil      │  data only)  │             │
                 │─────────────│──────────────│─────────────│
                 │             │              │             │
       LOW       │  Timing     │  Access      │  Metadata   │
                 │  attacks    │  control     │  exposure   │
                 │             │  bypass      │  in DB      │
                 │─────────────│──────────────│─────────────│


    RISK RESPONSE:

    ┌───────────────────────────────────────────────────────────┐
    │  ACCEPT (Low risk, low impact)                            │
    │  - Metadata exposure in database                          │
    │  - Timing side channels (existence detection)             │
    │                                                           │
    │  MITIGATE (Medium risk)                                   │
    │  - SQL injection: Application-level encryption            │
    │  - Access control bypass: RBAC + audit logging            │
    │  - Insider threat: Audit logs + access reviews            │
    │                                                           │
    │  TRANSFER (High risk, external capability)                │
    │  - HSM requirements: Use external vault                   │
    │  - ABAC requirements: Use HashiCorp Vault                 │
    │                                                           │
    │  AVOID (Unacceptable risk)                                │
    │  - Plaintext secret storage                               │
    │  - Secrets in LocalConfiguration.php/git                  │
    │  - Persistent caching of decrypted secrets                │
    └───────────────────────────────────────────────────────────┘
```

---

### 5.4 Security Invariants

These conditions MUST always hold:

```
                    SECURITY INVARIANTS

    ┌─────────────────────────────────────────────────────────────┐
    │                                                              │
    │  1. SECRETS NEVER STORED IN PLAINTEXT                        │
    │     - Database always contains encrypted blob               │
    │     - Even if encryption fails, store nothing (throw)        │
    │                                                              │
    │  2. MASTER KEY NEVER IN DATABASE                             │
    │     - Key in separate file or environment                    │
    │     - If key were in DB, encryption is pointless             │
    │                                                              │
    │  3. DECRYPTED SECRETS NEVER CACHED PERSISTENTLY              │
    │     - No Redis, APCu, file cache for plaintext               │
    │     - Request-scoped memory only                             │
    │                                                              │
    │  4. EVERY ACCESS IS LOGGED                                   │
    │     - No silent retrieval of secrets                         │
    │     - Audit log is append-only (no deletes in normal ops)    │
    │                                                              │
    │  5. ACCESS CONTROL CHECKED BEFORE DECRYPTION                 │
    │     - Don't decrypt then check permissions                   │
    │     - Fail fast if user lacks access                         │
    │                                                              │
    │  6. CRYPTOGRAPHIC FAILURES ARE FATAL                         │
    │     - Decryption failure = throw exception                   │
    │     - No fallback to plaintext or weak crypto                │
    │                                                              │
    │  7. UNIQUE NONCE PER ENCRYPTION                              │
    │     - Never reuse nonce with same key                        │
    │     - AES-GCM security depends on this                       │
    │                                                              │
    └─────────────────────────────────────────────────────────────┘
```

---

## Part 6: Implementation Decisions Summary

### 6.1 Final Architecture Decision

```
                    FINAL ARCHITECTURE

    ┌─────────────────────────────────────────────────────────────┐
    │                  TYPO3 Application                           │
    │  ┌─────────────────────────────────────────────────────────┐ │
    │  │                    VaultService                         │ │
    │  │  ─────────────────────────────────────────────────────  │ │
    │  │  store() | retrieve() | rotate() | delete() | list()   │ │
    │  └─────────────────────────────────────────────────────────┘ │
    │                              │                               │
    │          ┌───────────────────┼───────────────────┐           │
    │          ▼                   ▼                   ▼           │
    │  ┌────────────────┐ ┌────────────────┐ ┌────────────────┐   │
    │  │ AccessControl  │ │ EncryptionSvc  │ │ AuditLogSvc    │   │
    │  │ Service        │ │                │ │                │   │
    │  └────────────────┘ └────────────────┘ └────────────────┘   │
    │          │                   │                   │           │
    │          │                   ▼                   │           │
    │          │          ┌────────────────┐           │           │
    │          │          │ MasterKeyProv  │           │           │
    │          │          │ (pluggable)    │           │           │
    │          │          └────────────────┘           │           │
    │          │                   │                   │           │
    │          └───────────────────┼───────────────────┘           │
    │                              ▼                               │
    │                    ┌────────────────┐                        │
    │                    │ VaultAdapter   │                        │
    │                    │ (pluggable)    │                        │
    │                    └────────────────┘                        │
    │                              │                               │
    └──────────────────────────────┼───────────────────────────────┘
                                   │
           ┌───────────────────────┼───────────────────────┐
           ▼                       ▼                       ▼
    ┌─────────────┐       ┌─────────────┐       ┌─────────────┐
    │   Local     │       │  HashiCorp  │       │     AWS     │
    │  Database   │       │   Vault     │       │   Secrets   │
    └─────────────┘       └─────────────┘       └─────────────┘
```

---

### 6.2 Technology Choices

| Component | Choice | Rationale |
|-----------|--------|-----------|
| Encryption algorithm | AES-256-GCM via libsodium | Industry standard, hardware acceleration, PHP native |
| Key derivation | HKDF-SHA256 | Standard KDF, deterministic for derived keys |
| Master key storage | File (default), Env, Derived | Flexible for different hosting |
| Random number generation | sodium_randombytes_buf() | CSPRNG from libsodium |
| Memory cleanup | sodium_memzero() | Best effort secure erase |
| Access control | TYPO3 BE groups (RBAC) | Native to TYPO3, familiar to users |
| Audit storage | TYPO3 database table | Simple, queryable, exportable |
| External vault | HTTP APIs | Standard integration pattern |

---

### 6.3 Implementation Priority

```
    IMPLEMENTATION PHASES

    PHASE 1 (MVP - Core Security)
    ────────────────────────────────
    [x] Envelope encryption with AES-256-GCM
    [x] Derived master key provider
    [x] File master key provider
    [x] Basic access control (owner)
    [x] Basic audit logging
    [x] VaultService core API
    [x] TCA vaultSecret field type

    PHASE 2 (Production Ready)
    ────────────────────────────────
    [ ] Group-based access control
    [ ] Full audit logging with context
    [ ] CLI commands
    [ ] Secret versioning
    [ ] Environment variable key provider
    [ ] Backend module for management

    PHASE 3 (Enterprise)
    ────────────────────────────────
    [ ] Master key rotation
    [ ] Secret expiration
    [ ] Audit log hash chain
    [ ] Export/import for DR
    [ ] Multi-site scoping
    [ ] Alert notifications

    PHASE 4 (External Integration)
    ────────────────────────────────
    [ ] HashiCorp Vault adapter
    [ ] AWS Secrets Manager adapter
    [ ] Azure Key Vault adapter
    [ ] Adapter auto-detection
    [ ] Fallback chain support
```

---

## Appendix A: Security Checklist

### Installation Checklist

- [ ] Master key file created with proper permissions (0400)
- [ ] Master key file located outside webroot
- [ ] Master key file NOT in version control
- [ ] Master key backed up to separate secure location
- [ ] Salt file (if using derived key) has proper permissions
- [ ] Sodium extension available in PHP

### Configuration Checklist

- [ ] Appropriate tier selected for environment
- [ ] CLI access disabled unless needed
- [ ] Audit log retention configured
- [ ] Alert notifications configured (Tier 3+)

### Operational Checklist

- [ ] Master key rotation schedule established
- [ ] Secret rotation procedures documented
- [ ] Incident response plan includes vault compromise
- [ ] Regular audit log review process

### Monitoring Checklist

- [ ] Alert on access_denied events
- [ ] Alert on unusual access patterns
- [ ] Monitor for expired secrets
- [ ] Track audit log growth

---

## Appendix B: Glossary

| Term | Definition |
|------|------------|
| **DEK** | Data Encryption Key - per-secret key that encrypts the secret value |
| **KEK** | Key Encryption Key - encrypts DEKs (in advanced architectures) |
| **Master Key** | Root key that encrypts DEKs in our envelope encryption |
| **Envelope Encryption** | Pattern where data is encrypted by DEK, DEK encrypted by master |
| **AEAD** | Authenticated Encryption with Associated Data (e.g., AES-GCM) |
| **HSM** | Hardware Security Module - tamper-resistant crypto device |
| **RBAC** | Role-Based Access Control |
| **ABAC** | Attribute-Based Access Control |
| **Zero-Knowledge** | Architecture where server cannot decrypt user data |
| **CSPRNG** | Cryptographically Secure Pseudo-Random Number Generator |

---

## Document Information

| Field | Value |
|-------|-------|
| Version | 1.0.0 |
| Status | Draft |
| Author | Netresearch DTT GmbH |
| Last Updated | 2024-01 |
| Next Review | Before implementation of each tier |

---

*This document represents the theoretical foundation and practical implementation plan for nr-vault. Each tier builds upon the previous, allowing organizations to choose the security level appropriate for their threat model and operational constraints.*
