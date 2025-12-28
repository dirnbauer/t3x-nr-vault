# nr-vault Delivery Plan: Maximum Security with Minimal User Impact

**Target:** TYPO3 v14.0+ | PHP 8.5+

## Executive Summary

This document outlines the delivery strategy for nr-vault, a TYPO3 v14 secret management extension designed to provide enterprise-grade security while maintaining seamless integration with existing workflows. The core philosophy is **"security by default, complexity by choice"** - users get strong protection automatically, with advanced features available when needed.

---

## 1. User Workflow Analysis

### 1.1 Current State: How Users Manage Secrets Today

| Workflow Pattern | Prevalence | Security Risk | User Experience |
|------------------|------------|---------------|-----------------|
| **LocalConfiguration.php** | Very High | Critical - Often committed to git | Easy but insecure |
| **Extension Configuration** | High | High - Stored plaintext in DB | Familiar but exposed |
| **TCA password fields** | Medium | High - Hash (unusable) or plaintext | Confusing behavior |
| **Environment variables** | Low | Medium - Not runtime configurable | DevOps-friendly but rigid |
| **Custom database columns** | Medium | Critical - Plaintext in DB/backups | Works but dangerous |
| **Hardcoded in PHP** | Low | Critical - In version control | Never appropriate |

#### Pain Points Identified

1. **No standard approach**: Every extension reinvents secret storage
2. **Security/usability trade-off**: Secure options are hard to use
3. **No audit trail**: Unknown who accessed which secrets when
4. **Key rotation nightmare**: Manual process, often skipped
5. **Multi-environment complexity**: Different secrets per environment are hard to manage
6. **Backup exposure**: Secrets included in database backups

### 1.2 Desired State: The Ideal User Experience

#### For Content Managers / Editors
```
Current: "Where do I put this API key? Is this field secure?"
Desired: "I paste the API key, click save. Done."
```

- Seamless field in TYPO3 backend that "just works"
- Visual confirmation that secret is stored securely
- No crypto knowledge required
- Clear feedback on who can access the secret

#### For Integrators / Developers
```
Current: "How do I store this API key? Build custom encryption? Use env vars?"
Desired: "$vault->store('key', $secret) - that's it."
```

- Simple API: store, retrieve, rotate, delete
- TCA integration for forms
- Dependency injection ready
- Clear documentation with copy-paste examples

#### For DevOps / Administrators
```
Current: "How do I rotate keys across 50 sites? Who accessed what?"
Desired: "CLI for rotation, dashboard for audit, alerts for anomalies."
```

- CLI tools for automation
- Audit log queries and exports
- Master key rotation without downtime
- Multi-environment support

#### For Security Officers
```
Current: "Where are secrets? Who has access? Are we compliant?"
Desired: "Centralized view, access controls, exportable audit trail."
```

- Full audit trail for compliance
- Access control via existing BE groups
- Encryption at rest certification
- Incident response capabilities

### 1.3 Migration Path: Transitioning Existing Secrets

#### Phase 1: Discovery (Automated)
```
./vendor/bin/typo3 vault:migrate:scan

Scanning for potential secrets...
Found 47 potential secrets:
- tx_myext_config.api_key (23 records, plaintext)
- tx_newsletter_domain_model_newsletter.smtp_password (8 records, plaintext)
- LocalConfiguration.php: $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']
...
```

#### Phase 2: Migration (Semi-Automated)
```
./vendor/bin/typo3 vault:migrate:execute tx_myext_config.api_key \
    --identifier-pattern="myext_config_{uid}_api_key" \
    --owner-field="cruser_id" \
    --groups=1

Migrating 23 records...
[============================] 100%
Successfully migrated 23 secrets.
Original columns NOT cleared (use --clear-originals to remove).
```

#### Phase 3: Verification
```
./vendor/bin/typo3 vault:migrate:verify tx_myext_config.api_key

Verification Report:
- 23 secrets migrated
- 23 original values match decrypted vault values
- 0 access control issues
Ready to clear original columns.
```

#### Phase 4: Cleanup (Manual Decision)
```
./vendor/bin/typo3 vault:migrate:cleanup tx_myext_config.api_key \
    --clear-originals

This will permanently remove original plaintext values.
Type 'CONFIRM' to proceed: CONFIRM

Cleared 23 original values.
Migration complete.
```

---

## 2. Design for Minimal Friction

### 2.1 UI/UX Considerations for TYPO3 Backend

#### TCA Field: vaultSecret RenderType

```
+------------------------------------------------------------------+
| API Key                                                    [?]   |
+------------------------------------------------------------------+
| ****************************************************************  |
|                                                                   |
| [Show] [Copy] [Rotate] [Delete]                                  |
|                                                                   |
| Stored securely | Version 3 | Last rotated: 2024-01-15           |
| Access: Admin, API Managers                                       |
+------------------------------------------------------------------+
```

