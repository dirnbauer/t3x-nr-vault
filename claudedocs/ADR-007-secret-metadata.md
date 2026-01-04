# ADR-007: Secret Metadata

## Status
Accepted

## Date
2026-01-03

## Context
Secrets need metadata for access control, lifecycle management, and operational insights. This metadata must be queryable without decrypting secrets.

## Decision
Store metadata as plaintext columns alongside encrypted value in the same table:

- **Encrypted**: encryptedValue, encryptedDek, nonces
- **Plaintext**: owner, groups, context, expiration, version, etc.

## Metadata Categories

**Access Control:**
- owner_uid, allowed_groups, context, frontend_accessible

**Lifecycle:**
- version, expires_at, last_rotated_at, read_count, last_read_at

**Storage:**
- adapter, external_reference, scope_pid

**Custom:**
- metadata (JSON for application-specific data)

## Benefits

```php
// Check expiration without decryption
if ($secret->isExpired()) throw new SecretExpiredException();

// Check permissions without decryption
if (!$accessControl->canRead($secret)) throw new AccessDeniedException();

// Only decrypt after all checks pass
return $this->decrypt($secret);
```

## Database Schema

```sql
-- Queryable metadata (plaintext)
owner_uid int(11) unsigned,
context varchar(50),
expires_at int(11) unsigned,
version int(11) unsigned,

-- Encrypted data
encrypted_value mediumblob,
encrypted_dek text,
```

## Consequences

**Positive:**
- Fast queries without decryption
- Access control before crypto operations
- Flexible custom metadata via JSON

**Negative:**
- Metadata visible in database
- Must not store secrets in metadata fields

## References
- `Classes/Domain/Model/Secret.php`
- `Classes/Service/VaultService.php::getMetadata()`
