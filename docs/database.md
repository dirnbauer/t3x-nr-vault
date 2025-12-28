# nr-vault Database Schema

## Overview

nr-vault uses two tables for storing encrypted secrets and audit logs. The schema follows TYPO3 conventions and supports both the local encryption adapter and external vault adapters.

## Tables

### tx_nrvault_secret

Stores encrypted secrets with metadata for access control.

```sql
CREATE TABLE tx_nrvault_secret (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- Identification
    identifier varchar(255) DEFAULT '' NOT NULL,

    -- Encrypted data (local adapter only)
    encrypted_value mediumblob,
    encrypted_dek varchar(500) DEFAULT '' NOT NULL,
    encryption_version int(11) unsigned DEFAULT '1' NOT NULL,

    -- Access control
    owner_uid int(11) unsigned DEFAULT '0' NOT NULL,
    allowed_groups varchar(255) DEFAULT '' NOT NULL,

    -- Metadata
    version int(11) unsigned DEFAULT '1' NOT NULL,
    expires_at int(11) unsigned DEFAULT '0' NOT NULL,
    metadata text,

    -- Adapter info
    adapter varchar(50) DEFAULT 'local' NOT NULL,
    external_reference varchar(500) DEFAULT '' NOT NULL,

    -- Standard TYPO3 fields
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(1) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(1) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY identifier (identifier, deleted),
    KEY owner_uid (owner_uid),
    KEY adapter (adapter),
    KEY expires_at (expires_at)
);
```

#### Column Details

| Column | Type | Description |
|--------|------|-------------|
| `identifier` | varchar(255) | Unique identifier for the secret (e.g., `myext_api_key_123`) |
| `encrypted_value` | mediumblob | The encrypted secret value (AES-256-GCM ciphertext + nonce + auth tag) |
| `encrypted_dek` | varchar(500) | The Data Encryption Key, encrypted with master key |
| `encryption_version` | int | Encryption format version (for future algorithm upgrades) |
| `owner_uid` | int | Backend user UID who owns this secret |
| `allowed_groups` | varchar(255) | Comma-separated list of BE group UIDs allowed to access |
| `version` | int | Secret version, incremented on rotation |
| `expires_at` | int | Unix timestamp when secret expires (0 = never) |
| `metadata` | text | JSON-encoded custom metadata |
| `adapter` | varchar(50) | Storage adapter identifier (`local`, `hashicorp`, `aws`, etc.) |
| `external_reference` | varchar(500) | For external adapters: path/key in external vault |

### tx_nrvault_audit_log

Immutable audit trail of all secret operations.

```sql
CREATE TABLE tx_nrvault_audit_log (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,

    -- What happened
    secret_identifier varchar(255) DEFAULT '' NOT NULL,
    action varchar(50) DEFAULT '' NOT NULL,
    success tinyint(1) unsigned DEFAULT '1' NOT NULL,
    error_message text,

    -- Who did it
    actor_uid int(11) unsigned DEFAULT '0' NOT NULL,
    actor_type varchar(50) DEFAULT '' NOT NULL,
    actor_username varchar(255) DEFAULT '' NOT NULL,

    -- Context
    ip_address varchar(45) DEFAULT '' NOT NULL,
    user_agent varchar(500) DEFAULT '' NOT NULL,
    request_id varchar(100) DEFAULT '' NOT NULL,

    -- When
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    -- Additional data (JSON)
    context text,

    PRIMARY KEY (uid),
    KEY secret_identifier (secret_identifier),
    KEY action (action),
    KEY actor_uid (actor_uid),
    KEY tstamp (tstamp),
    KEY success (success)
);
```

#### Column Details

| Column | Type | Description |
|--------|------|-------------|
| `secret_identifier` | varchar(255) | The secret that was accessed |
| `action` | varchar(50) | Operation: `create`, `read`, `update`, `delete`, `rotate`, `access_denied` |
| `success` | tinyint | Whether operation succeeded |
| `error_message` | text | Error details if operation failed |
| `actor_uid` | int | Backend user UID who performed action |
| `actor_type` | varchar(50) | Context: `backend`, `cli`, `api`, `scheduler` |
| `actor_username` | varchar(255) | Username for display (denormalized for audit immutability) |
| `ip_address` | varchar(45) | Request IP address (IPv4 or IPv6) |
| `user_agent` | varchar(500) | HTTP User-Agent header |
| `request_id` | varchar(100) | Unique request identifier for correlation |
| `context` | text | Additional JSON context (e.g., which extension triggered the access) |

## Indexes

### tx_nrvault_secret Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| PRIMARY | `uid` | Primary key |
| identifier | `identifier`, `deleted` | Unique secret lookup |
| owner_uid | `owner_uid` | Filter by owner |
| adapter | `adapter` | Filter by storage adapter |
| expires_at | `expires_at` | Find expired secrets |

