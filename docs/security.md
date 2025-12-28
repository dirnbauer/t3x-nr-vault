# nr-vault Security Considerations

## Threat Model

### Assets to Protect

1. **Secret Values**: API keys, passwords, tokens, certificates
2. **Master Key**: Root of trust for all encryption
3. **Data Encryption Keys (DEKs)**: Per-secret encryption keys
4. **Audit Logs**: Evidence of access patterns

### Threat Actors

| Actor | Capability | Mitigation |
|-------|-----------|------------|
| SQL Injection Attacker | Database read/write | Secrets encrypted at rest |
| Database Admin | Full database access | Master key stored outside DB |
| Backup Administrator | Access to backups | Backups contain only encrypted data |
| Compromised BE User | Authenticated backend access | Access control + audit logging |
| Insider (Developer) | Source code access | No hardcoded keys, key derivation |
| Memory Dump Attacker | RAM access during operation | Request-scoped caching only |

## Encryption Details

### Algorithm Selection

| Purpose | Algorithm | Key Size | Notes |
|---------|-----------|----------|-------|
| Secret encryption | AES-256-GCM | 256-bit | AEAD, authenticated encryption |
| DEK encryption | AES-256-GCM | 256-bit | Master key wrapping |
| Key derivation | HKDF-SHA256 | - | For derived master key option |
| Random generation | /dev/urandom | - | Via sodium_crypto_secretbox |

### Why AES-256-GCM?

1. **Authenticated Encryption (AEAD)**: Provides both confidentiality and integrity
2. **Tampering Detection**: Authentication tag detects any modification
3. **Industry Standard**: Used by TLS 1.3, AWS, Google Cloud
4. **Hardware Acceleration**: Intel AES-NI provides fast encryption
5. **PHP Native**: Available via `sodium_crypto_secretbox` (libsodium)

### Envelope Encryption Benefits

```
Master Key (stored outside database)
    ↓ encrypts
DEK (unique per secret, stored encrypted in database)
    ↓ encrypts
Secret Value (stored encrypted in database)
```

**Advantages**:
- Master key exposure is minimized (only touches DEKs)
- Key rotation is fast (re-encrypt DEKs, not all secrets)
- Compromise of one DEK doesn't expose other secrets
- Same pattern used by AWS KMS, Google Cloud KMS, Azure Key Vault

## Master Key Protection

### Priority Order (Most to Least Secure)

#### 1. HSM / Key Management Service (Enterprise)

For high-security environments, use external KMS:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'aws_kms',
    'awsKmsKeyId' => 'arn:aws:kms:eu-west-1:123456789:key/...',
];
```

**Pros**: Hardware-protected, audit trail, key never leaves HSM
**Cons**: Requires cloud service, network latency

#### 2. File-Based (Recommended for Self-Hosted)

```bash
# Generate key
openssl rand -base64 32 > /var/secrets/typo3/nr-vault-master.key

# Restrict permissions
chmod 0400 /var/secrets/typo3/nr-vault-master.key
chown www-data:www-data /var/secrets/typo3/nr-vault-master.key
```

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'file',
    'masterKeyPath' => '/var/secrets/typo3/nr-vault-master.key',
];
```

**Security Requirements**:
- File MUST be outside webroot
- Permissions MUST be 0400 (read-only by web user)
- File MUST NOT be in git repository
- Backup separately from database

#### 3. Environment Variable (Recommended for Containers)

```bash
export NR_VAULT_MASTER_KEY="$(openssl rand -base64 32)"
```

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'env',
    'masterKeyEnvVar' => 'NR_VAULT_MASTER_KEY',
];
```

**Pros**: Works with container orchestration (K8s secrets, Docker secrets)
**Cons**: May appear in process listings, environment dumps

#### 4. Derived Key (Shared Hosting Fallback)

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'derived',
    'derivedKeySaltPath' => '/var/secrets/typo3/vault-salt.key',
];
```

Derives master key from: `TYPO3 encryptionKey + salt file + constant`

**Pros**: Works when file storage is limited
**Cons**: encryptionKey may be in git, weaker security

## Access Control

### Permission Model

```
Secret Access = (User is Owner) OR (User in Allowed Groups) OR (User is System Maintainer)
```

### Backend User Groups

```php
$vault->store('api_key', $secret, [
    'owner' => 1,           // BE user UID
    'groups' => [1, 2, 3],  // Allowed BE group UIDs
]);
```

### Access Check Flow

