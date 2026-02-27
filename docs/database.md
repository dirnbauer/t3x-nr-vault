# nr-vault Database Schema

## Overview

nr-vault uses three tables for storing encrypted secrets, access control relationships, and audit logs. The schema follows TYPO3 v14 conventions and supports both the local encryption adapter and external vault adapters.

**Target:** TYPO3 v13.4+ | PHP 8.2+

## Tables

### tx_nrvault_secret

Stores encrypted secrets with metadata for access control.

```sql
CREATE TABLE tx_nrvault_secret (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,

    -- Identification
    identifier varchar(255) DEFAULT '' NOT NULL,
    description text,

    -- Encrypted data (local adapter only)
    encrypted_value mediumblob,
    encrypted_dek text,
    dek_nonce varchar(24) DEFAULT '' NOT NULL,
    value_nonce varchar(24) DEFAULT '' NOT NULL,
    encryption_version int(11) unsigned DEFAULT 1 NOT NULL,

    -- Change detection (without decrypting)
    value_checksum char(64) DEFAULT '' NOT NULL,

    -- Access control
    owner_uid int(11) unsigned DEFAULT 0 NOT NULL,
    allowed_groups text,
    context varchar(50) DEFAULT '' NOT NULL,

    -- Versioning and lifecycle
    version int(11) unsigned DEFAULT 1 NOT NULL,
    expires_at int(11) unsigned DEFAULT 0 NOT NULL,
    last_rotated_at int(11) unsigned DEFAULT 0 NOT NULL,
    metadata text,

    -- Adapter info
    adapter varchar(50) DEFAULT 'local' NOT NULL,
    external_reference varchar(500) DEFAULT '' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    cruser_id int(11) unsigned DEFAULT 0 NOT NULL,
    deleted tinyint(1) unsigned DEFAULT 0 NOT NULL,
    hidden tinyint(1) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY identifier (identifier, deleted),
    KEY owner_uid (owner_uid),
    KEY adapter (adapter),
    KEY expires_at (expires_at),
    KEY context (context),
    KEY expires_cleanup (deleted, expires_at)
);
```

#### Column Details

| Column | Type | Description |
|--------|------|-------------|
| `identifier` | varchar(255) | Unique identifier for the secret (e.g., `myext_api_key_123`) |
| `description` | text | Human-readable description of the secret's purpose |
| `encrypted_value` | mediumblob | The encrypted secret value (AES-256-GCM ciphertext) |
| `encrypted_dek` | text | The Data Encryption Key, encrypted with master key (base64) |
| `dek_nonce` | varchar(24) | Nonce used for DEK encryption (12 bytes base64 = ~16-20 chars) |
| `value_nonce` | varchar(24) | Nonce used for value encryption |
| `encryption_version` | int | Encryption format version (for future algorithm upgrades) |
| `value_checksum` | char(64) | SHA-256 of plaintext for change detection without decryption |
| `owner_uid` | int | Backend user UID who owns this secret |
| `allowed_groups` | text | Comma-separated list of BE group UIDs (or use MM table) |
| `context` | varchar(50) | Permission scoping: `hr`, `marketing`, `payment`, etc. |
| `version` | int | Secret version, incremented on rotation |
| `expires_at` | int | Unix timestamp when secret expires (0 = never) |
| `last_rotated_at` | int | Unix timestamp of last rotation |
| `metadata` | text | JSON-encoded custom metadata |
| `adapter` | varchar(50) | Storage adapter identifier (`local`, `hashicorp`, `aws`, etc.) |
| `external_reference` | varchar(500) | For external adapters: path/key in external vault |

### tx_nrvault_secret_begroups_mm

Many-to-many relationship between secrets and backend user groups for access control.

```sql
CREATE TABLE tx_nrvault_secret_begroups_mm (
    uid_local int(11) unsigned DEFAULT 0 NOT NULL,
    uid_foreign int(11) unsigned DEFAULT 0 NOT NULL,
    sorting int(11) unsigned DEFAULT 0 NOT NULL,
    sorting_foreign int(11) unsigned DEFAULT 0 NOT NULL,

    KEY uid_local (uid_local),
    KEY uid_foreign (uid_foreign)
);
```

#### Column Details

