# ADR-009: Extension Configuration Secrets

## Status
Accepted

## Date
2026-01-04

## Context
TYPO3 extensions store API keys in extension settings (`ext_conf_template.txt`). These are stored in `sys_registry` table and loaded into `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']`.

**Challenges:**
1. No PSR-14 events for extension config save/load
2. Values in `$GLOBALS` persist entire request (memory safety issue)
3. No custom field type hooks for save/load lifecycle

## Decision
Store **vault identifiers** (not secrets) in extension settings. Resolve at use time.

### Pattern A: Direct identifier (recommended)

```php
// Extension setting: acme_translate_api_key

$vault->http()
    ->withAuthentication($config['apiKey'], SecretPlacement::Bearer)
    ->sendRequest($request);
```

Simple, works directly with VaultHttpClient.

### Pattern B: Prefixed reference (optional)

For mixed settings, explicit documentation, or **migration from plaintext to vault**:

```php
// Extension setting: vault:acme_translate_api_key

$ref = VaultReference::tryParse($config['apiKey']);
$vault->http()
    ->withAuthentication($ref->identifier, SecretPlacement::Bearer)
    ->sendRequest($request);
```

For mixed settings or explicit documentation.

## Example

```php
final class TranslationService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly RequestFactory $requestFactory,
        ExtensionConfiguration $extensionConfiguration,
    ) {
        $config = $extensionConfiguration->get('acme_translate');
        $this->apiKey = (string) ($config['apiKey'] ?? '');
    }

    public function translate(string $text, string $targetLang): string
    {
        $request = $this->requestFactory->createRequest('POST', $this->apiEndpoint);

        // $this->apiKey = vault identifier, resolved at request time
        return $this->vault->http()
            ->withAuthentication($this->apiKey, SecretPlacement::Bearer)
            ->sendRequest($request);
    }
}
```

## Why Safe

```
sys_registry (extension config):
  apiKey = "acme_translate_api_key"    ← Just the identifier

tx_nrvault_secret (vault):
  identifier = "acme_translate_api_key"
  encrypted_value = [AES-256-GCM encrypted]
```

If someone accidentally enters actual API key:
1. `withAuthentication('sk_live_abc...')` tries vault lookup
2. Vault returns "not found"
3. Request fails safely (secret never sent)

## Consequences

**Positive:**
- Memory safety preserved (resolve at use time)
- Simple pattern (direct identifier works)
- Safe failure mode
- Backend-friendly (no CLI needed)

**Negative:**
- Two-step setup (create secret, then reference)
- No UI validation in extension settings
- Convention-based (developers document which fields are vault refs)

## References
- `Documentation/Usage/ExtensionSettings.rst`