**Design Principles:**

1. **Familiar Pattern**: Looks like a password field (masked by default)
2. **Clear Actions**: Obvious buttons for common operations
3. **Status Visibility**: User knows secret is secure at a glance
4. **Access Transparency**: Shows who can access this secret
5. **Audit Hint**: Version number indicates rotation history

#### Backend Module: Secrets Manager

```
+------------------------------------------------------------------+
| SECRETS MANAGER                                     [+ New Secret] |
+------------------------------------------------------------------+
| Filter: [All Secrets v] Owner: [All Users v] Search: [________]   |
+------------------------------------------------------------------+
| Identifier              | Owner    | Access      | Version | Age |
+------------------------------------------------------------------+
| myext_api_key_123       | admin    | Admin, API  | v3      | 2d  |
| newsletter_smtp_pass    | editor1  | Newsletter  | v1      | 45d |
| payment_gateway_token   | admin    | Admin       | v2      | 7d  |
+------------------------------------------------------------------+
| < 1 2 3 ... 10 >                              Showing 1-25 of 234 |
+------------------------------------------------------------------+

[Bulk Actions: v] [Rotate Selected] [Export Audit Log]
```

**Features:**
- List all accessible secrets (filtered by current user's permissions)
- Search/filter by identifier, owner, access groups
- Bulk operations for rotation
- Direct link to audit log per secret
- Export capabilities for compliance

#### Audit Log Viewer

```
+------------------------------------------------------------------+
| AUDIT LOG                                          [Export CSV]   |
+------------------------------------------------------------------+
| Secret: [All v] Action: [All v] User: [All v] Date: [Last 7 days v]|
+------------------------------------------------------------------+
| Timestamp           | Secret         | Action | User   | IP       |
+------------------------------------------------------------------+
| 2024-01-20 14:32:01 | myext_api_123  | read   | admin  | 10.0.0.5 |
| 2024-01-20 14:30:45 | myext_api_123  | rotate | admin  | 10.0.0.5 |
| 2024-01-20 09:15:22 | smtp_password  | read   | system | CLI      |
+------------------------------------------------------------------+
```

### 2.2 Auto-Detection of Secrets in Configuration

#### Heuristic Detection

The extension can scan existing configurations for potential secrets:

```php
// SecretDetectionService
class SecretDetectionService
{
    private const SECRET_PATTERNS = [
        // Field name patterns
        '/password$/i',
        '/api[_-]?key$/i',
        '/secret$/i',
        '/token$/i',
        '/credential$/i',
        '/auth/i',

        // Value patterns
        '/^sk_live_/',      // Stripe
        '/^pk_live_/',      // Stripe
        '/^AKIA/',          // AWS
        '/^ghp_/',          // GitHub
        '/^xox[baprs]-/',   // Slack
    ];

    public function detectInLocalConfiguration(): array;
    public function detectInDatabase(string $tableName): array;
    public function detectInExtensionConfiguration(): array;
}
```

#### Integration with TYPO3 Configuration Module

When detected secrets are found:

```
+------------------------------------------------------------------+
| SECURITY WARNING                                                  |
+------------------------------------------------------------------+
| We detected 3 potential secrets in plaintext storage:             |
|                                                                   |
| - LocalConfiguration.php: MAIL.transport_smtp_password           |
| - tx_myext_config: api_key column (12 records)                   |
| - Extension config: tx_newsletter.api_key                        |
|                                                                   |
| [Migrate to Vault] [Dismiss] [Don't show again]                   |
+------------------------------------------------------------------+
```

### 2.3 Seamless API for Extension Developers

#### Core API (5 Essential Methods)

```php
use Netresearch\NrVault\Service\VaultService;

class MyApiService
{
    public function __construct(
        private readonly VaultService $vault,
    ) {}

    // Store - creates or updates
    public function saveCredentials(int $id, string $apiKey): void
    {
        $this->vault->store("myext_provider_{$id}_key", $apiKey);
    }

    // Retrieve - returns null if not found
    public function getCredentials(int $id): ?string
    {
        return $this->vault->retrieve("myext_provider_{$id}_key");
    }

    // Exists - check without decryption
    public function hasCredentials(int $id): bool
    {
        return $this->vault->exists("myext_provider_{$id}_key");
    }

    // Rotate - update with versioning
    public function rotateCredentials(int $id, string $newKey): void
    {
        $this->vault->rotate("myext_provider_{$id}_key", $newKey);
    }

    // Delete - permanent removal
    public function removeCredentials(int $id): void
    {
        $this->vault->delete("myext_provider_{$id}_key");
    }
}
```

#### Advanced API (When Needed)

```php
// With access control
$this->vault->store('shared_api_key', $secret, [
    'owner' => $GLOBALS['BE_USER']->user['uid'],
    'groups' => [1, 5],  // Admin and API Managers
]);

// With expiration
$this->vault->store('temp_token', $token, [
    'expires' => new \DateTime('+30 days'),
]);

// With custom metadata
$this->vault->store('oauth_token', $token, [
    'metadata' => [
        'provider' => 'google',
        'scope' => 'calendar.read',
        'refresh_token' => true,
    ],
]);

// Query metadata
$meta = $this->vault->getMetadata('oauth_token');
// Returns: ['version' => 2, 'createdAt' => ..., 'metadata' => [...]]

// List with filters
$secrets = $this->vault->list([
    'prefix' => 'myext_',
    'owner' => 1,
]);
```

#### TCA Integration (Zero-Code for Forms)

```php
// Configuration/TCA/tx_myext_provider.php
return [
    'columns' => [
        'api_key' => [
            'label' => 'API Key',
            'config' => [
                'type' => 'user',
                'renderType' => 'vaultSecret',
                'parameters' => [
                    'vaultIdentifier' => 'myext_provider_{uid}_api_key',
                ],
            ],
        ],
    ],
];
```

The `{uid}` placeholder is replaced with the record UID automatically.

### 2.4 CLI Tools for DevOps

#### Secret Management
```bash
# Store (interactive)
./vendor/bin/typo3 vault:store my_api_key

# Store (from stdin - for scripts)
echo "secret123" | ./vendor/bin/typo3 vault:store my_api_key --stdin

# Store with options
./vendor/bin/typo3 vault:store my_api_key \
    --owner=1 \
    --groups=1,2 \
    --expires="+90 days"

# Retrieve (outputs to stdout)
./vendor/bin/typo3 vault:retrieve my_api_key

# Rotate
./vendor/bin/typo3 vault:rotate my_api_key --stdin < new_key.txt

# Delete
./vendor/bin/typo3 vault:delete my_api_key --force

# List
./vendor/bin/typo3 vault:list --prefix=myext_ --format=json
```

#### Master Key Operations
```bash
# Generate new key (outputs to stdout, doesn't store)
./vendor/bin/typo3 vault:master-key:generate

# Rotate master key (re-encrypts all DEKs)
./vendor/bin/typo3 vault:master-key:rotate

# Export for backup (requires confirmation)
./vendor/bin/typo3 vault:master-key:export --confirm
```

#### Audit Operations
```bash
# View recent logs
./vendor/bin/typo3 vault:audit --limit=50

# Filter by secret
./vendor/bin/typo3 vault:audit --secret=my_api_key

# Filter by action and time
./vendor/bin/typo3 vault:audit --action=read --since="2024-01-01"

# Export for compliance
./vendor/bin/typo3 vault:audit \
    --since="2024-01-01" \
    --until="2024-01-31" \
    --format=json > january_audit.json
```

#### Migration Operations
```bash
# Scan for plaintext secrets
./vendor/bin/typo3 vault:migrate:scan

# Migrate from database column
./vendor/bin/typo3 vault:migrate:execute tx_myext.api_key \
    --identifier-pattern="myext_{uid}_key"

# Migrate from LocalConfiguration
./vendor/bin/typo3 vault:migrate:config MAIL.transport_smtp_password \
    --identifier=mail_smtp_password
```

---

## 3. Security by Default Principles

### 3.1 Secure Defaults (No Configuration Required)

| Feature | Default | Rationale |
|---------|---------|-----------|
| **Encryption** | AES-256-GCM | Industry standard, hardware accelerated |
| **Key per secret** | Enabled | Compromise isolation |
| **Audit logging** | Enabled | Compliance and forensics |
| **Access check** | Enabled | Every retrieve() validates permissions |
| **Expiry check** | Enabled | Expired secrets throw exception |
| **Persistent cache** | Disabled | Secrets never in Redis/APCu |
| **CLI access** | Disabled | Must be explicitly enabled |

### 3.2 First-Run Experience

When nr-vault is installed, it works immediately:

```php
// This works on first run with zero configuration
$vault->store('my_first_secret', $value);
```

**What happens behind the scenes:**

1. **Master Key**: Auto-generated and stored in `var/secrets/vault-master.key`
2. **Permissions**: File created with 0600 permissions
3. **Warning**: Log entry recommends moving key outside webroot
4. **Notification**: Backend flash message prompts proper configuration

```
+------------------------------------------------------------------+
| SECURITY NOTICE                                                   |
+------------------------------------------------------------------+
| nr-vault is using auto-generated master key in var/secrets/.      |
| For production, move the key outside the webroot:                 |
|                                                                   |
| mv var/secrets/vault-master.key /var/secrets/typo3/              |
| chmod 0400 /var/secrets/typo3/vault-master.key                   |
|                                                                   |
| Then update extension configuration.                              |
|                                                                   |
| [Configure Now] [Remind Later] [I understand the risks]          |
+------------------------------------------------------------------+
```

### 3.3 Progressive Security Enhancement

#### Level 1: Auto-Generated Key (Development)
- Zero configuration
- Key in var/secrets/
- Warning displayed
- Suitable for: Local development

#### Level 2: File-Based Key (Production)
- Key outside webroot
- Restrictive permissions
- Backed up separately
- Suitable for: Single server, VPS

#### Level 3: Environment Variable (Containers)
- Key injected at runtime
- Works with K8s secrets
- No file on disk
- Suitable for: Docker, Kubernetes

#### Level 4: External KMS (Enterprise)
- AWS KMS, HashiCorp Vault, Azure Key Vault
- Hardware-protected keys
- Full audit trail
- Suitable for: Regulated industries

### 3.4 Fail-Secure Behaviors

| Scenario | Behavior | Rationale |
|----------|----------|-----------|
| Master key not found | Exception thrown | Never fall back to plaintext |
| Decryption fails | Exception thrown | Indicates tampering or wrong key |
| Access denied | Exception + audit log | Never silently succeed |
| Secret expired | Exception thrown | Force renewal, don't serve stale |
| Database unavailable | Exception thrown | Don't expose secrets on error |
| Invalid identifier | ValidationException | Prevent injection attempts |

#### Error Handling Example

```php
try {
    $apiKey = $vault->retrieve('payment_gateway_key');
} catch (SecretNotFoundException $e) {
    // Secret doesn't exist - log and fail safely
    $this->logger->error('Payment gateway key not configured');
    throw new ConfigurationException('Payment gateway not configured');
} catch (AccessDeniedException $e) {
    // User lacks permission - already audit logged
    throw new AccessDeniedException('You cannot access payment credentials');
} catch (SecretExpiredException $e) {
    // Secret has expired - force renewal
    throw new ConfigurationException('Payment gateway key has expired');
} catch (EncryptionException $e) {
    // Crypto failure - possible tampering
    $this->logger->critical('Vault encryption failure', ['exception' => $e]);
    throw new SecurityException('Security error accessing credentials');
}
```

---

## 4. Integration Strategy

### 4.1 How Other Extensions Will Use the Vault

#### Pattern 1: Direct Dependency (Recommended)

```php
// ext_emconf.php
$EM_CONF[$_EXTKEY] = [
    'constraints' => [
        'depends' => [
            'nr_vault' => '1.0.0-1.99.99',
        ],
    ],
];

// Classes/Service/PaymentService.php
class PaymentService
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
    ) {}

    public function getGatewayCredentials(): array
    {
        return [
            'api_key' => $this->vault->retrieve('payment_api_key'),
            'api_secret' => $this->vault->retrieve('payment_api_secret'),
        ];
    }
}
```

#### Pattern 2: Optional Dependency (Fallback Support)

```php
// Classes/Service/PaymentService.php
class PaymentService
{
    public function __construct(
        private readonly ?VaultServiceInterface $vault = null,
    ) {}

    public function getApiKey(): string
    {
        // Try vault first
        if ($this->vault !== null) {
            try {
                $key = $this->vault->retrieve('payment_api_key');
                if ($key !== null) {
                    return $key;
                }
            } catch (\Exception $e) {
                // Vault unavailable, fall through
            }
        }

        // Fallback to extension configuration (with deprecation warning)
        $key = $this->extensionConfiguration->get('my_extension', 'apiKey');
        if ($key) {
            trigger_error(
                'Storing API keys in extension configuration is deprecated. Use nr_vault.',
                E_USER_DEPRECATED
            );
        }

        return $key;
    }
}
```

#### Pattern 3: TCA-Only Integration (Simplest)

```php
// Configuration/TCA/Overrides/tx_myext_config.php
$GLOBALS['TCA']['tx_myext_config']['columns']['api_key'] = [
    'label' => 'API Key',
    'config' => [
        'type' => 'user',
        'renderType' => 'vaultSecret',
        'parameters' => [
            'vaultIdentifier' => 'myext_config_{uid}_api_key',
        ],
    ],
];

// The extension reads from vault automatically via TCA DataHandler hooks
```

### 4.2 Backward Compatibility Considerations

#### For Extension Users

| Scenario | Compatibility Strategy |
|----------|----------------------|
| Extension updates to use vault | Migration wizard offered |
| User doesn't want vault | Fallback mode available |
| Existing secrets in DB | Migration tool preserves data |
| Multi-site with shared secrets | Site-aware identifiers |

#### For Extension Developers

```php
// VaultCompatibilityTrait - Add to existing services
trait VaultCompatibilityTrait
{
    private ?VaultServiceInterface $vault = null;

    public function injectVault(?VaultServiceInterface $vault = null): void
    {
        $this->vault = $vault;
    }

    protected function getSecret(string $identifier, string $fallbackConfig = ''): ?string
    {
        // Try vault
        if ($this->vault !== null && $this->vault->exists($identifier)) {
            return $this->vault->retrieve($identifier);
        }

        // Fallback to old config
        if ($fallbackConfig) {
            return $this->getConfigValue($fallbackConfig);
        }

        return null;
    }
}
```

### 4.3 Migration Tools and Documentation

#### Automated Migration Wizard

```
+------------------------------------------------------------------+
| MIGRATE TO VAULT WIZARD                              Step 1 of 4  |
+------------------------------------------------------------------+
| Select secrets to migrate:                                        |
|                                                                   |
| [x] tx_myext_config.api_key (12 records)                         |
|     Identifier pattern: myext_config_{uid}_api_key               |
|                                                                   |
| [x] LocalConfiguration: MAIL.transport_smtp_password             |
|     Identifier: mail_smtp_password                               |
|                                                                   |
| [ ] tx_newsletter.smtp_password (already migrated)               |
|                                                                   |
| [Next: Configure Access Control]                                  |
+------------------------------------------------------------------+
```

#### Migration Documentation Structure

```
Documentation/
├── Migration/
│   ├── Index.rst
│   ├── FromExtensionConfiguration.rst
│   ├── FromLocalConfiguration.rst
│   ├── FromDatabasePlaintext.rst
│   ├── FromEnvironmentVariables.rst
│   └── MultiSiteMigration.rst
```

---

## 5. Deployment Considerations

### 5.1 Single Server Installation

#### Typical LAMP/LEMP Stack

```
/var/www/html/typo3/
├── public/
├── var/
│   └── secrets/           # Auto-created, should be moved
│       └── vault-master.key
├── vendor/
└── config/

/var/secrets/typo3/        # Recommended location
└── vault-master.key       # 0400 permissions
```

**Installation Steps:**

```bash
# 1. Install extension
composer require netresearch/nr-vault

# 2. Generate master key
./vendor/bin/typo3 vault:master-key:generate > /var/secrets/typo3/vault-master.key

# 3. Secure the key
chmod 0400 /var/secrets/typo3/vault-master.key
chown www-data:www-data /var/secrets/typo3/vault-master.key

# 4. Configure extension
./vendor/bin/typo3 configuration:set EXTENSIONS/nr_vault/masterKeyPath /var/secrets/typo3/vault-master.key

# 5. Verify
./vendor/bin/typo3 vault:store test_secret --stdin <<< "test123"
./vendor/bin/typo3 vault:retrieve test_secret
./vendor/bin/typo3 vault:delete test_secret --force
```

### 5.2 Multi-Server / Container Deployments

#### Docker Compose Example

```yaml
version: '3.8'

services:
  typo3:
    image: my-typo3-image
    environment:
      - NR_VAULT_MASTER_KEY_FILE=/run/secrets/vault_master_key
    secrets:
      - vault_master_key
    volumes:
      - typo3_var:/var/www/html/var

secrets:
  vault_master_key:
    file: ./secrets/vault-master.key  # Or external secret
```

```php
// Configuration/system/additional.php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'file',
    'masterKeyPath' => getenv('NR_VAULT_MASTER_KEY_FILE'),
];
```

#### Kubernetes Deployment

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: typo3-vault-master-key
type: Opaque
data:
  master.key: <base64-encoded-32-byte-key>

---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: typo3
spec:
  template:
    spec:
      containers:
        - name: typo3
          env:
            - name: NR_VAULT_MASTER_KEY
              valueFrom:
                secretKeyRef:
                  name: typo3-vault-master-key
                  key: master.key
```

```php
// Configuration/system/additional.php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'masterKeyProvider' => 'env',
    'masterKeyEnvVar' => 'NR_VAULT_MASTER_KEY',
];
```

### 5.3 Cloud-Native Deployments

#### AWS with Secrets Manager

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'adapter' => 'aws',
    'awsRegion' => 'eu-west-1',
    'awsSecretPrefix' => 'typo3/production/',
    // Uses IAM role for authentication
];
```

