# ADR-004: TCA Integration

## Status
Accepted

## Date
2026-01-03

## Context
TYPO3 extensions store sensitive data in TCA fields. We need seamless vault integration without requiring extensions to rewrite their data handling.

## Decision
Custom `renderType: 'vaultSecret'` with DataHandler hooks:

1. **VaultSecretElement**: FormEngine element for rendering password fields
2. **DataHandlerHook**: Intercepts saves to store secrets in vault
3. **FlexFormVaultHook**: Separate handling for FlexForm fields

## Implementation

```php
// TCA configuration - one line change
'api_key' => [
    'config' => [
        'type' => 'input',
        'renderType' => 'vaultSecret',  // This enables vault storage
    ],
],

// Or use helper
'api_key' => VaultFieldHelper::getSecureFieldConfig('API Key'),
```

## Data Flow

1. Form displays masked field with reveal/copy buttons
2. On save, DataHandlerHook extracts secret, generates UUID v7
3. Secret stored in vault with metadata (table, field, uid)
4. UUID stored in database field
5. At runtime, VaultFieldResolver resolves UUID to secret

## Record Operations

- **Create**: New secret stored with UUID
- **Update**: Secret rotated (new version)
- **Delete**: Vault secret removed
- **Copy**: New secret created for copied record

## Consequences

**Positive:**
- Minimal migration (add renderType)
- Full lifecycle handling
- Audit trail with context

**Negative:**
- Runtime resolution required
- Two hooks to maintain (TCA + FlexForm)

## References
- `Classes/Form/Element/VaultSecretElement.php`
- `Classes/Hook/DataHandlerHook.php`
- `Classes/Utility/VaultFieldResolver.php`
