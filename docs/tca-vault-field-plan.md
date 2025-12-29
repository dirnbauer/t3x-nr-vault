# TCA Vault Field Integration Plan

## Overview

Enable other TYPO3 extensions to use vault-backed fields for storing sensitive data (API keys, credentials, tokens) by providing a custom TCA `renderType`.

## Architecture

### Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                     BACKEND FORM EDIT                           │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌──────────────┐    ┌─────────────────────┐    ┌───────────┐  │
│  │ VaultSecret  │───►│ VaultSecretElement  │───►│ Form HTML │  │
│  │ Evaluation   │    │ (renderType)        │    │ (obfusc.) │  │
│  └──────────────┘    └─────────────────────┘    └───────────┘  │
│         │                      │                               │
│         │                      │ on display                    │
│         │                      ▼                               │
│         │            ┌─────────────────────┐                   │
│         │            │ VaultService        │                   │
│         │            │ ->retrieve()        │                   │
│         │            └─────────────────────┘                   │
│         │                                                      │
│         │ on save                                              │
│         ▼                                                      │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ DataHandler processes form submission                    │  │
│  │ → VaultSecretEvaluation::evaluateFieldValue()           │  │
│  │ → VaultService::store(identifier, secret, metadata)      │  │
│  │ → Returns vault identifier to store in DB column         │  │
│  └──────────────────────────────────────────────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│                     DATABASE STORAGE                            │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  tx_myext_settings table:                                       │
│  ┌────────┬─────────────────────────────────┐                  │
│  │ uid    │ api_key (varchar)               │                  │
│  ├────────┼─────────────────────────────────┤                  │
│  │ 1      │ myext_api_key_a7x9k3m2          │ ◄── Vault ID     │
│  │ 2      │ myext_api_key_b8y4l2n1          │                  │
│  └────────┴─────────────────────────────────┘                  │
│                                                                 │
│  tx_nrvault_secrets table (vault storage):                      │
│  ┌────────────────────────────────┬──────────────────────────┐ │
│  │ identifier                     │ encrypted_value          │ │
│  ├────────────────────────────────┼──────────────────────────┤ │
│  │ myext_api_key_a7x9k3m2         │ AES-256-GCM encrypted    │ │
│  │ myext_api_key_b8y4l2n1         │ AES-256-GCM encrypted    │ │
│  └────────────────────────────────┴──────────────────────────┘ │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Identifier Generation

Format: `{prefix}_{field}_{randomSuffix}`

Example: `myext_api_key_a7x9k3m2`

- **prefix**: Configurable per field (default: extension key)
- **field**: Field name from TCA
- **randomSuffix**: 8 character random alphanumeric

This prevents identifier enumeration and guessing.

## TCA Configuration

### Basic Usage

```php
// Configuration/TCA/tx_myext_settings.php
return [
    'ctrl' => [...],
    'columns' => [
        'api_key' => [
            'label' => 'API Key',
            'config' => [
                'type' => 'input',
                'renderType' => 'vaultSecret',
                'size' => 30,
            ],
        ],
        'api_secret' => [
            'label' => 'API Secret',
            'config' => [
                'type' => 'input',
                'renderType' => 'vaultSecret',
                'size' => 50,
                'vaultOptions' => [
                    'prefix' => 'myext_prod',    // Custom identifier prefix
                    'revealable' => true,        // Show reveal button (default: true)
                    'copyable' => true,          // Show copy button (default: true)
                ],
            ],
        ],
    ],
];
```

### Using TCA Helper

```php
use Netresearch\NrVault\TCA\VaultFieldHelper;

return [
    'columns' => [
        'api_key' => VaultFieldHelper::getFieldConfig([
            'label' => 'API Key',
            'prefix' => 'myext',
            'size' => 30,
        ]),
    ],
];
```

## Implementation Components

### 1. VaultSecretElement (ALREADY IMPLEMENTED)

Location: `Classes/Form/Element/VaultSecretElement.php`

Already handles:
- Obfuscated display with reveal button
- Hidden field for vault identifier tracking
- Change detection via checksum

### 2. DataHandlerHook (ALREADY IMPLEMENTED)

Location: `Classes/Hook/DataHandlerHook.php`

Handles save/delete operations:
- `processDatamap_preProcessFieldArray`: Intercepts form values before save
- `processDatamap_afterDatabaseOperations`: Stores secrets to vault after record UID is known
- `processCmdmap_preProcess`: Deletes vault secrets when records are deleted
- `processCmdmap_postProcess`: Copies vault secrets when records are copied

Uses deterministic identifier format: `{table}__{field}__{uid}`

### 3. VaultFieldResolver Utility (TO IMPLEMENT)

Location: `Classes/Utility/VaultFieldResolver.php`

```php
<?php
declare(strict_types=1);

namespace Netresearch\NrVault\Utility;

use Netresearch\NrVault\Service\VaultService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class VaultFieldResolver
{
    /**
     * Resolve vault identifiers to actual secret values.
     *
     * @param array $data Record data containing vault identifiers
     * @param array $fields Field names to resolve
     * @return array Data with vault identifiers replaced by actual values
     */
    public static function resolveFields(array $data, array $fields): array
    {
        $vaultService = GeneralUtility::makeInstance(VaultService::class);

        foreach ($fields as $field) {
            if (isset($data[$field]) && self::isVaultIdentifier($data[$field])) {
                try {
                    $data[$field] = $vaultService->retrieve($data[$field]);
                } catch (\Exception) {
                    $data[$field] = null;
                }
            }
        }

        return $data;
    }

    public static function isVaultIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z0-9_]+$/i', $value);
    }
}
```