#### HashiCorp Vault

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    'adapter' => 'hashicorp',
    'vaultAddress' => 'https://vault.example.com:8200',
    'vaultPath' => 'secret/data/typo3/',
    'vaultAuthMethod' => 'kubernetes',  // or 'token', 'approle'
];
```

### 5.4 CI/CD Pipeline Integration

#### GitLab CI Example

```yaml
stages:
  - test
  - deploy
  - post-deploy

variables:
  NR_VAULT_MASTER_KEY: $VAULT_MASTER_KEY  # From CI/CD variables

test:
  script:
    - composer install
    - ./vendor/bin/phpunit

deploy:
  script:
    - composer install --no-dev
    - rsync -avz --delete ./ $DEPLOY_TARGET/
  only:
    - main

rotate-secrets:
  stage: post-deploy
  script:
    - ./vendor/bin/typo3 vault:rotate payment_api_key --stdin < $NEW_PAYMENT_KEY
  only:
    - schedules
  when: manual
```

#### GitHub Actions Example

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Deploy
        env:
          NR_VAULT_MASTER_KEY: ${{ secrets.VAULT_MASTER_KEY }}
        run: |
          # Deploy steps...

      - name: Rotate secrets (optional)
        if: github.event_name == 'workflow_dispatch'
        run: |
          echo "${{ secrets.NEW_API_KEY }}" | \
            ./vendor/bin/typo3 vault:rotate api_key --stdin
```

