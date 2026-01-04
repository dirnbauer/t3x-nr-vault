# ADR-006: Audit Logging

## Status
Accepted

## Date
2026-01-03

## Context
Secret management requires comprehensive audit trails for security investigation and compliance (SOC 2, ISO 27001, GDPR).

## Decision
Dedicated audit table with SHA-256 hash chain for tamper detection, combined with PSR-14 events for extensibility.

## Hash Chain

Each entry links to the previous via hash:

```php
$entryHash = hash('sha256', implode('|', [
    $uid, $secretIdentifier, $action, $actorUid, $crdate, $previousHash
]));
```

Tampering breaks the chain and is detectable via `verifyHashChain()`.

## Logged Operations

- `create` - New secret stored
- `read` - Secret retrieved
- `update` - Secret modified
- `delete` - Secret removed
- `rotate` - Secret rotated
- `access_denied` - Permission failure
- `http_call` - VaultHttpClient API call

## PSR-14 Events

```php
SecretCreatedEvent    // After secret created
SecretAccessedEvent   // After secret read
SecretRotatedEvent    // After rotation
SecretDeletedEvent    // After deletion
MasterKeyRotatedEvent // After key rotation
```

## Audit Entry Structure

```php
AuditLogEntry {
    secretIdentifier, action, success
    actorUid, actorType, actorUsername
    ipAddress, userAgent, requestId
    previousHash, entryHash         // Tamper detection
    hashBefore, hashAfter           // Change tracking
    context                         // JSON metadata
}
```

## Consequences

**Positive:**
- Tamper-evident via hash chain
- Complete operation trail
- Extensible via PSR-14 events

**Negative:**
- Storage growth over time
- Chain corruption affects verification

## References
- `Classes/Audit/AuditLogService.php`
- `Classes/Event/SecretAccessedEvent.php`