| Column | Type | Description |
|--------|------|-------------|
| `uid_local` | int | UID of the secret (tx_nrvault_secret.uid) |
| `uid_foreign` | int | UID of the backend group (be_groups.uid) |
| `sorting` | int | Sorting from secret's perspective |
| `sorting_foreign` | int | Sorting from group's perspective |

### tx_nrvault_audit_log

Immutable audit trail of all secret operations with tamper-evident hash chain.

```sql
CREATE TABLE tx_nrvault_audit_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT 0 NOT NULL,

    -- What happened
    secret_identifier varchar(255) DEFAULT '' NOT NULL,
    action varchar(50) DEFAULT '' NOT NULL,
    success tinyint(1) unsigned DEFAULT 1 NOT NULL,
    error_message text,
    reason text,

    -- Who did it
    actor_uid int(11) unsigned DEFAULT 0 NOT NULL,
    actor_type varchar(50) DEFAULT '' NOT NULL,
    actor_username varchar(255) DEFAULT '' NOT NULL,
    actor_role varchar(100) DEFAULT '' NOT NULL,

    -- Context
    ip_address varchar(45) DEFAULT '' NOT NULL,
    user_agent varchar(500) DEFAULT '' NOT NULL,
    request_id varchar(100) DEFAULT '' NOT NULL,

    -- Tamper detection (hash chain)
    previous_hash varchar(64) DEFAULT '' NOT NULL,
    entry_hash varchar(64) DEFAULT '' NOT NULL,

    -- Change tracking
    hash_before char(64) DEFAULT '' NOT NULL,
    hash_after char(64) DEFAULT '' NOT NULL,

    -- When
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    -- Additional data (JSON)
    context text,

    PRIMARY KEY (uid),
    KEY secret_identifier (secret_identifier),
    KEY secret_identifier_time (secret_identifier, crdate DESC),
    KEY action (action),
    KEY actor_uid (actor_uid),
    KEY crdate (crdate),
    KEY success (success),
    KEY secret_outcome_time (secret_identifier, success, crdate)
);
```

#### Column Details

| Column | Type | Description |
|--------|------|-------------|
| `secret_identifier` | varchar(255) | The secret that was accessed |
| `action` | varchar(50) | Operation: `create`, `read`, `update`, `delete`, `rotate`, `access_denied`, `http_call` |
| `success` | tinyint | Whether operation succeeded |
| `error_message` | text | Error details if operation failed |
| `reason` | text | Required reason for rotate/delete operations (compliance) |
| `actor_uid` | int | Backend user UID who performed action (0 for CLI/scheduler) |
| `actor_type` | varchar(50) | Context: `backend`, `cli`, `api`, `scheduler` |
| `actor_username` | varchar(255) | Username for display (denormalized for audit immutability) |
| `actor_role` | varchar(100) | User's role/group at time of action |
| `ip_address` | varchar(45) | Request IP address (IPv4 or IPv6) |
| `user_agent` | varchar(500) | HTTP User-Agent header |
| `request_id` | varchar(100) | Unique request identifier for correlation |
| `previous_hash` | varchar(64) | SHA-256 hash of previous log entry (chain) |
| `entry_hash` | varchar(64) | SHA-256 hash of this entry's content |
| `hash_before` | char(64) | Secret's value_checksum before operation |
| `hash_after` | char(64) | Secret's value_checksum after operation |
| `context` | text | Additional JSON context (e.g., which extension triggered the access) |

## Indexes

### tx_nrvault_secret Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| PRIMARY | `uid` | Primary key |
| identifier | `identifier`, `deleted` | Unique secret lookup (allows soft delete) |
| owner_uid | `owner_uid` | Filter by owner |
| adapter | `adapter` | Filter by storage adapter |
| expires_at | `expires_at` | Find expiring secrets |
| context | `context` | Filter by permission context |
| expires_cleanup | `deleted`, `expires_at` | Efficient cleanup queries |

### tx_nrvault_audit_log Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| PRIMARY | `uid` | Primary key |
| secret_identifier | `secret_identifier` | Audit history per secret |
| secret_identifier_time | `secret_identifier`, `crdate DESC` | Recent history per secret |
| action | `action` | Filter by action type |
| actor_uid | `actor_uid` | User's action history |
| crdate | `crdate` | Time-based queries |
| success | `success` | Filter failures |
| secret_outcome_time | `secret_identifier`, `success`, `crdate` | Failed access analysis |

## Hash Chain Implementation