---

## 6. Documentation and Training

### 6.1 User Documentation Needs

#### Quick Start Guide
- Installation (Composer, TER)
- First secret in under 5 minutes
- Understanding the UI

#### Backend User Guide
- Managing secrets via UI
- Understanding access controls
- Viewing audit logs
- Rotating secrets

#### Troubleshooting
- Common error messages
- Access denied issues
- Migration problems

### 6.2 Developer Documentation Needs

#### API Reference
- VaultServiceInterface methods
- Exception types and handling
- Event listeners

#### Integration Guide
- TCA field configuration
- Dependency injection setup
- Testing with vault

#### Migration Guide
- From plaintext database
- From LocalConfiguration
- From environment variables
- Multi-site considerations

#### Security Best Practices
- Identifier naming conventions
- Access control patterns
- Error handling patterns

### 6.3 Security Guidelines

#### For System Administrators

```rst
Master Key Security Checklist
=============================

Production Requirements
-----------------------

- [ ] Master key stored outside webroot
- [ ] File permissions: 0400
- [ ] File ownership: web server user only
- [ ] Master key backed up separately from database
- [ ] Backup stored in secure location (vault, HSM, encrypted)
- [ ] Master key not in version control
- [ ] Master key different per environment

Operational Security
--------------------

- [ ] Audit log review schedule established
- [ ] Alerts configured for access_denied events
- [ ] Master key rotation schedule (annual minimum)
- [ ] Secret rotation procedures documented
- [ ] Incident response plan includes vault compromise
```

