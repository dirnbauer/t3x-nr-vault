# AGENTS.md - Configuration

> TYPO3 configuration guidelines for nr-vault.

## Structure

```
Configuration/
├── Backend/
│   └── Modules.php       # Backend module registration
├── Sets/
│   └── NrVault/          # Site set configuration
├── TCA/
│   ├── tx_nrvault_secret.php  # Secret table TCA
│   └── Overrides/             # TCA overrides for core tables
├── Icons.php             # Icon registration
├── JavaScriptModules.php # ES6 module registration
└── Services.yaml         # Dependency injection
```

## Services.yaml Patterns

### Interface Aliasing
```yaml
Netresearch\NrVault\Service\VaultServiceInterface:
  alias: Netresearch\NrVault\Service\VaultService
  public: true
```

### Factory Pattern
```yaml
Netresearch\NrVault\Crypto\MasterKeyProviderInterface:
  factory: ['@Netresearch\NrVault\Crypto\MasterKeyProviderFactory', 'create']
  public: true
```

### Controller Registration
```yaml
Netresearch\NrVault\Controller\SecretsController:
  public: true
  tags:
    - name: backend.controller
```

### CLI Command Registration
```yaml
Netresearch\NrVault\Command\VaultStoreCommand:
  tags:
    - name: console.command
```

## Backend Module Configuration

TYPO3 v14 uses PHP arrays for module registration:

```php
// Configuration/Backend/Modules.php
return [
    'admin_vault' => [
        'parent' => 'admin',
        'access' => 'admin',
        'path' => '/module/admin/vault',
        'labels' => 'nr_vault.modules.overview',  // Short format
        'routes' => [
            '_default' => [
                'target' => Controller::class . '::action',
            ],
        ],
    ],
];
```

Key patterns:
- Use short label format: `ext_key.modules.name`
- Labels map to `Resources/Private/Language/Modules/{name}.xlf`
- Submodules use `parent` to nest under parent module
- POST routes require `'methods' => ['POST']`

## TCA Configuration

### Custom Table
```php
// Configuration/TCA/tx_nrvault_secret.php
return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang_db.xlf:tx_nrvault_secret',
        'label' => 'identifier',
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
    ],
    'columns' => [ ... ],
    'types' => [ ... ],
];
```

### TCA Overrides
```php
// Configuration/TCA/Overrides/tt_content.php
$GLOBALS['TCA']['tt_content']['columns']['my_field'] = [ ... ];
```

## Icon Registration

```php
// Configuration/Icons.php
return [
    'module-vault' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_vault/Resources/Public/Icons/module-vault.svg',
    ],
];
```

## Site Sets

Site sets provide configurable settings:

```
Configuration/Sets/NrVault/
├── config.yaml     # Set metadata
└── settings.yaml   # Default settings
```

## Common Tasks

### Add New Backend Module Route
1. Add route to `Configuration/Backend/Modules.php`
2. Add controller action in `Classes/Controller/`
3. Add template in `Resources/Private/Templates/`

### Add New TCA Field
1. Add column definition in TCA file
2. Add to `showitem` in types
3. Add label in `locallang_db.xlf`

### Register New Service
1. Create interface in `Classes/`
2. Create implementation in `Classes/`
3. Add alias in `Configuration/Services.yaml`

---

*[n] Netresearch DTT GmbH*