The audit log uses a cryptographic hash chain for tamper detection:

```php
public function calculateEntryHash(array $entry, string $previousHash): string
{
    $payload = json_encode([
        'uid' => $entry['uid'],
        'secret_identifier' => $entry['secret_identifier'],
        'action' => $entry['action'],
        'actor_uid' => $entry['actor_uid'],
        'crdate' => $entry['crdate'],
        'previous_hash' => $previousHash,
    ], JSON_THROW_ON_ERROR);

    return hash('sha256', $payload);
}
```

**Verification:** Walk the chain from oldest to newest, computing expected hashes. Any mismatch indicates tampering.

## Data Flow

### Storing a Secret (Local Adapter)

```
1. VaultService::store('my_api_key', 'secret123', [...])

2. IdentifierValidator::validate('my_api_key')
   → Verify format: alphanumeric + underscores

3. AccessControlService::checkWriteAccess(...)
   → Verify user has permission

4. EncryptionService::encrypt('secret123', 'my_api_key')
   → DEK = random_bytes(32)
   → value_nonce = random_bytes(12)
   → encrypted_value = AES-256-GCM(secret123, DEK, value_nonce, AAD=identifier)
   → dek_nonce = random_bytes(12)
   → encrypted_dek = AES-256-GCM(DEK, master_key, dek_nonce, AAD=identifier)
   → value_checksum = SHA256(secret123)

5. INSERT INTO tx_nrvault_secret (
       identifier = 'my_api_key',
       encrypted_value = <blob>,
       encrypted_dek = <base64>,
       dek_nonce = <base64>,
       value_nonce = <base64>,
       value_checksum = <sha256>,
       owner_uid = 1,
       context = 'payment',
       ...
   )

6. AuditLogService::log('create', 'my_api_key', [...])
   → INSERT with hash chain continuation

7. sodium_memzero($plaintext)
   → Securely wipe from memory
```

### Retrieving a Secret (Local Adapter)

```
1. VaultService::retrieve('my_api_key')

2. AccessControlService::checkReadAccess('my_api_key', $currentUser)
   → Verify user is owner OR in allowed_groups OR has context access

3. Check request-scoped cache
   → Return if already decrypted this request

4. SELECT encrypted_value, encrypted_dek, dek_nonce, value_nonce
   FROM tx_nrvault_secret
   WHERE identifier = 'my_api_key' AND deleted = 0

5. Check expires_at
   → Throw SecretExpiredException if expired

6. EncryptionService::decrypt(encrypted_value, encrypted_dek, ...)
   → DEK = AES-256-GCM_decrypt(encrypted_dek, master_key, dek_nonce, AAD)
   → plaintext = AES-256-GCM_decrypt(encrypted_value, DEK, value_nonce, AAD)
   → sodium_memzero(DEK)

7. Cache in request scope

8. AuditLogService::log('read', 'my_api_key', [...])

9. Return plaintext
   → Caller responsible for sodium_memzero() after use
```

## Storage for External Adapters

When using external vault adapters (HashiCorp, AWS, Azure), the database serves as a metadata store only:

| Column | Local Adapter | External Adapter |
|--------|--------------|------------------|
| `encrypted_value` | Contains ciphertext | NULL |
| `encrypted_dek` | Contains encrypted DEK | NULL |
| `dek_nonce` | Contains nonce | Empty |
| `value_nonce` | Contains nonce | Empty |
| `value_checksum` | SHA-256 of plaintext | Empty (external handles) |
| `external_reference` | Empty | Path in external vault |
| `adapter` | `local` | `hashicorp`, `aws`, `azure` |

Example for HashiCorp Vault:

```sql
INSERT INTO tx_nrvault_secret (
    identifier = 'my_api_key',
    encrypted_value = NULL,
    encrypted_dek = '',
    adapter = 'hashicorp',
    external_reference = 'secret/data/typo3/my_api_key',
    owner_uid = 1,
    context = 'payment'
);
```

The actual secret value is stored in HashiCorp Vault at `secret/data/typo3/my_api_key`.

## Database Protection

### Audit Log Immutability

To prevent tampering via direct SQL access, add database triggers:

```sql
-- Prevent DELETE on audit log
DELIMITER //
CREATE TRIGGER prevent_audit_log_delete
BEFORE DELETE ON tx_nrvault_audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit log deletion not permitted';
END//

-- Prevent UPDATE on audit log
CREATE TRIGGER prevent_audit_log_update
BEFORE UPDATE ON tx_nrvault_audit_log
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Audit log modification not permitted';
END//
DELIMITER ;
```

### Partitioning for Scale

For high-volume installations, partition audit log by month:

```sql
ALTER TABLE tx_nrvault_audit_log
PARTITION BY RANGE (crdate) (
    PARTITION p_2025_01 VALUES LESS THAN (UNIX_TIMESTAMP('2025-02-01')),
    PARTITION p_2025_02 VALUES LESS THAN (UNIX_TIMESTAMP('2025-03-01')),
    -- ... add partitions as needed
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

## Migration

### From Plaintext Storage

If migrating from plaintext API keys in other extensions:

```php
// Migration command example
$plaintextSecrets = $this->connection->select('tx_myext_provider')
    ->where('api_key', '!=', '')
    ->execute();

foreach ($plaintextSecrets as $row) {
    $this->vaultService->store(
        identifier: sprintf('myext_provider_%d_api_key', $row['uid']),
        secret: $row['api_key'],
        options: [
            'owner' => $row['cruser_id'],
            'metadata' => ['migrated_from' => 'tx_myext_provider', 'original_uid' => $row['uid']],
        ]
    );

    // Optionally clear plaintext after migration
    $this->connection->update('tx_myext_provider', ['api_key' => ''], ['uid' => $row['uid']]);
}
```

### Master Key Rotation

When rotating the master key, all DEKs must be re-encrypted:

```php
// Handled by vault:master-key:rotate command
// Process in batches to avoid long locks:

$batchSize = 100;
$lastUid = 0;

while ($batch = $this->getNextBatch($lastUid, $batchSize)) {
    $this->connection->beginTransaction();

    foreach ($batch as $secret) {
        // 1. Decrypt DEK with old master key
        $dek = $this->encryption->decryptDek($secret['encrypted_dek'], $oldKey, $secret['dek_nonce']);

        // 2. Re-encrypt DEK with new master key
        $newNonce = random_bytes(12);
        $newEncryptedDek = $this->encryption->encryptDek($dek, $newKey, $newNonce);

        // 3. Update record
        $this->connection->update('tx_nrvault_secret', [
            'encrypted_dek' => $newEncryptedDek,
            'dek_nonce' => base64_encode($newNonce),
            'encryption_version' => $secret['encryption_version'] + 1,
        ], ['uid' => $secret['uid']]);

        sodium_memzero($dek);
        $lastUid = $secret['uid'];
    }

    $this->connection->commit();
}
```

## Cleanup

### Expired Secrets

```sql
-- Find expired secrets for notification
SELECT identifier, expires_at, owner_uid
FROM tx_nrvault_secret
WHERE expires_at > 0
  AND expires_at < UNIX_TIMESTAMP()
  AND deleted = 0;

-- Find secrets expiring in next 30 days
SELECT identifier, expires_at, owner_uid
FROM tx_nrvault_secret
WHERE expires_at > 0
  AND expires_at BETWEEN UNIX_TIMESTAMP() AND UNIX_TIMESTAMP() + (30 * 86400)
  AND deleted = 0;
```

### Audit Log Retention

```sql
-- Archive or delete audit logs older than retention period
-- Only if hash chain verification is not required for compliance

DELETE FROM tx_nrvault_audit_log
WHERE crdate < UNIX_TIMESTAMP() - (365 * 86400);
```

## Performance Considerations

1. **Blob Size**: `encrypted_value` uses `mediumblob` (16MB max) to support large secrets like certificates. For typical API keys (< 1KB), this adds no overhead.

2. **Index on identifier**: Unique index ensures O(log n) lookup by identifier.

3. **Composite Indexes**: Added for common query patterns (cleanup, audit analysis).

4. **Audit Log Growth**: Consider partitioning or archiving for high-volume installations. At 1KB per entry, 1M entries = ~1GB.

5. **No Full-Text Search**: Secret values are encrypted and cannot be searched. Use `identifier`, `context`, and `metadata` for searchability.

6. **Connection Pooling**: Each decrypt operation requires master key access. Use request-scoped caching to minimize decryption operations.

---

*Schema Version: 2.0*
*Compatible with: TYPO3 v13.4+ | PHP 8.2+*