#### For Developers

```rst
Secure Coding with nr-vault
===========================

DO:
- Use descriptive identifiers (myext_provider_123_api_key)
- Handle all exceptions explicitly
- Set appropriate access groups
- Use expiration for temporary tokens
- Log retrieval failures

DON'T:
- Log secret values
- Store secrets in session
- Cache secrets persistently
- Use generic identifiers
- Ignore access denied exceptions
```

---

## 7. Delivery Review

### 7.1 Attack Surface Minimization

| Attack Vector | Mitigation | Residual Risk |
|--------------|------------|---------------|
| SQL Injection | Encryption at rest | Low - Need master key |
| Database dump | Envelope encryption | Low - Need master key |
| Backup exposure | Separate key storage | Low - Two-component security |
| Memory dump | Request-only caching | Medium - Active secrets exposed |
| Insider threat | Access control + audit | Medium - Privileged users |
| Master key theft | File permissions, HSM option | Depends on deployment |
| Brute force | AES-256, no timing leaks | Very Low |

**Attack Surface Score: Significantly Reduced**

### 7.2 User Experience Validation

| User Type | Experience Goal | Achieved |
|-----------|-----------------|----------|
| Editor | "Just paste and save" | Yes - TCA field |
| Developer | "Simple API, good docs" | Yes - 5 core methods |
| Admin | "CLI tools, audit trail" | Yes - Full CLI suite |
| Security | "Compliance ready" | Yes - Audit exports |