### tx_nrvault_audit_log Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| PRIMARY | `uid` | Primary key |
| secret_identifier | `secret_identifier` | Audit history per secret |
| action | `action` | Filter by action type |
| actor_uid | `actor_uid` | User's action history |
| tstamp | `tstamp` | Time-based queries |
| success | `success` | Filter failures |

## Data Flow

### Storing a Secret (Local Adapter)

```
1. VaultService::store('my_api_key', 'secret123')

2. EncryptionService::generateDek()
   → DEK = random 32 bytes

3. EncryptionService::encryptWithDek('secret123', DEK)
   → encrypted_value = AES-256-GCM(secret123, DEK)

4. EncryptionService::encryptDek(DEK)
   → encrypted_dek = AES-256-GCM(DEK, master_key)

5. INSERT INTO tx_nrvault_secret (
       identifier = 'my_api_key',
       encrypted_value = <blob>,
       encrypted_dek = <base64>,
       owner_uid = 1,
       ...
   )

6. INSERT INTO tx_nrvault_audit_log (
       secret_identifier = 'my_api_key',
       action = 'create',
       ...
   )
```

### Retrieving a Secret (Local Adapter)

```
1. VaultService::retrieve('my_api_key')

2. AccessControlService::checkAccess('my_api_key', $currentUser)
   → Verify user is owner OR in allowed_groups

3. SELECT encrypted_value, encrypted_dek
   FROM tx_nrvault_secret
   WHERE identifier = 'my_api_key'

4. EncryptionService::decryptDek(encrypted_dek)
   → DEK = AES-256-GCM_decrypt(encrypted_dek, master_key)

5. EncryptionService::decryptWithDek(encrypted_value, DEK)
   → plaintext = AES-256-GCM_decrypt(encrypted_value, DEK)

6. INSERT INTO tx_nrvault_audit_log (
       secret_identifier = 'my_api_key',
       action = 'read',
       ...
   )

7. Return plaintext
```

## Storage for External Adapters

When using external vault adapters (HashiCorp, AWS, Azure), the database serves as a metadata store only:

| Column | Local Adapter | External Adapter |
|--------|--------------|------------------|
| `encrypted_value` | Contains ciphertext | NULL |
| `encrypted_dek` | Contains encrypted DEK | NULL |
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
    allowed_groups = '1,2'
);
```

The actual secret value is stored in HashiCorp Vault at `secret/data/typo3/my_api_key`.

## Migration

### From Plaintext Storage

If migrating from plaintext API keys in other extensions:

```sql
-- Example: Migrate from custom table with plaintext api_key column
INSERT INTO tx_nrvault_secret (identifier, owner_uid, metadata)
SELECT
    CONCAT('myext_provider_', uid, '_api_key') as identifier,
    cruser_id as owner_uid,
    JSON_OBJECT('migrated_from', 'tx_myext_provider', 'original_uid', uid) as metadata
FROM tx_myext_provider
WHERE api_key != '';

-- Then call VaultService::store() for each to encrypt the actual values
```

### Master Key Rotation

When rotating the master key, all DEKs must be re-encrypted:

```sql
-- This is handled by MasterKeyRotationService
-- For each secret:
-- 1. Decrypt DEK with old master key
-- 2. Re-encrypt DEK with new master key
-- 3. Update encrypted_dek column
-- 4. Increment encryption_version
UPDATE tx_nrvault_secret
SET encrypted_dek = :newEncryptedDek,
    encryption_version = encryption_version + 1
WHERE uid = :uid;
```

## Cleanup

### Expired Secrets

```sql
-- Find expired secrets
SELECT identifier, expires_at
FROM tx_nrvault_secret
WHERE expires_at > 0
  AND expires_at < UNIX_TIMESTAMP()
  AND deleted = 0;

-- Scheduler task can soft-delete or notify about expiring secrets
```

### Audit Log Retention

```sql
-- Delete audit logs older than retention period (e.g., 365 days)
DELETE FROM tx_nrvault_audit_log
WHERE tstamp < UNIX_TIMESTAMP() - (365 * 24 * 60 * 60);
```

## Performance Considerations

1. **Blob Size**: `encrypted_value` uses `mediumblob` (16MB max) to support large secrets like certificates. For typical API keys (< 1KB), this adds no overhead.

2. **Index on identifier**: Unique index ensures O(log n) lookup by identifier.

3. **Audit Log Growth**: Consider partitioning or archiving for high-volume installations.

4. **No Full-Text Search**: Secret values are encrypted and cannot be searched. Use `identifier` and `metadata` for searchability.