### 4. NodeRegistry Registration (ALREADY IMPLEMENTED)

Location: `ext_localconf.php`

```php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1734000000] = [
    'nodeName' => 'vaultSecret',
    'priority' => 40,
    'class' => \Netresearch\NrVault\Form\Element\VaultSecretElement::class,
];
```

### 5. DataHandler Hooks Registration (ALREADY IMPLEMENTED)

Location: `ext_localconf.php`

```php
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = \Netresearch\NrVault\Hook\DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = \Netresearch\NrVault\Hook\DataHandlerHook::class;
```

## Usage in Consuming Extensions

### Step 1: Add Dependency

```json
{
    "require": {
        "netresearch/nr-vault": "^1.0"
    }
}
```

### Step 2: Configure TCA Field

```php
'my_api_key' => [
    'label' => 'API Key',
    'config' => [
        'type' => 'input',
        'renderType' => 'vaultSecret',
        'size' => 30,
    ],
],
```

That's it! The DataHandlerHook automatically detects fields with `renderType => 'vaultSecret'` and handles vault storage.

### Step 3: Retrieve Secret in Code

```php
use Netresearch\NrVault\Utility\VaultFieldResolver;

// In your service or controller
$settings = $this->getTypoScriptSettings();
$resolved = VaultFieldResolver::resolveFields($settings, ['api_key', 'api_secret']);

// Now $resolved['api_key'] contains the actual secret value
$client->authenticate($resolved['api_key']);
```

## Security Considerations

1. **Identifier Unpredictability**: Random suffixes prevent enumeration
2. **Audit Trail**: All vault access is logged
3. **RBAC Integration**: Secrets inherit record-level permissions
4. **No Plaintext Storage**: Only vault identifiers in external tables
5. **Reveal Protection**: Reveal action requires explicit user action

## Orphan Cleanup

When records are deleted, their vault secrets become orphaned. A scheduler task should:

1. Scan vault secrets with `type = 'tca_field'` metadata
2. Check if referenced record still exists
3. Delete orphaned secrets after configurable retention period

## Migration Path

For existing extensions storing credentials in plaintext:

1. Add nr-vault dependency
2. Update TCA to use `renderType => 'vaultSecret'`
3. Run migration command to move existing values to vault:

```bash
vendor/bin/typo3 vault:migrate-field tx_myext_settings api_key
```

## Implementation Phases

### Phase 1: Core TCA Integration (COMPLETED)
- [x] VaultSecretElement form rendering
- [x] DataHandlerHook for save/delete/copy operations
- [x] NodeRegistry registration
- [x] DataHandler hooks registration

### Phase 2: Developer Experience (COMPLETED)
- [x] VaultFieldResolver utility for easy secret retrieval
- [x] TCA helper class for simplified configuration
- [x] FlexForm support for vaultSecret fields (FlexFormVaultHook + FlexFormVaultResolver)
- [x] Developer documentation (Documentation/Developer/TcaIntegration.rst)
- [x] Unit tests for all new components

### Phase 3: Advanced Features (IN PROGRESS)
- [x] Orphan cleanup scheduler task
- [x] Migration CLI command (`vault:migrate-field`)
- [x] Orphan cleanup CLI command (`vault:cleanup-orphans`)
- [ ] TSconfig for field-level permissions
- [ ] Site configuration vault integration

## File Summary

| File | Status | Purpose |
|------|--------|---------|
| `Classes/Form/Element/VaultSecretElement.php` | Done | Form rendering with obfuscation |
| `Classes/Hook/DataHandlerHook.php` | Done | Save/delete/copy operations |
| `Classes/Hook/FlexFormVaultHook.php` | Done | FlexForm vault field handling |
| `Classes/Utility/VaultFieldResolver.php` | Done | Secret retrieval utility |
| `Classes/Utility/FlexFormVaultResolver.php` | Done | FlexForm secret retrieval |
| `Classes/TCA/VaultFieldHelper.php` | Done | TCA configuration helper |
| `Classes/Command/VaultMigrateFieldCommand.php` | Done | Migration CLI command |
| `Classes/Command/VaultCleanupOrphansCommand.php` | Done | Orphan cleanup CLI command |
| `Classes/Task/OrphanCleanupTask.php` | Done | Scheduler task for orphan cleanup |
| `Classes/Task/OrphanCleanupTaskAdditionalFieldProvider.php` | Done | Scheduler task configuration |
| `ext_localconf.php` | Done | Element and hook registration |
| `Documentation/Developer/TcaIntegration.rst` | Done | Developer docs |
| `Tests/Unit/Utility/VaultFieldResolverTest.php` | Done | Unit tests |
| `Tests/Unit/Utility/FlexFormVaultResolverTest.php` | Done | Unit tests |
| `Tests/Unit/TCA/VaultFieldHelperTest.php` | Done | Unit tests |
| `Tests/Unit/Command/VaultMigrateFieldCommandTest.php` | Done | Command unit tests |
| `Tests/Unit/Command/VaultCleanupOrphansCommandTest.php` | Done | Command unit tests |
| `Tests/Unit/Task/OrphanCleanupTaskTest.php` | Done | Task unit tests |
| `Tests/Unit/Task/OrphanCleanupTaskAdditionalFieldProviderTest.php` | Done | Task field provider tests |