**UX Validation Score: Meets Requirements**

### 7.3 Developer Experience Validation

| Criterion | Assessment |
|-----------|------------|
| Learning curve | Minimal - familiar patterns |
| Integration effort | Low - DI + TCA support |
| Documentation | Comprehensive |
| Error messages | Clear and actionable |
| Testing support | Mockable interfaces |

**DX Validation Score: Meets Requirements**

### 7.4 Operational Overhead

| Operation | Frequency | Effort | Automation |
|-----------|-----------|--------|------------|
| Initial setup | Once | Low | Wizard |
| Secret rotation | As needed | Very Low | CLI |
| Master key rotation | Annual | Low | CLI |
| Audit review | Periodic | Low | Export tools |
| Incident response | Rare | Medium | Documented |
| Backup/restore | With DB | Low | Standard tools |

**Operational Overhead: Acceptable**

---

## 8. Delivery Phases

### Phase 1: Core Infrastructure

**Goal:** Working encryption and storage with envelope encryption model

- [ ] Database schema and migrations (tx_nrvault_secret, tx_nrvault_audit_log, MM table)
- [ ] EncryptionService (AES-256-GCM via libsodium with XChaCha20-Poly1305 fallback)
- [ ] MasterKeyProvider implementations (file, env, derived)
- [ ] MasterKeyProviderFactory for provider resolution
- [ ] LocalEncryptionAdapter
- [ ] VaultService facade
- [ ] Unit tests for crypto operations