```php
public function checkAccess(string $identifier, BackendUserAuthentication $user): bool
{
    $secret = $this->secretRepository->findByIdentifier($identifier);

    // Owner always has access
    if ($secret->getOwnerUid() === $user->user['uid']) {
        return true;
    }

    // System maintainers bypass group restrictions
    if ($user->isSystemMaintainer()) {
        return true;
    }

    // Check group membership
    $userGroups = GeneralUtility::intExplode(',', $user->user['usergroup']);
    $allowedGroups = $secret->getAllowedGroupsArray();

    return count(array_intersect($userGroups, $allowedGroups)) > 0;
}
```

### CLI Access

CLI access requires explicit configuration:

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'allowCliAccess' => true,
    'cliAccessGroups' => [1], // Only secrets accessible by these groups
];
```

## Audit Logging

### What Gets Logged

| Action | Details Logged |
|--------|---------------|
| create | identifier, owner, groups |
| read | identifier, accessor |
| update | identifier, accessor |
| delete | identifier, accessor |
| rotate | identifier, accessor |
| access_denied | identifier, denied user, reason |

### What Is NOT Logged

- Secret values (never logged, even encrypted)
- Master key operations details
- Raw DEKs

### Log Immutability

Audit logs should be append-only in production:

```php
// AuditLogService prevents modifications
public function log(...): void
{
    // Always INSERT, never UPDATE or DELETE
    $this->connection->insert('tx_nrvault_audit_log', $data);
}
```

For tamper-evidence, consider:
- External syslog forwarding
- Database triggers preventing DELETE/UPDATE
- Blockchain-style hash chaining

## Attack Mitigations

### SQL Injection

**Threat**: Attacker extracts database contents via SQL injection

**Mitigation**: All secrets encrypted with per-secret DEKs. Attacker would need:
1. Database dump (encrypted values)
2. Master key (stored outside database)
3. Understanding of envelope encryption structure

### Timing Attacks

**Threat**: Attacker measures response times to infer secret existence

**Mitigation**:
- `exists()` method is constant-time (doesn't decrypt)
- `retrieve()` timing varies only on secret length, not existence

### Memory Exposure

**Threat**: Attacker dumps process memory to extract secrets

**Mitigation**:
- Secrets cached only for current request (not in Redis/APCu)
- PHP's garbage collection clears variables
- Consider `sodium_memzero()` for critical implementations

### Backup Security

**Threat**: Attacker accesses database backup

**Mitigation**:
- Backups contain only encrypted secrets
- Master key MUST be backed up separately
- Consider backup encryption at storage level

### Key Rotation

**Threat**: Long-lived keys increase compromise window

**Mitigation**:
- DEK rotation: `VaultService::rotate($identifier, $newValue)`
- Master key rotation: Re-encrypts all DEKs (fast operation)
- Recommended: Rotate master key annually or after personnel changes

## Secure Defaults

nr-vault ships with secure defaults:

| Setting | Default | Reason |
|---------|---------|--------|
| `hashed` TCA | `false` | API keys must be retrievable |
| Cache | Request-only | Secrets never in persistent cache |
| Audit | Enabled | All operations logged |
| Expiry check | Enabled | Expired secrets throw exception |
| Access check | Enabled | Every retrieve() checks permissions |

## Security Checklist

### Installation

- [ ] Master key file has 0400 permissions
- [ ] Master key file is outside webroot
- [ ] Master key file is NOT in version control
- [ ] Master key is backed up separately from database

### Configuration

- [ ] `allowCliAccess` is disabled unless needed
- [ ] `cliAccessGroups` restricts CLI access scope
- [ ] Audit log retention is configured
- [ ] External log forwarding is configured (production)

### Operations

- [ ] Regular audit log review
- [ ] Master key rotation schedule established
- [ ] Secret rotation procedures documented
- [ ] Incident response plan includes vault compromise

### Monitoring

- [ ] Alert on `access_denied` audit events
- [ ] Alert on unusual read patterns
- [ ] Alert on bulk operations
- [ ] Monitor for expired secrets

## Incident Response

### If Database is Compromised

1. Secrets remain protected (encrypted)
2. Rotate master key immediately
3. Audit logs reveal access patterns
4. Consider rotating all secrets as precaution

### If Master Key is Compromised

1. **Immediate**: All secrets must be considered exposed
2. Generate new master key
3. Re-encrypt all DEKs with new key
4. Rotate all secret values
5. Audit access logs for suspicious activity

### If Individual Secret is Compromised

1. Use `VaultService::rotate()` to replace with new value
2. Check audit log for access history
3. Revoke compromised credential at external service
4. Investigate how compromise occurred
