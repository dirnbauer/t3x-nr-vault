# AGENTS.md - Resources

> Frontend resources guidelines for nr-vault.

## Structure

```
Resources/
├── Private/
│   ├── Language/
│   │   ├── Modules/              # Module-specific labels
│   │   │   ├── overview.xlf
│   │   │   ├── secrets.xlf
│   │   │   ├── audit.xlf
│   │   │   └── migration.xlf
│   │   ├── locallang.xlf         # General labels
│   │   ├── locallang_mod.xlf     # Module labels (legacy)
│   │   └── locallang_tca.xlf     # TCA field labels
│   ├── Partials/                 # Reusable Fluid partials
│   └── Templates/                # Fluid templates
│       ├── Overview/
│       ├── Secrets/
│       ├── Audit/
│       └── Migration/
└── Public/
    ├── Icons/                    # SVG icons
    ├── Css/                      # Stylesheets
    └── JavaScript/               # ES6 modules
```

## Language Files (XLIFF)

### File Naming Convention
- `locallang.xlf` - General extension labels
- `locallang_tca.xlf` - TCA/database field labels
- `Modules/{name}.xlf` - Module-specific labels

### XLIFF Format
```xml
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
    <file source-language="en" datatype="plaintext" original="messages">
        <body>
            <trans-unit id="title">
                <source>Secret Management</source>
            </trans-unit>
            <trans-unit id="button.save">
                <source>Save Secret</source>
            </trans-unit>
        </body>
    </file>
</xliff>
```

### Usage in Fluid
```html
<f:translate key="LLL:EXT:nr_vault/Resources/Private/Language/locallang.xlf:title" />
```

### Short Format (TYPO3 v12+)
```php
// In Modules.php
'labels' => 'nr_vault.modules.secrets',
// Maps to: EXT:nr_vault/Resources/Private/Language/Modules/secrets.xlf
```

## Fluid Templates

### Controller → Template Mapping
```
Controller: SecretsController::listAction()
Template:   Resources/Private/Templates/Secrets/List.html
```

### Template Structure
```html
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      xmlns:be="http://typo3.org/ns/TYPO3/CMS/Backend/ViewHelpers"
      xmlns:core="http://typo3.org/ns/TYPO3/CMS/Core/ViewHelpers"
      data-namespace-typo3-fluid="true">

<f:layout name="Module" />

<f:section name="Content">
    <!-- Template content -->
</f:section>

</html>
```

### Partials
Reusable components in `Partials/`:
```html
<f:render partial="SecretRow" arguments="{secret: secret}" />
```

## JavaScript (ES6 Modules)

### Registration
```php
// Configuration/JavaScriptModules.php
return [
    'imports' => [
        '@netresearch/nr-vault/' => 'EXT:nr_vault/Resources/Public/JavaScript/',
    ],
];
```

### Usage in Templates
```html
<script type="module">
    import VaultModule from '@netresearch/nr-vault/vault-module.js';
</script>
```

## Icons

### SVG Requirements
- Viewbox: `0 0 16 16` or `0 0 24 24`
- Single color (uses `currentColor`)
- No embedded styles

### Registration
```php
// Configuration/Icons.php
return [
    'vault-secret' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_vault/Resources/Public/Icons/secret.svg',
    ],
];
```

## CSS

### TYPO3 Backend Styles
Follow TYPO3 backend styling patterns:
- Use CSS custom properties where possible
- Prefix custom classes with `vault-`
- Use TYPO3 utility classes when available

## Common Tasks

### Add New Translation
1. Add `<trans-unit>` to appropriate `.xlf` file
2. Use `<f:translate>` in template
3. Test in both frontend and backend contexts

### Add New Template
1. Create `.html` file matching controller action
2. Use `<f:layout name="Module" />` for backend
3. Define `<f:section name="Content">`

### Add New Icon
1. Add SVG to `Resources/Public/Icons/`
2. Register in `Configuration/Icons.php`
3. Use with `<core:icon identifier="..." />`

---

*[n] Netresearch DTT GmbH*