**Deliverable:** Secrets can be stored/retrieved via API

### Phase 2: Access Control & Audit

**Goal:** Security controls in place with tamper-evident logging

- [ ] AccessControlService with context-based scoping
- [ ] AuditLogService with hash chain for tamper detection
- [ ] CLI access control enforcement (allowCliAccess, cliAccessGroups)
- [ ] PSR-14 event dispatching (SecretStoredEvent, SecretRetrievedEvent, etc.)
- [ ] Exception hierarchy
- [ ] Integration tests

**Deliverable:** Multi-user access control working with full audit trail

### Phase 3: TYPO3 Integration

**Goal:** Backend UI complete with native TYPO3 patterns

- [ ] TCA vaultSecret field type with FormEngine integration
- [ ] Backend module (secrets list with filtering)
- [ ] Backend module (audit viewer with hash chain verification)
- [ ] DataHandler hooks for TCA field operations
- [ ] Functional tests

**Deliverable:** Full backend experience

### Phase 4: CLI & DevOps

**Goal:** Automation ready

- [ ] CLI commands (store, retrieve, rotate, delete, list)
- [ ] CLI commands (master-key operations)
- [ ] CLI commands (audit with hash chain verification)
- [ ] Scheduler tasks (expiry check, notifications)
- [ ] vault:init command for first-run setup

**Deliverable:** CLI automation complete

### Phase 5: Vault HTTP Client

**Goal:** Secure API calls without secret exposure

- [ ] VaultHttpClient implementation
- [ ] SecretPlacement enum (BearerAuth, Header, QueryParam, BodyField, etc.)
- [ ] VaultHttpResponse wrapper
- [ ] OAuth support with automatic token refresh
- [ ] Audit logging for HTTP calls
- [ ] Integration tests

**Deliverable:** Make authenticated API calls without exposing secrets

### Phase 6: Migration & Documentation

**Goal:** Adoption enabled

- [ ] Secret detection service
- [ ] Migration wizard (backend)
- [ ] Migration CLI commands
- [ ] Comprehensive documentation
- [ ] Security guide

**Deliverable:** Ready for production use

### Phase 7: External Adapters & Rust FFI (Future)

**Goal:** Enterprise features and zero-PHP exposure option

- [ ] HashiCorp Vault adapter
- [ ] AWS Secrets Manager adapter
- [ ] Azure Key Vault adapter
- [ ] Optional Rust FFI for zero-PHP-exposure encryption
- [ ] Enterprise documentation

**Deliverable:** Cloud-native options with hardware-level security

### Phase 8: Service Registry (Future)

**Goal:** Complete endpoint abstraction

- [ ] ServiceRegistryInterface
- [ ] Service configuration with URL templates
- [ ] Automatic credential + endpoint resolution
- [ ] Service health monitoring
- [ ] Environment-aware configuration

**Deliverable:** Abstract away both credentials AND endpoints

```php
// Future API
$response = $vault->service('stripe')->post('charges', $payload);
// Automatically resolves: URL + API version + credentials
```

---

## 9. User Journey Maps

### Journey 1: First-Time Installation

```
+---------------+     +---------------+     +---------------+
|   Install     |---->| Auto-Config   |---->| Store First   |
|   Extension   |     | (dev mode)    |     | Secret        |
+---------------+     +---------------+     +---------------+
        |                    |                     |
        v                    v                     v
   "composer        "Key auto-generated,   "Works immediately,
    require"         warning displayed"     see success message"
                            |
                            v
                    +---------------+
                    | Production    |
                    | Setup Prompt  |
                    +---------------+
                            |
                            v
                    "Move key, configure,
                     verify"
```

### Journey 2: Migrating Existing Extension

