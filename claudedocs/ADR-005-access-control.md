# ADR-005: Access Control

## Status
Accepted

## Date
2026-01-03

## Context
Secrets may contain highly sensitive data. Access must be controlled and integrated with TYPO3's permission system.

## Decision
Owner/Group model with TYPO3 backend user integration:

1. **Owner**: Backend user with full access
2. **Groups**: Backend groups with access
3. **Admin override**: TYPO3 admins bypass all checks

## Access Decision Tree

```
1. Is user admin/system maintainer? → ALLOW
2. Is user the secret's owner? → ALLOW
3. Is user in any allowed_groups? → ALLOW
4. Is CLI context with CLI access enabled? → Check CLI groups
5. Is frontend with frontend_accessible=true? → ALLOW (read only)
6. Default → DENY
```

## Implementation

```php
public function canRead(Secret $secret): bool
{
    if ($backendUser->isAdmin()) return true;
    if ($userUid === $secret->getOwnerUid()) return true;

    $userGroups = $backendUser->userGroupsUID ?? [];
    return count(array_intersect($userGroups, $secret->getAllowedGroups())) > 0;
}
```

## Database Schema

```sql
owner_uid int(11) unsigned,           -- Single owner
allowed_groups text,                   -- Group IDs
frontend_accessible tinyint(1),        -- FE access flag
```

## Consequences

**Positive:**
- Familiar TYPO3 concepts (users, groups)
- Simple mental model
- Admin override as expected

**Negative:**
- No per-operation ACL (read vs write)
- May need many groups for fine control

## References
- `Classes/Security/AccessControlService.php`
- `Configuration/TCA/tx_nrvault_secret.php`