```
+---------------+     +---------------+     +---------------+
|   Run Scan    |---->| Review Found  |---->| Configure     |
|   Command     |     | Secrets       |     | Migration     |
+---------------+     +---------------+     +---------------+
        |                    |                     |
        v                    v                     v
   "vault:migrate   "47 secrets found   "Set identifier
    :scan"           across 5 tables"    patterns, owners"
        |                                          |
        +------------------------------------------+
                            |
                            v
                    +---------------+
                    | Execute       |
                    | Migration     |
                    +---------------+
                            |
                            v
                    +---------------+
                    | Verify &      |
                    | Cleanup       |
                    +---------------+
```

### Journey 3: Developer Integration

```
+---------------+     +---------------+     +---------------+
|   Add Vault   |---->| Inject        |---->| Use API       |
|   Dependency  |     | VaultService  |     | Methods       |
+---------------+     +---------------+     +---------------+
        |                    |                     |
        v                    v                     v
   "require         "DI in Services.yaml   "store(), retrieve()
    nr_vault"        or constructor"        in service class"
                                                   |
                                                   v
                                           +---------------+
                                           | Add TCA       |
                                           | Integration   |
                                           +---------------+
                                                   |
                                                   v
                                           "vaultSecret field
                                            in form"
```

### Journey 4: Secret Rotation (DevOps)

```
+---------------+     +---------------+     +---------------+
|   Generate    |---->| Rotate via    |---->| Verify in     |
|   New Secret  |     | CLI           |     | Application   |
+---------------+     +---------------+     +---------------+
        |                    |                     |
        v                    v                     v
   "Create new API   "echo $KEY |         "Check application
    key at provider"  vault:rotate"        still works"
                            |
                            v
                    +---------------+
                    | Check Audit   |
                    | Log           |
                    +---------------+
                            |
                            v
                    "Confirm rotation
                     logged properly"
```

---

## 10. Success Metrics

### Adoption Metrics
- Extensions using nr-vault API
- Secrets migrated from plaintext
- Active installations (TER/Packagist)

### Security Metrics
- Zero plaintext secrets in adopting projects
- Audit log completeness
- Successful incident responses

### User Satisfaction Metrics
- Time to first secret (< 5 minutes)
- Integration time for developers (< 1 hour)
- Support tickets per installation

---

## Appendix A: Configuration Reference

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    // Storage adapter: 'local', 'hashicorp', 'aws', 'azure'
    'adapter' => 'local',

    // Master key provider: 'file', 'env', 'derived', 'aws_kms'
    'masterKeyProvider' => 'file',

    // File provider settings
    'masterKeyPath' => '/var/secrets/typo3/vault-master.key',

    // Environment provider settings
    'masterKeyEnvVar' => 'NR_VAULT_MASTER_KEY',

    // Derived provider settings
    'derivedKeySaltPath' => '/var/secrets/typo3/vault-salt.key',

    // Audit settings
    'auditLogRetention' => 365,  // days, 0 = forever
    'auditLogExternalSyslog' => false,

    // CLI settings
    'allowCliAccess' => true,
    'cliAccessGroups' => [],  // empty = all accessible secrets

    // Cache settings
    'cacheEnabled' => true,  // request-scoped only

    // HashiCorp Vault adapter settings
    'hashicorp' => [
        'address' => 'https://vault.example.com:8200',
        'path' => 'secret/data/typo3/',
        'authMethod' => 'token',  // 'token', 'approle', 'kubernetes'
        'token' => '',  // or env var
    ],

    // AWS adapter settings
    'aws' => [
        'region' => 'eu-west-1',
        'secretPrefix' => 'typo3/',
        // Uses default credential chain
    ],
];
```

---

## Appendix B: Event Reference

```php
// Events dispatched by VaultService
namespace Netresearch\NrVault\Event;

// After secret is created or updated
final class SecretStoredEvent
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $version,
        public readonly ?int $ownerUid,
    ) {}
}

// After secret is read (value not included)
final class SecretRetrievedEvent
{
    public function __construct(
        public readonly string $identifier,
    ) {}
}

// After secret is deleted
final class SecretDeletedEvent
{
    public function __construct(
        public readonly string $identifier,
    ) {}
}

// After secret is rotated
final class SecretRotatedEvent
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $previousVersion,
        public readonly int $newVersion,
    ) {}
}

// After master key is rotated
final class MasterKeyRotatedEvent
{
    public function __construct(
        public readonly int $secretsReEncrypted,
    ) {}
}

// When access check fails
final class AccessDeniedEvent
{
    public function __construct(
        public readonly string $identifier,
        public readonly int $userUid,
        public readonly string $reason,
    ) {}
}
```

---

---

*Document Version: 2.0*
*Compatible with: TYPO3 v14.0+ | PHP 8.5+*
