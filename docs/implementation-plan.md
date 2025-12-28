# nr-vault Implementation Plan
## TYPO3 v14 | PHP 8.5+ | Secure Secrets Management Extension

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Technical Architecture](#2-technical-architecture)
3. [Database Schema](#3-database-schema)
4. [Directory Structure](#4-directory-structure)
5. [Service Configuration](#5-service-configuration)
6. [Core Components](#6-core-components)
7. [TCA Integration](#7-tca-integration)
8. [Backend Module](#8-backend-module)
9. [CLI Commands & HTTP Client](#9-cli-commands)
   - [9.5 Vault HTTP Client](#95-vault-http-client)
   - [9.6 Service Registry (Future)](#96-service-registry-future-enhancement)
10. [Event System](#10-event-system)
11. [External Adapters](#11-external-adapters)
12. [Testing Strategy](#12-testing-strategy)
13. [Implementation Phases](#13-implementation-phases)
14. [File-by-File Implementation](#14-file-by-file-implementation)

---

## 1. Executive Summary

### 1.1 Extension Overview

| Attribute | Value |
|-----------|-------|
| **Extension Key** | `nr_vault` |
| **Vendor** | Netresearch DTT GmbH |
| **Composer Name** | `netresearch/nr-vault` |
| **TYPO3 Compatibility** | v14.0+ |
| **PHP Requirement** | ^8.5 |
| **License** | GPL-2.0-or-later |

### 1.2 Core Dependencies

```json
{
    "require": {
        "php": "^8.5",
        "typo3/cms-core": "^14.0",
        "typo3/cms-backend": "^14.0",
        "typo3/cms-extbase": "^14.0",
        "ext-sodium": "*"
    },
    "require-dev": {
        "typo3/testing-framework": "^9.0",
        "phpstan/phpstan": "^2.0",
        "phpunit/phpunit": "^11.0"
    },
    "suggest": {
        "aws/aws-sdk-php": "For AWS Secrets Manager integration",
        "guzzlehttp/guzzle": "For HashiCorp Vault integration"
    }
}
```

### 1.3 Key Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Encryption Algorithm | AES-256-GCM via libsodium | Industry standard, hardware acceleration, PHP native |
| Encryption Pattern | Envelope encryption with per-secret DEKs | Cryptographic isolation, efficient key rotation |
| Access Control | TYPO3 Backend User Groups (RBAC) | Native to TYPO3, familiar to users |
| Storage | TYPO3 Database (default) + External Adapters | Flexible for different hosting environments |
| Caching | Request-scoped only | Security: no persistent plaintext storage |

---

## 2. Technical Architecture

### 2.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────────────┐
│                              TYPO3 Backend                               │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────────┐         │
│  │  TCA Field      │  │ Backend Module  │  │  CLI Commands   │         │
│  │  (vaultSecret)  │  │  (Secrets Mgr)  │  │                 │         │
│  └────────┬────────┘  └────────┬────────┘  └────────┬────────┘         │
│           │                    │                     │                  │
│           └────────────────────┼─────────────────────┘                  │
│                                ▼                                         │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                        VaultService                                │  │
│  │  ┌─────────────────────────────────────────────────────────────┐  │  │
│  │  │  store() │ retrieve() │ rotate() │ delete() │ list()        │  │  │
│  │  └─────────────────────────────────────────────────────────────┘  │  │
│  └───────────────────────────────────────────────────────────────────┘  │
│                                │                                         │
│      ┌─────────────────────────┼─────────────────────────┐              │
│      ▼                         ▼                         ▼              │
│  ┌────────────────┐   ┌────────────────┐   ┌────────────────┐          │
│  │ AccessControl  │   │ EncryptionSvc  │   │  AuditLogSvc   │          │
│  │ Service        │   │                │   │                │          │
│  └────────────────┘   └───────┬────────┘   └────────────────┘          │
│                               │                                          │
│                               ▼                                          │
│                    ┌────────────────────┐                               │
│                    │ MasterKeyProvider  │                               │
│                    │   (pluggable)      │                               │
│                    └────────────────────┘                               │
│                               │                                          │
│                               ▼                                          │
│                    ┌────────────────────┐                               │
│                    │   VaultAdapter     │                               │
│                    │   (pluggable)      │                               │
│                    └────────────────────┘                               │
│                               │                                          │
└───────────────────────────────┼──────────────────────────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
┌──────────────┐       ┌──────────────┐       ┌──────────────┐
│    Local     │       │  HashiCorp   │       │     AWS      │
│   Database   │       │    Vault     │       │   Secrets    │
└──────────────┘       └──────────────┘       └──────────────┘
```

### 2.2 Encryption Flow

```
STORE SECRET:
─────────────
                    ┌─────────────────────────────┐
     plaintext ────►│  Generate DEK (32 bytes)    │
     secret         │  sodium_crypto_secretbox_   │
                    │  keygen()                   │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │  Encrypt secret with DEK    │
                    │  sodium_crypto_aead_        │
                    │  aes256gcm_encrypt()        │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
     master ───────►│  Encrypt DEK with master    │
     key            │  sodium_crypto_aead_        │
                    │  aes256gcm_encrypt()        │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │  Store in database:         │
                    │  - encrypted_dek            │
                    │  - encrypted_value          │
                    │  - nonces (2x 12 bytes)     │
                    └─────────────────────────────┘


RETRIEVE SECRET:
────────────────
                    ┌─────────────────────────────┐
     master ───────►│  Decrypt DEK with master    │
     key            │  sodium_crypto_aead_        │
                    │  aes256gcm_decrypt()        │
                    └──────────────┬──────────────┘
                                   │
                    ┌──────────────▼──────────────┐
                    │  Decrypt secret with DEK    │
                    │  sodium_crypto_aead_        │
                    │  aes256gcm_decrypt()        │
                    └──────────────┬──────────────┘
                                   │
                                   ▼
                              plaintext
                              secret
```

### 2.3 Class Hierarchy

```
Netresearch\NrVault\
├── Service\
│   ├── VaultService                    # Main facade
│   ├── VaultServiceInterface           # Public API contract
│   ├── EncryptionService               # Crypto operations
│   ├── EncryptionServiceInterface
│   ├── AccessControlService            # Permission checks
│   ├── AccessControlServiceInterface
│   ├── AuditLogService                 # Audit trail
│   └── AuditLogServiceInterface
│
├── Http\
│   ├── VaultHttpClientInterface        # HTTP client contract
│   ├── VaultHttpClientFactory          # Creates PHP or Rust client
│   ├── PhpVaultHttpClient              # Pure PHP implementation (default)
│   ├── RustVaultHttpClient             # FFI+Rust implementation (optional)
│   ├── VaultHttpResponse               # Response DTO
│   ├── SecretPlacement                 # Enum: Bearer, Header, Body, etc.
│   └── SecretBinding                   # Value object for secret config
│
├── Adapter\
│   ├── VaultAdapterInterface           # Storage abstraction
│   ├── LocalDatabaseAdapter            # Default: TYPO3 DB
│   ├── HashiCorpVaultAdapter           # HashiCorp Vault
│   └── AwsSecretsManagerAdapter        # AWS Secrets Manager
│
├── KeyProvider\
│   ├── MasterKeyProviderInterface      # Key provider abstraction
│   ├── FileKeyProvider                 # Key from file
│   ├── EnvironmentKeyProvider          # Key from env var
│   └── DerivedKeyProvider              # Key from encryptionKey
│
├── Domain\
│   ├── Model\
│   │   ├── Secret                      # Secret entity
│   │   └── AuditLogEntry               # Audit log entity
│   └── Repository\
│       ├── SecretRepository            # Secret persistence
│       └── AuditLogRepository          # Audit persistence
│
├── Form\
│   ├── Element\
│   │   └── VaultSecretElement          # TCA field renderer
│   └── FieldWizard\
│       └── VaultSecretWizard           # Field wizard
│
├── Controller\
│   └── VaultController                 # Backend module
│
├── Command\
│   ├── StoreCommand                    # vault:store
│   ├── RetrieveCommand                 # vault:retrieve
│   ├── RotateCommand                   # vault:rotate
│   ├── DeleteCommand                   # vault:delete
│   ├── ListCommand                     # vault:list
│   ├── AuditCommand                    # vault:audit
│   ├── MasterKeyGenerateCommand        # vault:master-key:generate
│   └── MasterKeyRotateCommand          # vault:master-key:rotate
│
├── DataHandler\
│   ├── VaultSecretDataHandler          # TCA save/delete hooks
│   └── VaultSecretDataHandlerInterface
│
├── Event\
│   ├── SecretStoredEvent
│   ├── SecretRetrievedEvent
│   ├── SecretRotatedEvent
│   ├── SecretDeletedEvent
│   ├── AccessDeniedEvent
│   └── MasterKeyRotatedEvent
│
├── Exception\
│   ├── VaultException                  # Base exception
│   ├── SecretNotFoundException
│   ├── AccessDeniedException
│   ├── EncryptionException
│   ├── DecryptionException
│   ├── SecretExpiredException
│   ├── InvalidIdentifierException
│   ├── MasterKeyException
│   └── AdapterException
│
├── Utility\
│   ├── IdentifierValidator             # Validate secret identifiers
│   └── SecureRandomGenerator           # CSPRNG wrapper
│
└── Configuration\
    └── VaultConfiguration              # Extension configuration DTO
```

---

## 3. Database Schema

### 3.1 Secret Storage Table

```sql
CREATE TABLE tx_nrvault_secret (
    -- TYPO3 system fields
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    deleted tinyint(4) unsigned DEFAULT 0 NOT NULL,

    -- Secret identification
    identifier varchar(255) DEFAULT '' NOT NULL,

    -- Encrypted data (base64 encoded)
    encrypted_dek text,              -- Encrypted Data Encryption Key
    dek_nonce varchar(32) DEFAULT '' NOT NULL,   -- 24 bytes base64 = 32 chars
    encrypted_value mediumtext,      -- Encrypted secret value
    value_nonce varchar(32) DEFAULT '' NOT NULL, -- 24 bytes base64 = 32 chars

    -- Change detection (detect changes without decrypting)
    value_checksum char(64) DEFAULT '' NOT NULL, -- SHA-256 of plaintext

    -- Versioning
    version int(11) unsigned DEFAULT 1 NOT NULL,
    last_rotated_at int(11) unsigned DEFAULT 0 NOT NULL,

    -- Access control
    owner_uid int(11) unsigned DEFAULT 0 NOT NULL,
    allowed_groups text,             -- Comma-separated group UIDs
    context varchar(50) DEFAULT '' NOT NULL,     -- Permission scoping: "hr", "marketing", "api"

    -- Metadata
    description text,
    expires_at int(11) unsigned DEFAULT 0,       -- Unix timestamp, 0 = never
    metadata text,                   -- JSON for custom metadata

    -- Indexes
    PRIMARY KEY (uid),
    UNIQUE KEY identifier (identifier),
    KEY owner_uid (owner_uid),
    KEY expires_at (expires_at),
    KEY context (context),
    KEY pid (pid)
);
```

### 3.2 Audit Log Table

```sql
CREATE TABLE tx_nrvault_audit_log (
    -- TYPO3 system fields
    uid int(11) unsigned NOT NULL AUTO_INCREMENT,
    pid int(11) unsigned DEFAULT 0 NOT NULL,
    crdate int(11) unsigned DEFAULT 0 NOT NULL,

    -- Log entry identification
    entry_id varchar(36) DEFAULT '' NOT NULL,  -- UUID v7

    -- Event details
    action varchar(32) DEFAULT '' NOT NULL,     -- store, retrieve, rotate, delete, access_denied
    secret_identifier varchar(255) DEFAULT '' NOT NULL,
    secret_version int(11) unsigned DEFAULT 0 NOT NULL,
    outcome varchar(16) DEFAULT 'success' NOT NULL, -- success, failure, denied

    -- Actor information
    actor_type varchar(16) DEFAULT 'user' NOT NULL, -- user, system, cli, scheduler
    actor_uid int(11) unsigned DEFAULT 0 NOT NULL,
    actor_username varchar(255) DEFAULT '' NOT NULL,
    actor_role varchar(100) DEFAULT '' NOT NULL,   -- BE group name for audit clarity

    -- Context
    ip_address varchar(45) DEFAULT '' NOT NULL,  -- IPv6 ready
    user_agent text,
    request_id varchar(36) DEFAULT '' NOT NULL,
    cli_command varchar(255) DEFAULT '' NOT NULL,

    -- Compliance fields
    reason text,                                -- Why was this action performed? (required for rotate/delete)
    hash_before char(64) DEFAULT '' NOT NULL,   -- Checksum before change
    hash_after char(64) DEFAULT '' NOT NULL,    -- Checksum after change

    -- Hash chain for tamper detection (Tier 3)
    previous_hash varchar(64) DEFAULT '' NOT NULL,
    entry_hash varchar(64) DEFAULT '' NOT NULL,

    -- Additional context
    context text,  -- JSON

    -- Indexes
    PRIMARY KEY (uid),
    KEY entry_id (entry_id),
    KEY action (action),
    KEY secret_identifier (secret_identifier),
    KEY actor_uid (actor_uid),
    KEY crdate (crdate),
    KEY pid (pid)
);
```

### 3.3 TCA Configuration

```php
// Configuration/TCA/tx_nrvault_secret.php
<?php

return [
    'ctrl' => [
        'title' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang_db.xlf:tx_nrvault_secret',
        'label' => 'identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'adminOnly' => true,
        'rootLevel' => 1,
        'hideTable' => true, // Managed via backend module only
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:nr_vault/Resources/Public/Icons/secret.svg',
    ],
    'columns' => [
        'identifier' => [
            'label' => 'Identifier',
            'config' => [
                'type' => 'input',
                'size' => 50,
                'max' => 255,
                'eval' => 'required,trim,uniqueInPid',
                'readOnly' => true,
            ],
        ],
        'encrypted_dek' => [
            'label' => 'Encrypted DEK',
            'config' => [
                'type' => 'passthrough',
            ],
        ],
        'dek_nonce' => [
            'config' => ['type' => 'passthrough'],
        ],
        'encrypted_value' => [
            'config' => ['type' => 'passthrough'],
        ],
        'value_nonce' => [
            'config' => ['type' => 'passthrough'],
        ],
        'version' => [
            'label' => 'Version',
            'config' => [
                'type' => 'number',
                'readOnly' => true,
            ],
        ],
        'owner_uid' => [
            'label' => 'Owner',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'be_users',
                'readOnly' => true,
            ],
        ],
        'allowed_groups' => [
            'label' => 'Allowed Groups',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'foreign_table' => 'be_groups',
                'MM' => 'tx_nrvault_secret_begroups_mm',
            ],
        ],
        'description' => [
            'label' => 'Description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
            ],
        ],
        'expires_at' => [
            'label' => 'Expires At',
            'config' => [
                'type' => 'datetime',
                'default' => 0,
            ],
        ],
        'metadata' => [
            'config' => ['type' => 'passthrough'],
        ],
    ],
    'types' => [
        '1' => [
            'showitem' => 'identifier, description, version, owner_uid, allowed_groups, expires_at',
        ],
    ],
];
```

---

## 4. Directory Structure

```
nr_vault/
├── Classes/
│   ├── Adapter/
│   │   ├── VaultAdapterInterface.php
│   │   ├── LocalDatabaseAdapter.php
│   │   ├── HashiCorpVaultAdapter.php
│   │   └── AwsSecretsManagerAdapter.php
│   │
│   ├── Command/
│   │   ├── StoreCommand.php
│   │   ├── RetrieveCommand.php
│   │   ├── RotateCommand.php
│   │   ├── DeleteCommand.php
│   │   ├── ListCommand.php
│   │   ├── AuditCommand.php
│   │   ├── MasterKeyGenerateCommand.php
│   │   ├── MasterKeyRotateCommand.php
│   │   └── MigrateCommand.php
│   │
│   ├── Configuration/
│   │   └── VaultConfiguration.php
│   │
│   ├── Controller/
│   │   └── VaultController.php
│   │
│   ├── Hook/
│   │   └── DataHandlerHook.php
│   │
│   ├── Scheduler/
│   │   ├── ExpiredSecretsTask.php
│   │   └── OAuthTokenRefreshTask.php
│   │
│   ├── Domain/
│   │   ├── Model/
│   │   │   ├── Secret.php
│   │   │   └── AuditLogEntry.php
│   │   └── Repository/
│   │       ├── SecretRepository.php
│   │       └── AuditLogRepository.php
│   │
│   ├── Event/
│   │   ├── SecretStoredEvent.php
│   │   ├── SecretRetrievedEvent.php
│   │   ├── SecretRotatedEvent.php
│   │   ├── SecretDeletedEvent.php
│   │   ├── AccessDeniedEvent.php
│   │   └── MasterKeyRotatedEvent.php
│   │
│   ├── Exception/
│   │   ├── VaultException.php
│   │   ├── SecretNotFoundException.php
│   │   ├── AccessDeniedException.php
│   │   ├── EncryptionException.php
│   │   ├── DecryptionException.php
│   │   ├── SecretExpiredException.php
│   │   ├── InvalidIdentifierException.php
│   │   ├── MasterKeyException.php
│   │   └── AdapterException.php
│   │
│   ├── Form/
│   │   ├── Element/
│   │   │   └── VaultSecretElement.php
│   │   └── FieldWizard/
│   │       └── VaultSecretWizard.php
│   │
│   ├── Http/
│   │   ├── VaultHttpClientInterface.php
│   │   ├── VaultHttpClientFactory.php
│   │   ├── PhpVaultHttpClient.php
│   │   ├── RustVaultHttpClient.php
│   │   ├── VaultHttpResponse.php
│   │   ├── SecretPlacement.php
│   │   ├── SecretBinding.php
│   │   └── Exception/
│   │       ├── VaultHttpException.php
│   │       └── SecretInjectionException.php
│   │
│   ├── KeyProvider/
│   │   ├── MasterKeyProviderInterface.php
│   │   ├── FileKeyProvider.php
│   │   ├── EnvironmentKeyProvider.php
│   │   └── DerivedKeyProvider.php
│   │
│   ├── Middleware/
│   │   └── RequestIdMiddleware.php
│   │
│   ├── Service/
│   │   ├── VaultService.php
│   │   ├── VaultServiceInterface.php
│   │   ├── EncryptionService.php
│   │   ├── EncryptionServiceInterface.php
│   │   ├── AccessControlService.php
│   │   ├── AccessControlServiceInterface.php
│   │   ├── AuditLogService.php
│   │   └── AuditLogServiceInterface.php
│   │
│   └── Utility/
│       ├── IdentifierValidator.php
│       └── SecureRandomGenerator.php
│
├── Configuration/
│   ├── Backend/
│   │   ├── Modules.php
│   │   └── Routes.php
│   │
│   ├── Icons.php
│   │
│   ├── RequestMiddlewares.php
│   │
│   ├── Services.yaml
│   │
│   ├── TCA/
│   │   ├── tx_nrvault_secret.php
│   │   └── tx_nrvault_audit_log.php
│   │
│   └── TsConfig/
│       └── Page/
│           └── BackendLayouts.tsconfig
│
├── Documentation/
│   ├── Index.rst
│   ├── Introduction/
│   ├── Installation/
│   ├── Configuration/
│   ├── DeveloperGuide/
│   │   ├── Api.rst
│   │   ├── TcaIntegration.rst
│   │   └── Events.rst
│   ├── Security/
│   └── Migration/
│
├── Resources/
│   ├── Private/
│   │   ├── Language/
│   │   │   ├── locallang.xlf
│   │   │   ├── locallang_db.xlf
│   │   │   └── locallang_mod.xlf
│   │   │
│   │   ├── Layouts/
│   │   │   └── Backend/
│   │   │       └── Default.html
│   │   │
│   │   ├── Partials/
│   │   │   └── Backend/
│   │   │       ├── SecretList.html
│   │   │       ├── SecretForm.html
│   │   │       └── AuditLog.html
│   │   │
│   │   └── Templates/
│   │       └── Backend/
│   │           ├── Index.html
│   │           ├── Show.html
│   │           ├── Create.html
│   │           ├── Edit.html
│   │           └── Audit.html
│   │
│   └── Public/
│       ├── Icons/
│       │   ├── Extension.svg
│       │   ├── secret.svg
│       │   └── module-vault.svg
│       │
│       ├── Css/
│       │   └── backend.css
│       │
│       └── JavaScript/
│           └── VaultSecretField.js
│
├── Tests/
│   ├── Functional/
│   │   ├── Service/
│   │   │   ├── VaultServiceTest.php
│   │   │   ├── EncryptionServiceTest.php
│   │   │   └── AccessControlServiceTest.php
│   │   │
│   │   ├── Adapter/
│   │   │   └── LocalDatabaseAdapterTest.php
│   │   │
│   │   └── Command/
│   │       ├── StoreCommandTest.php
│   │       └── RetrieveCommandTest.php
│   │
│   └── Unit/
│       ├── Service/
│       │   └── EncryptionServiceTest.php
│       │
│       ├── KeyProvider/
│       │   ├── FileKeyProviderTest.php
│       │   ├── EnvironmentKeyProviderTest.php
│       │   └── DerivedKeyProviderTest.php
│       │
│       ├── Utility/
│       │   └── IdentifierValidatorTest.php
│       │
│       └── Domain/
│           └── Model/
│               └── SecretTest.php
│
├── composer.json
├── ext_emconf.php
├── ext_localconf.php
├── ext_tables.sql
└── ext_tables.php
```

---

## 5. Service Configuration

### 5.1 Services.yaml

```yaml
# Configuration/Services.yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  Netresearch\NrVault\:
    resource: '../Classes/*'
    exclude:
      - '../Classes/Domain/Model/*'
      - '../Classes/Exception/*'
      - '../Classes/Event/*'

  # Public API Services
  Netresearch\NrVault\Service\VaultServiceInterface:
    alias: Netresearch\NrVault\Service\VaultService
    public: true

  Netresearch\NrVault\Service\VaultService:
    public: true
    arguments:
      $adapter: '@Netresearch\NrVault\Adapter\VaultAdapterInterface'
      $encryptionService: '@Netresearch\NrVault\Service\EncryptionServiceInterface'
      $accessControlService: '@Netresearch\NrVault\Service\AccessControlServiceInterface'
      $auditLogService: '@Netresearch\NrVault\Service\AuditLogServiceInterface'
      $eventDispatcher: '@Psr\EventDispatcher\EventDispatcherInterface'
      $configuration: '@Netresearch\NrVault\Configuration\VaultConfiguration'

  # Encryption Service
  Netresearch\NrVault\Service\EncryptionServiceInterface:
    alias: Netresearch\NrVault\Service\EncryptionService

  Netresearch\NrVault\Service\EncryptionService:
    arguments:
      $masterKeyProvider: '@Netresearch\NrVault\KeyProvider\MasterKeyProviderInterface'

  # Access Control
  Netresearch\NrVault\Service\AccessControlServiceInterface:
    alias: Netresearch\NrVault\Service\AccessControlService

  # Audit Log
  Netresearch\NrVault\Service\AuditLogServiceInterface:
    alias: Netresearch\NrVault\Service\AuditLogService

  # Adapter Selection (conditionally configured)
  Netresearch\NrVault\Adapter\VaultAdapterInterface:
    alias: Netresearch\NrVault\Adapter\LocalDatabaseAdapter

  Netresearch\NrVault\Adapter\LocalDatabaseAdapter:
    arguments:
      $connectionPool: '@TYPO3\CMS\Core\Database\ConnectionPool'

  # Master Key Provider (conditionally configured via factory)
  Netresearch\NrVault\KeyProvider\MasterKeyProviderInterface:
    factory: ['@Netresearch\NrVault\KeyProvider\MasterKeyProviderFactory', 'create']

  Netresearch\NrVault\KeyProvider\MasterKeyProviderFactory:
    arguments:
      $configuration: '@Netresearch\NrVault\Configuration\VaultConfiguration'

  # Configuration DTO
  Netresearch\NrVault\Configuration\VaultConfiguration:
    factory: ['@Netresearch\NrVault\Configuration\VaultConfigurationFactory', 'create']

  Netresearch\NrVault\Configuration\VaultConfigurationFactory: ~

  # Form Elements
  Netresearch\NrVault\Form\Element\VaultSecretElement:
    tags:
      - name: backend.form.element
        identifier: vaultSecret

  # Commands
  Netresearch\NrVault\Command\StoreCommand:
    tags:
      - name: console.command
        command: 'vault:store'
        description: 'Store a secret in the vault'

  Netresearch\NrVault\Command\RetrieveCommand:
    tags:
      - name: console.command
        command: 'vault:retrieve'
        description: 'Retrieve a secret from the vault'

  Netresearch\NrVault\Command\RotateCommand:
    tags:
      - name: console.command
        command: 'vault:rotate'
        description: 'Rotate a secret value'

  Netresearch\NrVault\Command\DeleteCommand:
    tags:
      - name: console.command
        command: 'vault:delete'
        description: 'Delete a secret from the vault'

  Netresearch\NrVault\Command\ListCommand:
    tags:
      - name: console.command
        command: 'vault:list'
        description: 'List secrets in the vault'

  Netresearch\NrVault\Command\AuditCommand:
    tags:
      - name: console.command
        command: 'vault:audit'
        description: 'View audit log entries'

  Netresearch\NrVault\Command\MasterKeyGenerateCommand:
    tags:
      - name: console.command
        command: 'vault:master-key:generate'
        description: 'Generate a new master key'

  Netresearch\NrVault\Command\MasterKeyRotateCommand:
    tags:
      - name: console.command
        command: 'vault:master-key:rotate'
        description: 'Rotate the master key and re-encrypt all DEKs'

  Netresearch\NrVault\Command\MigrateCommand:
    tags:
      - name: console.command
        command: 'vault:migrate'
        description: 'Migrate plaintext secrets to vault'
```

---

## 6. Core Components

### 6.1 VaultServiceInterface

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\InvalidIdentifierException;
use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\SecretNotFoundException;

interface VaultServiceInterface
{
    /**
     * Store a secret in the vault.
     *
     * @param string $identifier Unique identifier for the secret
     * @param string $secret The secret value to store
     * @param array{
     *     owner?: int,
     *     groups?: int[],
     *     description?: string,
     *     expires?: \DateTimeInterface|null,
     *     metadata?: array<string, mixed>
     * } $options Storage options
     *
     * @throws InvalidIdentifierException If identifier format is invalid
     * @throws EncryptionException If encryption fails
     * @throws AccessDeniedException If user cannot store secrets
     */
    public function store(string $identifier, string $secret, array $options = []): void;

    /**
     * Retrieve a secret from the vault.
     *
     * @param string $identifier The secret identifier
     * @return string|null The decrypted secret value, or null if not found
     *
     * @throws AccessDeniedException If user lacks access to this secret
     * @throws SecretExpiredException If the secret has expired
     * @throws EncryptionException If decryption fails
     */
    public function retrieve(string $identifier): ?string;

    /**
     * Check if a secret exists (without decrypting).
     *
     * @param string $identifier The secret identifier
     * @return bool True if secret exists and user has access
     */
    public function exists(string $identifier): bool;

    /**
     * Rotate a secret value (update with versioning).
     *
     * @param string $identifier The secret identifier
     * @param string $newSecret The new secret value
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If user cannot modify this secret
     * @throws EncryptionException If encryption fails
     */
    public function rotate(string $identifier, string $newSecret): void;

    /**
     * Delete a secret from the vault.
     *
     * @param string $identifier The secret identifier
     *
     * @throws SecretNotFoundException If secret doesn't exist
     * @throws AccessDeniedException If user cannot delete this secret
     */
    public function delete(string $identifier): void;

    /**
     * List secrets matching criteria.
     *
     * @param array{
     *     prefix?: string,
     *     owner?: int,
     *     groups?: int[],
     *     includeExpired?: bool
     * } $filters Filter criteria
     *
     * @return Secret[] Array of Secret objects (without decrypted values)
     */
    public function list(array $filters = []): array;

    /**
     * Get secret metadata (without decrypting value).
     *
     * @param string $identifier The secret identifier
     * @return array{
     *     identifier: string,
     *     version: int,
     *     owner: int,
     *     groups: int[],
     *     description: string|null,
     *     expiresAt: \DateTimeInterface|null,
     *     createdAt: \DateTimeInterface,
     *     updatedAt: \DateTimeInterface,
     *     metadata: array<string, mixed>
     * }|null
     *
     * @throws AccessDeniedException If user cannot access this secret
     */
    public function getMetadata(string $identifier): ?array;
}
```

### 6.2 EncryptionService

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Exception\DecryptionException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\KeyProvider\MasterKeyProviderInterface;

final class EncryptionService implements EncryptionServiceInterface
{
    private const NONCE_LENGTH = SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES; // 12 bytes
    private const KEY_LENGTH = SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES;   // 32 bytes

    public function __construct(
        private readonly MasterKeyProviderInterface $masterKeyProvider,
    ) {
        if (!sodium_crypto_aead_aes256gcm_is_available()) {
            throw new \RuntimeException(
                'AES-256-GCM is not available on this system. ' .
                'Please ensure your CPU supports AES-NI or use XSalsa20-Poly1305.'
            );
        }
    }

    /**
     * Encrypt a secret using envelope encryption.
     *
     * @return array{
     *     encrypted_dek: string,
     *     dek_nonce: string,
     *     encrypted_value: string,
     *     value_nonce: string
     * }
     */
    public function encrypt(string $plaintext, string $identifier): array
    {
        try {
            // Generate Data Encryption Key (DEK) - 32 bytes for AES-256-GCM
            $dek = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_KEYBYTES);

            // Generate nonces
            $dekNonce = random_bytes(self::NONCE_LENGTH);
            $valueNonce = random_bytes(self::NONCE_LENGTH);

            // Get master key
            $masterKey = $this->masterKeyProvider->getMasterKey();

            // Encrypt DEK with master key (identifier as additional authenticated data)
            $encryptedDek = sodium_crypto_aead_aes256gcm_encrypt(
                $dek,
                $identifier,
                $dekNonce,
                $masterKey
            );

            // Encrypt value with DEK
            $encryptedValue = sodium_crypto_aead_aes256gcm_encrypt(
                $plaintext,
                $identifier,
                $valueNonce,
                $dek
            );

            // Securely erase sensitive data from memory
            sodium_memzero($dek);
            sodium_memzero($masterKey);
            sodium_memzero($plaintext);

            return [
                'encrypted_dek' => base64_encode($encryptedDek),
                'dek_nonce' => base64_encode($dekNonce),
                'encrypted_value' => base64_encode($encryptedValue),
                'value_nonce' => base64_encode($valueNonce),
            ];
        } catch (\SodiumException $e) {
            throw new EncryptionException(
                'Failed to encrypt secret: ' . $e->getMessage(),
                1700000001,
                $e
            );
        }
    }

    /**
     * Decrypt a secret using envelope encryption.
     */
    public function decrypt(
        string $encryptedDek,
        string $dekNonce,
        string $encryptedValue,
        string $valueNonce,
        string $identifier,
    ): string {
        try {
            // Decode from base64
            $encryptedDekBinary = base64_decode($encryptedDek, true);
            $dekNonceBinary = base64_decode($dekNonce, true);
            $encryptedValueBinary = base64_decode($encryptedValue, true);
            $valueNonceBinary = base64_decode($valueNonce, true);

            if ($encryptedDekBinary === false || $dekNonceBinary === false
                || $encryptedValueBinary === false || $valueNonceBinary === false
            ) {
                throw new DecryptionException('Invalid base64 encoding', 1700000002);
            }

            // Get master key
            $masterKey = $this->masterKeyProvider->getMasterKey();

            // Decrypt DEK with master key
            $dek = sodium_crypto_aead_aes256gcm_decrypt(
                $encryptedDekBinary,
                $identifier,
                $dekNonceBinary,
                $masterKey
            );

            if ($dek === false) {
                throw new DecryptionException(
                    'Failed to decrypt DEK - possible tampering or wrong master key',
                    1700000003
                );
            }

            // Decrypt value with DEK
            $plaintext = sodium_crypto_aead_aes256gcm_decrypt(
                $encryptedValueBinary,
                $identifier,
                $valueNonceBinary,
                $dek
            );

            if ($plaintext === false) {
                throw new DecryptionException(
                    'Failed to decrypt value - possible tampering',
                    1700000004
                );
            }

            // Securely erase sensitive data from memory
            sodium_memzero($dek);
            sodium_memzero($masterKey);

            return $plaintext;
        } catch (\SodiumException $e) {
            throw new DecryptionException(
                'Decryption failed: ' . $e->getMessage(),
                1700000005,
                $e
            );
        }
    }

    /**
     * Re-encrypt DEK with new master key (for key rotation).
     */
    public function reEncryptDek(
        string $encryptedDek,
        string $dekNonce,
        string $identifier,
        string $oldMasterKey,
        string $newMasterKey,
    ): array {
        try {
            $encryptedDekBinary = base64_decode($encryptedDek, true);
            $dekNonceBinary = base64_decode($dekNonce, true);

            // Decrypt DEK with old master key
            $dek = sodium_crypto_aead_aes256gcm_decrypt(
                $encryptedDekBinary,
                $identifier,
                $dekNonceBinary,
                $oldMasterKey
            );

            if ($dek === false) {
                throw new DecryptionException(
                    'Failed to decrypt DEK with old master key',
                    1700000006
                );
            }

            // Generate new nonce
            $newDekNonce = random_bytes(self::NONCE_LENGTH);

            // Re-encrypt DEK with new master key
            $newEncryptedDek = sodium_crypto_aead_aes256gcm_encrypt(
                $dek,
                $identifier,
                $newDekNonce,
                $newMasterKey
            );

            // Cleanup
            sodium_memzero($dek);

            return [
                'encrypted_dek' => base64_encode($newEncryptedDek),
                'dek_nonce' => base64_encode($newDekNonce),
            ];
        } catch (\SodiumException $e) {
            throw new EncryptionException(
                'Failed to re-encrypt DEK: ' . $e->getMessage(),
                1700000007,
                $e
            );
        }
    }

    /**
     * Generate a new master key.
     */
    public function generateMasterKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }
}
```

### 6.3 AccessControlService

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\AccessDeniedException;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;

final class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(
        private readonly Context $context,
    ) {}

    /**
     * Check if current user can read a secret.
     */
    public function canRead(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'read');
    }

    /**
     * Check if current user can write (create/update) a secret.
     */
    public function canWrite(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'write');
    }

    /**
     * Check if current user can delete a secret.
     */
    public function canDelete(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'delete');
    }

    /**
     * Assert read access or throw exception.
     */
    public function assertCanRead(Secret $secret): void
    {
        if (!$this->canRead($secret)) {
            throw new AccessDeniedException(
                sprintf('Access denied to secret "%s"', $secret->getIdentifier()),
                1700000010
            );
        }
    }

    /**
     * Assert write access or throw exception.
     */
    public function assertCanWrite(Secret $secret): void
    {
        if (!$this->canWrite($secret)) {
            throw new AccessDeniedException(
                sprintf('Cannot modify secret "%s"', $secret->getIdentifier()),
                1700000011
            );
        }
    }

    /**
     * Assert delete access or throw exception.
     */
    public function assertCanDelete(Secret $secret): void
    {
        if (!$this->canDelete($secret)) {
            throw new AccessDeniedException(
                sprintf('Cannot delete secret "%s"', $secret->getIdentifier()),
                1700000012
            );
        }
    }

    /**
     * Get current user UID.
     */
    public function getCurrentUserUid(): int
    {
        try {
            /** @var UserAspect $userAspect */
            $userAspect = $this->context->getAspect('backend.user');
            return $userAspect->get('id');
        } catch (\Exception) {
            // CLI or no user context
            return 0;
        }
    }

    /**
     * Get current user's groups.
     *
     * @return int[]
     */
    public function getCurrentUserGroups(): array
    {
        try {
            /** @var UserAspect $userAspect */
            $userAspect = $this->context->getAspect('backend.user');
            return $userAspect->getGroupIds();
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Check if current user is admin.
     */
    public function isAdmin(): bool
    {
        try {
            /** @var UserAspect $userAspect */
            $userAspect = $this->context->getAspect('backend.user');
            return $userAspect->get('isAdmin');
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Internal access check.
     */
    private function hasAccess(Secret $secret, string $action): bool
    {
        // Admins have full access
        if ($this->isAdmin()) {
            return true;
        }

        $currentUserUid = $this->getCurrentUserUid();

        // CLI context with no user - check if CLI access is allowed
        if ($currentUserUid === 0 && PHP_SAPI === 'cli') {
            // CLI access must be explicitly enabled in configuration
            if (!$this->configuration->isCliAccessAllowed()) {
                return false;
            }

            // If CLI access groups are configured, check if secret is in allowed groups
            $cliAccessGroups = $this->configuration->getCliAccessGroups();
            if (!empty($cliAccessGroups)) {
                $secretGroups = $secret->getAllowedGroups();
                return !empty(array_intersect($secretGroups, $cliAccessGroups));
            }

            // No group restriction = full CLI access (if enabled)
            return true;
        }

        // Owner always has access
        if ($secret->getOwnerUid() === $currentUserUid && $currentUserUid > 0) {
            return true;
        }

        // Check group membership
        $allowedGroups = $secret->getAllowedGroups();
        if (empty($allowedGroups)) {
            // No groups specified = owner only
            return false;
        }

        $userGroups = $this->getCurrentUserGroups();
        $intersection = array_intersect($allowedGroups, $userGroups);

        // Write/delete requires being in allowed groups
        if ($action === 'write' || $action === 'delete') {
            return !empty($intersection);
        }

        // Read: group membership grants access
        return !empty($intersection);
    }
}
```

### 6.4 MasterKeyProviderInterface and Implementations

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\KeyProvider;

use Netresearch\NrVault\Exception\MasterKeyException;

interface MasterKeyProviderInterface
{
    /**
     * Get the master key for encryption/decryption.
     *
     * @return string Binary string of 32 bytes
     * @throws MasterKeyException If key cannot be retrieved
     */
    public function getMasterKey(): string;

    /**
     * Get the provider type identifier.
     */
    public function getType(): string;
}
```

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\KeyProvider;

use Netresearch\NrVault\Exception\MasterKeyException;

final class FileKeyProvider implements MasterKeyProviderInterface
{
    private const EXPECTED_KEY_LENGTH = 32;

    private ?string $cachedKey = null;

    public function __construct(
        private readonly string $keyFilePath,
    ) {}

    public function getMasterKey(): string
    {
        if ($this->cachedKey !== null) {
            return $this->cachedKey;
        }

        if (!file_exists($this->keyFilePath)) {
            throw new MasterKeyException(
                sprintf('Master key file not found: %s', $this->keyFilePath),
                1700000020
            );
        }

        if (!is_readable($this->keyFilePath)) {
            throw new MasterKeyException(
                sprintf('Master key file not readable: %s', $this->keyFilePath),
                1700000021
            );
        }

        // Check file permissions (should be 0400 or 0600)
        $perms = fileperms($this->keyFilePath) & 0777;
        if ($perms > 0600) {
            throw new MasterKeyException(
                sprintf(
                    'Master key file has insecure permissions: %o (expected 0400 or 0600)',
                    $perms
                ),
                1700000022
            );
        }

        $content = file_get_contents($this->keyFilePath);
        if ($content === false) {
            throw new MasterKeyException(
                'Failed to read master key file',
                1700000023
            );
        }

        // Try base64 decode first (common format)
        $key = base64_decode(trim($content), true);
        if ($key === false) {
            // Assume raw binary
            $key = $content;
        }

        if (strlen($key) !== self::EXPECTED_KEY_LENGTH) {
            throw new MasterKeyException(
                sprintf(
                    'Invalid master key length: %d bytes (expected %d)',
                    strlen($key),
                    self::EXPECTED_KEY_LENGTH
                ),
                1700000024
            );
        }

        $this->cachedKey = $key;
        return $key;
    }

    public function getType(): string
    {
        return 'file';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\KeyProvider;

use Netresearch\NrVault\Exception\MasterKeyException;

final class EnvironmentKeyProvider implements MasterKeyProviderInterface
{
    private const EXPECTED_KEY_LENGTH = 32;
    private const DEFAULT_ENV_VAR = 'NR_VAULT_MASTER_KEY';

    private ?string $cachedKey = null;

    public function __construct(
        private readonly string $envVarName = self::DEFAULT_ENV_VAR,
    ) {}

    public function getMasterKey(): string
    {
        if ($this->cachedKey !== null) {
            return $this->cachedKey;
        }

        $value = getenv($this->envVarName);
        if ($value === false || $value === '') {
            throw new MasterKeyException(
                sprintf('Environment variable %s not set', $this->envVarName),
                1700000030
            );
        }

        // Expect base64 encoded value
        $key = base64_decode($value, true);
        if ($key === false) {
            throw new MasterKeyException(
                sprintf('Environment variable %s is not valid base64', $this->envVarName),
                1700000031
            );
        }

        if (strlen($key) !== self::EXPECTED_KEY_LENGTH) {
            throw new MasterKeyException(
                sprintf(
                    'Invalid master key length from env: %d bytes (expected %d)',
                    strlen($key),
                    self::EXPECTED_KEY_LENGTH
                ),
                1700000032
            );
        }

        $this->cachedKey = $key;
        return $key;
    }

    public function getType(): string
    {
        return 'env';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\KeyProvider;

use Netresearch\NrVault\Exception\MasterKeyException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class DerivedKeyProvider implements MasterKeyProviderInterface
{
    private const KEY_LENGTH = 32;
    private const CONTEXT = 'nr-vault-v1';

    private ?string $cachedKey = null;

    public function __construct(
        private readonly string $saltFilePath,
        private readonly string $encryptionKey,
    ) {}

    public function getMasterKey(): string
    {
        if ($this->cachedKey !== null) {
            return $this->cachedKey;
        }

        if (empty($this->encryptionKey)) {
            throw new MasterKeyException(
                'TYPO3 encryptionKey is not configured',
                1700000040
            );
        }

        // Load salt from file
        if (!file_exists($this->saltFilePath)) {
            throw new MasterKeyException(
                sprintf('Salt file not found: %s', $this->saltFilePath),
                1700000041
            );
        }

        $salt = file_get_contents($this->saltFilePath);
        if ($salt === false || strlen($salt) < 16) {
            throw new MasterKeyException(
                'Invalid or empty salt file',
                1700000042
            );
        }

        // Derive key using HKDF
        $ikm = $this->encryptionKey . $salt;
        $key = hash_hkdf('sha256', $ikm, self::KEY_LENGTH, self::CONTEXT);

        if (strlen($key) !== self::KEY_LENGTH) {
            throw new MasterKeyException(
                'Key derivation failed',
                1700000043
            );
        }

        $this->cachedKey = $key;
        return $key;
    }

    public function getType(): string
    {
        return 'derived';
    }
}
```

---

## 7. TCA Integration

### 7.1 VaultSecretElement

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Form\Element;

use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

final class VaultSecretElement extends AbstractFormElement
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * Render the vault secret field.
     */
    public function render(): array
    {
        $resultArray = $this->initializeResultArray();

        $parameterArray = $this->data['parameterArray'];
        $config = $parameterArray['fieldConf']['config']['parameters'] ?? [];

        $table = $this->data['tableName'];
        $row = $this->data['databaseRow'];
        $fieldName = $this->data['fieldName'];
        $itemFormElementName = $parameterArray['itemFormElName'];

        // Build vault identifier
        $identifierPattern = $config['vaultIdentifier'] ?? "{$table}_{uid}_{$fieldName}";
        $identifier = $this->resolveIdentifierPattern($identifierPattern, $row);

        // Check if secret exists
        $hasValue = $this->vaultService->exists($identifier);

        // Build field ID
        $fieldId = StringUtility::getUniqueId('vault_secret_');

        // Add JavaScript module
        $this->pageRenderer->loadJavaScriptModule(
            '@netresearch/nr-vault/VaultSecretField.js'
        );

        // Build HTML
        $html = [];
        $html[] = '<div class="vault-secret-field" data-identifier="' . htmlspecialchars($identifier) . '">';

        // Hidden field to store that a value was entered
        $html[] = sprintf(
            '<input type="hidden" name="%s[_vault_identifier]" value="%s" />',
            htmlspecialchars($itemFormElementName),
            htmlspecialchars($identifier)
        );

        // Password input field
        $html[] = '<div class="input-group">';
        $html[] = sprintf(
            '<input type="password" id="%s" name="%s[_vault_value]" class="form-control vault-secret-input" ' .
            'placeholder="%s" autocomplete="new-password" />',
            htmlspecialchars($fieldId),
            htmlspecialchars($itemFormElementName),
            $hasValue ? '••••••••••••••••' : 'Enter secret value'
        );

        // Toggle visibility button
        $html[] = '<button type="button" class="btn btn-default vault-toggle-visibility" data-target="' . $fieldId . '">';
        $html[] = '<span class="icon-show">👁</span>';
        $html[] = '<span class="icon-hide" style="display:none">🔒</span>';
        $html[] = '</button>';

        $html[] = '</div>'; // .input-group

        // Status indicator
        $html[] = '<div class="vault-secret-status mt-2">';
        if ($hasValue) {
            $metadata = $this->vaultService->getMetadata($identifier);
            $html[] = '<span class="badge badge-success">Stored securely</span>';
            if ($metadata) {
                $html[] = sprintf(
                    ' <span class="text-muted">Version %d | Last updated: %s</span>',
                    $metadata['version'],
                    $metadata['updatedAt']->format('Y-m-d H:i')
                );
            }
        } else {
            $html[] = '<span class="badge badge-warning">Not configured</span>';
        }
        $html[] = '</div>';

        // Action buttons (for existing secrets)
        if ($hasValue && ($config['showRotateButton'] ?? false)) {
            $html[] = '<div class="vault-secret-actions mt-2">';
            $html[] = '<button type="button" class="btn btn-sm btn-default vault-rotate-btn">';
            $html[] = 'Rotate Secret';
            $html[] = '</button>';
            $html[] = '</div>';
        }

        $html[] = '</div>'; // .vault-secret-field

        $resultArray['html'] = implode("\n", $html);

        return $resultArray;
    }

    /**
     * Resolve identifier pattern with record data.
     */
    private function resolveIdentifierPattern(string $pattern, array $row): string
    {
        $replacements = [
            '{uid}' => (string)($row['uid'] ?? 'NEW'),
            '{table}' => $this->data['tableName'],
            '{field}' => $this->data['fieldName'],
        ];

        // Add all row fields as potential replacements
        foreach ($row as $field => $value) {
            if (is_scalar($value)) {
                $replacements['{' . $field . '}'] = (string)$value;
            }
        }

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $pattern
        );
    }
}
```

### 7.2 PSR-14 Event Listeners (TYPO3 v14 Native Approach)

Using PSR-14 events instead of DataHandler hooks for cleaner, more maintainable code:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook;

use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook for processing vault secret fields.
 *
 * TYPO3 v14 uses DataHandler hooks for record operations.
 * This is the reliable approach for intercepting save/delete operations.
 */
final class DataHandlerHook
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {}

    /**
     * Process vault secret fields after record is saved.
     * Hook: processDatamap_afterDatabaseOperations
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler
    ): void {
        // Resolve actual UID for new records
        $uid = is_numeric($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($uid === 0) {
            return;
        }

        foreach ($fieldArray as $fieldName => $value) {
            // Check if this is a vault secret field (contains our marker)
            if (!is_array($value) || !isset($value['_vault_identifier'])) {
                continue;
            }

            $identifier = $value['_vault_identifier'];
            $secretValue = $value['_vault_value'] ?? '';
            $reason = $value['_vault_reason'] ?? null;

            // Skip if no new value provided
            if ($secretValue === '') {
                continue;
            }

            // Resolve identifier placeholders
            $identifier = str_replace('{uid}', (string)$uid, $identifier);
            $identifier = str_replace('{table}', $table, $identifier);
            $identifier = str_replace('{field}', $fieldName, $identifier);

            // Store or rotate the secret
            $options = [];
            if ($reason !== null) {
                $options['reason'] = $reason;
            }

            if ($this->vaultService->exists($identifier)) {
                $this->vaultService->rotate($identifier, $secretValue, $options);
            } else {
                $this->vaultService->store($identifier, $secretValue, $options);
            }
        }
    }

    /**
     * Clean up vault secrets when record is deleted.
     * Hook: processCmdmap_deleteAction
     */
    public function processCmdmap_deleteAction(
        string $table,
        int $id,
        array $recordToDelete,
        bool &$recordWasDeleted,
        DataHandler $dataHandler
    ): void {
        // Find vault secrets for this record
        $prefix = $table . '_' . $id . '_';
        $secrets = $this->vaultService->list(['prefix' => $prefix]);

        foreach ($secrets as $secret) {
            try {
                $this->vaultService->delete($secret->getIdentifier(), [
                    'reason' => 'Parent record deleted',
                ]);
            } catch (\Exception) {
                // Log but don't block deletion
            }
        }
    }
}
```

**Hook registration** (Configuration/ext_localconf.php):

```php
<?php

declare(strict_types=1);

use Netresearch\NrVault\Hook\DataHandlerHook;

defined('TYPO3') or die();

// Register DataHandler hooks for vault secret processing
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
    = DataHandlerHook::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = DataHandlerHook::class;
```

**Services.yaml registration:**

```yaml
services:
  Netresearch\NrVault\Hook\DataHandlerHook:
    public: true
```

**Why DataHandler hooks in TYPO3 v14:**
- Direct access to record operations at the right lifecycle point
- Works with all record operations (new, update, copy, delete)
- Clean dependency injection via constructor
- Testable in isolation with mocked DataHandler

---

## 8. Backend Module

### 8.1 Module Registration

```php
<?php

// Configuration/Backend/Modules.php
return [
    'vault' => [
        'parent' => 'system',
        'position' => ['after' => 'config'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/system/vault',
        'iconIdentifier' => 'module-vault',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => \Netresearch\NrVault\Controller\VaultController::class . '::indexAction',
            ],
        ],
    ],
];
```

### 8.2 VaultController

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Netresearch\NrVault\Domain\Repository\AuditLogRepository;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Backend module controller for vault management.
 *
 * TYPO3 v14 backend controllers use #[AsController] attribute
 * and do NOT extend ActionController. They are pure PSR-7 controllers.
 */
#[AsController]
final class VaultController
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly AuditLogRepository $auditLogRepository,
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * List all secrets.
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $secrets = $this->vaultService->list();

        $moduleTemplate->assignMultiple([
            'secrets' => $secrets,
            'stats' => [
                'total' => count($secrets),
                'expiringSoon' => $this->countExpiringSoon($secrets),
            ],
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    /**
     * Show single secret details.
     */
    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $identifier = $request->getQueryParams()['identifier'] ?? '';
        $metadata = $this->vaultService->getMetadata($identifier);

        if ($metadata === null) {
            return new RedirectResponse(
                $this->uriBuilder->buildUriFromRoute('vault')
            );
        }

        $auditLog = $this->auditLogRepository->findBySecretIdentifier($identifier, 50);

        $moduleTemplate->assignMultiple([
            'metadata' => $metadata,
            'auditLog' => $auditLog,
        ]);

        return $moduleTemplate->renderResponse('Backend/Show');
    }

    /**
     * Create new secret form.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        return $moduleTemplate->renderResponse('Backend/Create');
    }

    /**
     * Store new secret.
     */
    public function storeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();

        $identifier = $body['identifier'] ?? '';
        $secret = $body['secret'] ?? '';
        $description = $body['description'] ?? '';
        $groups = array_map('intval', $body['groups'] ?? []);
        $expiresAt = !empty($body['expires_at'])
            ? new \DateTimeImmutable($body['expires_at'])
            : null;

        try {
            $this->vaultService->store($identifier, $secret, [
                'description' => $description,
                'groups' => $groups,
                'expires' => $expiresAt,
            ]);

            $this->addFlashMessage('Secret stored successfully', '', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::OK);
        } catch (\Exception $e) {
            $this->addFlashMessage($e->getMessage(), 'Error', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse(
            $this->uriBuilder->buildUriFromRoute('vault')
        );
    }

    /**
     * View audit log.
     */
    public function auditAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        $params = $request->getQueryParams();
        $filters = [
            'secret' => $params['secret'] ?? '',
            'action' => $params['action'] ?? '',
            'user' => $params['user'] ?? '',
            'since' => $params['since'] ?? '',
        ];

        $entries = $this->auditLogRepository->findWithFilters($filters, 100);

        $moduleTemplate->assignMultiple([
            'entries' => $entries,
            'filters' => $filters,
        ]);

        return $moduleTemplate->renderResponse('Backend/Audit');
    }

    /**
     * Count secrets expiring within 30 days.
     */
    private function countExpiringSoon(array $secrets): int
    {
        $threshold = new \DateTimeImmutable('+30 days');
        $count = 0;

        foreach ($secrets as $secret) {
            $expiresAt = $secret->getExpiresAt();
            if ($expiresAt !== null && $expiresAt < $threshold) {
                $count++;
            }
        }

        return $count;
    }
}
```

---

## 9. CLI Commands

### 9.1 StoreCommand

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'vault:store',
    description: 'Store a secret in the vault',
)]
final class StoreCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Secret identifier')
            ->addOption('stdin', null, InputOption::VALUE_NONE, 'Read secret from stdin')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Secret description')
            ->addOption('owner', 'o', InputOption::VALUE_REQUIRED, 'Owner user UID', '0')
            ->addOption('groups', 'g', InputOption::VALUE_REQUIRED, 'Comma-separated group UIDs')
            ->addOption('expires', 'e', InputOption::VALUE_REQUIRED, 'Expiration date (Y-m-d or relative like "+90 days")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = $input->getArgument('identifier');

        // Get secret value
        if ($input->getOption('stdin')) {
            $secret = trim(file_get_contents('php://stdin'));
        } else {
            $secret = $io->askHidden('Enter secret value');
        }

        if (empty($secret)) {
            $io->error('Secret value cannot be empty');
            return Command::FAILURE;
        }

        // Build options
        $options = [];

        if ($input->getOption('description')) {
            $options['description'] = $input->getOption('description');
        }

        if ($input->getOption('owner')) {
            $options['owner'] = (int)$input->getOption('owner');
        }

        if ($input->getOption('groups')) {
            $options['groups'] = array_map('intval', explode(',', $input->getOption('groups')));
        }

        if ($input->getOption('expires')) {
            $options['expires'] = new \DateTimeImmutable($input->getOption('expires'));
        }

        try {
            $this->vaultService->store($identifier, $secret, $options);
            $io->success(sprintf('Secret "%s" stored successfully', $identifier));
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

### 9.2 RetrieveCommand

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'vault:retrieve',
    description: 'Retrieve a secret from the vault',
)]
final class RetrieveCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Secret identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $identifier = $input->getArgument('identifier');

        try {
            $secret = $this->vaultService->retrieve($identifier);

            if ($secret === null) {
                $io->error(sprintf('Secret "%s" not found', $identifier));
                return Command::FAILURE;
            }

            // Output raw secret (for piping)
            $output->write($secret);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

### 9.3 MasterKeyRotateCommand

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\KeyProvider\MasterKeyProviderInterface;
use Netresearch\NrVault\Service\EncryptionServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsCommand(
    name: 'vault:master-key:rotate',
    description: 'Rotate the master key and re-encrypt all DEKs',
)]
final class MasterKeyRotateCommand extends Command
{
    public function __construct(
        private readonly SecretRepository $secretRepository,
        private readonly EncryptionServiceInterface $encryptionService,
        private readonly MasterKeyProviderInterface $masterKeyProvider,
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('new-key-file', null, InputOption::VALUE_REQUIRED, 'Path to new master key file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate without making changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $newKeyFile = $input->getOption('new-key-file');
        if (!$newKeyFile || !file_exists($newKeyFile)) {
            $io->error('New master key file required: --new-key-file=/path/to/new/key');
            return Command::FAILURE;
        }

        $dryRun = $input->getOption('dry-run');

        // Load keys
        $oldMasterKey = $this->masterKeyProvider->getMasterKey();
        $newMasterKeyContent = file_get_contents($newKeyFile);
        $newMasterKey = base64_decode(trim($newMasterKeyContent), true);

        if (strlen($newMasterKey) !== 32) {
            $io->error('New master key must be 32 bytes (base64 encoded)');
            return Command::FAILURE;
        }

        // Get all secrets
        $secrets = $this->secretRepository->findAll();
        $totalSecrets = count($secrets);

        $io->note(sprintf('Found %d secrets to re-encrypt', $totalSecrets));

        if ($dryRun) {
            $io->success('Dry run complete. No changes made.');
            return Command::SUCCESS;
        }

        if (!$io->confirm('Proceed with master key rotation?', false)) {
            return Command::FAILURE;
        }

        $connection = $this->connectionPool->getConnectionForTable('tx_nrvault_secret');
        $connection->beginTransaction();

        try {
            $progressBar = $io->createProgressBar($totalSecrets);
            $rotated = 0;

            foreach ($secrets as $secret) {
                // Re-encrypt DEK with new master key
                $reEncrypted = $this->encryptionService->reEncryptDek(
                    $secret->getEncryptedDek(),
                    $secret->getDekNonce(),
                    $secret->getIdentifier(),
                    $oldMasterKey,
                    $newMasterKey
                );

                // Update in database
                $connection->update(
                    'tx_nrvault_secret',
                    [
                        'encrypted_dek' => $reEncrypted['encrypted_dek'],
                        'dek_nonce' => $reEncrypted['dek_nonce'],
                        'tstamp' => time(),
                    ],
                    ['uid' => $secret->getUid()]
                );

                $rotated++;
                $progressBar->advance();
            }

            $progressBar->finish();
            $connection->commit();

            // Clean up
            sodium_memzero($oldMasterKey);
            sodium_memzero($newMasterKey);

            $io->newLine(2);
            $io->success(sprintf('Master key rotation complete. %d secrets re-encrypted.', $rotated));
            $io->warning('Remember to update your master key configuration to point to the new key file!');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $connection->rollBack();
            $io->error('Rotation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
```

---

## 9.5 Vault HTTP Client

The vault HTTP client allows extensions to make authenticated API calls without ever seeing the secret. This provides security benefits in pure PHP mode and enables a zero-exposure path when Rust FFI is available.

### 9.5.1 Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                        Extension Code                            │
│                                                                  │
│   $response = $vault->http()                                     │
│       ->withSecret('stripe_key', SecretPlacement::BearerAuth)   │
│       ->post('https://api.stripe.com/v1/charges', $payload);    │
│                                                                  │
│   // Secret identifier passed, NOT the secret value             │
└──────────────────────────────┬──────────────────────────────────┘
                               │
              ┌────────────────┴────────────────┐
              ▼                                 ▼
┌──────────────────────────┐     ┌──────────────────────────────┐
│   PhpVaultHttpClient     │     │   RustVaultHttpClient        │
│   (default, works        │     │   (optional, requires FFI)   │
│    everywhere)           │     │                              │
│                          │     │                              │
│   - Secret briefly in    │     │   - Secret NEVER in PHP      │
│     PHP memory           │     │   - mlock'd memory           │
│   - Auto sodium_memzero  │     │   - ~35% faster              │
│   - Audit logging        │     │   - Audit logging            │
└──────────────────────────┘     └──────────────────────────────┘
```

### 9.5.2 SecretPlacement Enum

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

enum SecretPlacement: string
{
    /** Authorization: Bearer {secret} */
    case BearerAuth = 'bearer';

    /** Authorization: Basic base64(username:{secret}) */
    case BasicAuthPassword = 'basic_pass';

    /** Authorization: Basic base64({secret}:password) */
    case BasicAuthUsername = 'basic_user';

    /** Custom header: X-Api-Key: {secret} */
    case Header = 'header';

    /** Query parameter: ?api_key={secret} */
    case QueryParam = 'query';

    /** JSON body field: {"api_key": "{secret}"} */
    case BodyField = 'body';

    /** URL segment: https://api.example.com/{secret}/endpoint */
    case UrlSegment = 'url';

    /** Form field: api_key={secret} (application/x-www-form-urlencoded) */
    case FormField = 'form';
}
```

### 9.5.3 VaultHttpClientInterface

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

interface VaultHttpClientInterface
{
    /**
     * Attach a secret to the request.
     * The secret is resolved internally - caller never sees the value.
     *
     * @param string $identifier Secret identifier in vault
     * @param SecretPlacement $placement Where to inject the secret
     * @param string|null $name Header name, query param name, field name, etc.
     */
    public function withSecret(
        string $identifier,
        SecretPlacement $placement,
        ?string $name = null,
    ): self;

    /**
     * Attach multiple secrets (e.g., OAuth client_id + client_secret).
     *
     * @param array<array{identifier: string, placement: SecretPlacement, name?: string}> $secrets
     */
    public function withSecrets(array $secrets): self;

    /**
     * Configure OAuth with automatic token refresh.
     *
     * @param string $accessTokenId Identifier for access token
     * @param string|null $refreshTokenId Identifier for refresh token (enables auto-refresh)
     * @param string|null $tokenEndpoint OAuth token endpoint for refresh
     */
    public function withOAuthToken(
        string $accessTokenId,
        ?string $refreshTokenId = null,
        ?string $tokenEndpoint = null,
    ): self;

    /**
     * Set non-secret headers.
     */
    public function withHeaders(array $headers): self;

    /**
     * Set request timeout.
     */
    public function withTimeout(int $seconds): self;

    /**
     * Set base URI for relative URLs.
     */
    public function withBaseUri(string $uri): self;

    /**
     * HTTP GET request.
     */
    public function get(string $url, array $query = []): VaultHttpResponse;

    /**
     * HTTP POST request.
     */
    public function post(string $url, array|string $body = []): VaultHttpResponse;

    /**
     * HTTP PUT request.
     */
    public function put(string $url, array|string $body = []): VaultHttpResponse;

    /**
     * HTTP PATCH request.
     */
    public function patch(string $url, array|string $body = []): VaultHttpResponse;

    /**
     * HTTP DELETE request.
     */
    public function delete(string $url): VaultHttpResponse;
}
```

### 9.5.4 PhpVaultHttpClient (Default Implementation)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Netresearch\NrVault\Http\Exception\VaultHttpException;
use Netresearch\NrVault\Service\AuditLogServiceInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;

/**
 * Pure PHP implementation of vault-aware HTTP client.
 *
 * Benefits over manual retrieve() + Guzzle:
 * - Secret never visible in caller code
 * - Automatic sodium_memzero() on secrets
 * - Audit logging of API calls with secret usage
 * - Consistent error handling
 * - Auto token refresh support
 * - Future-proof for Rust FFI upgrade
 */
final class PhpVaultHttpClient implements VaultHttpClientInterface
{
    /** @var SecretBinding[] */
    private array $secrets = [];
    private array $headers = [];
    private int $timeout = 30;
    private string $baseUri = '';
    private ?OAuthConfig $oauthConfig = null;

    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly ClientInterface $guzzle,
        private readonly AuditLogServiceInterface $auditLog,
    ) {}

    public function withSecret(
        string $identifier,
        SecretPlacement $placement,
        ?string $name = null,
    ): self {
        $clone = clone $this;
        $clone->secrets[] = new SecretBinding($identifier, $placement, $name);
        return $clone;
    }

    public function withSecrets(array $secrets): self
    {
        $clone = clone $this;
        foreach ($secrets as $config) {
            $clone->secrets[] = new SecretBinding(
                $config['identifier'],
                $config['placement'],
                $config['name'] ?? null,
            );
        }
        return $clone;
    }

    public function withOAuthToken(
        string $accessTokenId,
        ?string $refreshTokenId = null,
        ?string $tokenEndpoint = null,
    ): self {
        $clone = clone $this;
        $clone->secrets[] = new SecretBinding($accessTokenId, SecretPlacement::BearerAuth);

        if ($refreshTokenId !== null) {
            $clone->oauthConfig = new OAuthConfig($accessTokenId, $refreshTokenId, $tokenEndpoint);
        }

        return $clone;
    }

    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($clone->headers, $headers);
        return $clone;
    }

    public function withTimeout(int $seconds): self
    {
        $clone = clone $this;
        $clone->timeout = $seconds;
        return $clone;
    }

    public function withBaseUri(string $uri): self
    {
        $clone = clone $this;
        $clone->baseUri = rtrim($uri, '/');
        return $clone;
    }

    public function get(string $url, array $query = []): VaultHttpResponse
    {
        return $this->execute('GET', $url, null, $query);
    }

    public function post(string $url, array|string $body = []): VaultHttpResponse
    {
        return $this->execute('POST', $url, $body);
    }

    public function put(string $url, array|string $body = []): VaultHttpResponse
    {
        return $this->execute('PUT', $url, $body);
    }

    public function patch(string $url, array|string $body = []): VaultHttpResponse
    {
        return $this->execute('PATCH', $url, $body);
    }

    public function delete(string $url): VaultHttpResponse
    {
        return $this->execute('DELETE', $url);
    }

    private function execute(
        string $method,
        string $url,
        array|string|null $body = null,
        array $query = [],
    ): VaultHttpResponse {
        $fullUrl = $this->baseUri ? $this->baseUri . '/' . ltrim($url, '/') : $url;
        $resolvedSecrets = [];
        $startTime = microtime(true);

        try {
            // 1. Resolve all secrets (minimize time in memory)
            foreach ($this->secrets as $binding) {
                $secret = $this->vault->retrieve($binding->identifier);
                if ($secret === null) {
                    throw new VaultHttpException(
                        sprintf('Secret "%s" not found', $binding->identifier),
                        1700001001
                    );
                }
                $resolvedSecrets[$binding->identifier] = $secret;
            }

            // 2. Build request options with secrets injected
            $options = $this->buildRequestOptions($resolvedSecrets, $body, $query, $fullUrl);

            // 3. Log secret usage (before request, so we log even on failure)
            $this->logSecretUsage($method, $fullUrl);

            // 4. Execute HTTP request
            try {
                $response = $this->guzzle->request($method, $options['url'], [
                    'headers' => $options['headers'],
                    'json' => $options['json'] ?? null,
                    'body' => $options['body'] ?? null,
                    'query' => $options['query'] ?? null,
                    'timeout' => $this->timeout,
                    'http_errors' => false,  // Don't throw on 4xx/5xx
                ]);
            } catch (GuzzleException $e) {
                throw new VaultHttpException(
                    sprintf('HTTP request failed: %s', $e->getMessage()),
                    1700001002,
                    $e
                );
            }

            $result = new VaultHttpResponse(
                status: $response->getStatusCode(),
                headers: $this->flattenHeaders($response->getHeaders()),
                body: (string)$response->getBody(),
                elapsedMs: (int)((microtime(true) - $startTime) * 1000),
            );

            // 5. Handle OAuth auto-refresh on 401
            if ($result->getStatus() === 401 && $this->oauthConfig !== null) {
                $this->refreshOAuthToken();
                // Retry once with new token
                return $this->execute($method, $url, $body, $query);
            }

            return $result;

        } finally {
            // 6. ALWAYS wipe secrets from memory
            foreach ($resolvedSecrets as $identifier => &$secret) {
                sodium_memzero($secret);
            }
            unset($secret);
        }
    }

    private function buildRequestOptions(
        array $secrets,
        array|string|null $body,
        array $query,
        string &$url,
    ): array {
        $headers = $this->headers;
        $jsonBody = is_array($body) ? $body : null;
        $rawBody = is_string($body) ? $body : null;
        $formFields = [];

        foreach ($this->secrets as $binding) {
            $secret = $secrets[$binding->identifier];

            match ($binding->placement) {
                SecretPlacement::BearerAuth =>
                    $headers['Authorization'] = 'Bearer ' . $secret,

                SecretPlacement::BasicAuthPassword =>
                    $headers['Authorization'] = 'Basic ' . base64_encode(
                        ($binding->name ?? '') . ':' . $secret
                    ),

                SecretPlacement::BasicAuthUsername =>
                    $headers['Authorization'] = 'Basic ' . base64_encode(
                        $secret . ':' . ($binding->name ?? '')
                    ),

                SecretPlacement::Header =>
                    $headers[$binding->name ?? 'X-Api-Key'] = $secret,

                SecretPlacement::QueryParam =>
                    $query[$binding->name ?? 'api_key'] = $secret,

                SecretPlacement::BodyField =>
                    $jsonBody[$binding->name ?? 'api_key'] = $secret,

                SecretPlacement::UrlSegment =>
                    $url = str_replace('{secret}', $secret, $url),

                SecretPlacement::FormField =>
                    $formFields[$binding->name ?? 'api_key'] = $secret,
            };
        }

        return [
            'url' => $url,
            'headers' => $headers,
            'json' => $jsonBody,
            'body' => $rawBody,
            'query' => $query ?: null,
            'form_params' => $formFields ?: null,
        ];
    }

    private function logSecretUsage(string $method, string $url): void
    {
        $identifiers = array_map(
            fn(SecretBinding $b) => $b->identifier,
            $this->secrets
        );

        $this->auditLog->log(
            action: 'http_request',
            identifier: implode(', ', $identifiers),
            context: [
                'method' => $method,
                'host' => parse_url($url, PHP_URL_HOST),
                'path' => parse_url($url, PHP_URL_PATH),
                'secret_count' => count($identifiers),
            ]
        );
    }

    private function refreshOAuthToken(): void
    {
        if ($this->oauthConfig === null || $this->oauthConfig->refreshTokenId === null) {
            return;
        }

        $refreshToken = $this->vault->retrieve($this->oauthConfig->refreshTokenId);
        if ($refreshToken === null) {
            throw new VaultHttpException('Refresh token not found', 1700001003);
        }

        try {
            // Call token endpoint
            $response = $this->guzzle->request('POST', $this->oauthConfig->tokenEndpoint, [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $tokens = json_decode((string)$response->getBody(), true);

            // Store new access token
            $this->vault->rotate(
                $this->oauthConfig->accessTokenId,
                $tokens['access_token'],
                ['reason' => 'Automatic OAuth token refresh']
            );

            // Store new refresh token if provided
            if (isset($tokens['refresh_token'])) {
                $this->vault->rotate(
                    $this->oauthConfig->refreshTokenId,
                    $tokens['refresh_token'],
                    ['reason' => 'Automatic OAuth token refresh']
                );
            }

        } finally {
            sodium_memzero($refreshToken);
        }
    }

    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $values) {
            $flat[$name] = implode(', ', $values);
        }
        return $flat;
    }
}
```

### 9.5.5 VaultService Integration

```php
<?php

// Add to VaultServiceInterface:
public function http(?string $preset = null): VaultHttpClientInterface;

// Implementation in VaultService:
public function http(?string $preset = null): VaultHttpClientInterface
{
    $client = $this->httpClientFactory->create();

    if ($preset !== null) {
        // Load preset configuration (e.g., 'stripe', 'mailchimp')
        $config = $this->configuration->getHttpPreset($preset);
        $client = $client
            ->withBaseUri($config['baseUri'])
            ->withSecret($config['secretIdentifier'], $config['secretPlacement']);
    }

    return $client;
}
```

### 9.5.6 Usage Examples

```php
// Simple Bearer auth
$response = $vault->http()
    ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
    ->post('https://api.stripe.com/v1/charges', [
        'amount' => 2000,
        'currency' => 'usd',
        'source' => 'tok_visa',
    ]);

if ($response->isSuccess()) {
    $charge = $response->json();
}

// Using a preset
$response = $vault->http('stripe')
    ->post('/v1/charges', ['amount' => 2000, 'currency' => 'usd']);

// Custom header
$response = $vault->http()
    ->withSecret('sendgrid_key', SecretPlacement::Header, 'Authorization')
    ->withHeaders(['Content-Type' => 'application/json'])
    ->post('https://api.sendgrid.com/v3/mail/send', $emailPayload);

// Multiple secrets
$response = $vault->http()
    ->withSecrets([
        ['identifier' => 'oauth_client_id', 'placement' => SecretPlacement::BodyField, 'name' => 'client_id'],
        ['identifier' => 'oauth_client_secret', 'placement' => SecretPlacement::BodyField, 'name' => 'client_secret'],
    ])
    ->post('https://oauth.provider.com/token', [
        'grant_type' => 'client_credentials',
    ]);

// OAuth with auto-refresh
$response = $vault->http()
    ->withOAuthToken(
        accessTokenId: 'google_access_token',
        refreshTokenId: 'google_refresh_token',
        tokenEndpoint: 'https://oauth2.googleapis.com/token',
    )
    ->get('https://www.googleapis.com/calendar/v3/calendars/primary/events');
// Automatically refreshes token on 401 and retries

// Basic auth
$response = $vault->http()
    ->withSecret('api_password', SecretPlacement::BasicAuthPassword, 'api_user')
    ->get('https://api.example.com/protected');
```

### 9.5.7 Optional Rust FFI Implementation

When FFI is enabled, secrets never enter PHP memory:

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

/**
 * Rust FFI implementation - secrets never touch PHP memory.
 *
 * Requirements:
 * - PHP FFI extension enabled (ffi.enable=true)
 * - Compiled libnrvault.so in Resources/Private/Lib/
 *
 * Additional benefits over PHP implementation:
 * - Secret NEVER enters PHP memory
 * - mlock'd memory (not swappable)
 * - ~35% faster HTTP operations
 * - Automatic constant-time operations
 */
final class RustVaultHttpClient implements VaultHttpClientInterface
{
    private static ?\FFI $ffi = null;

    // ... (implementation as shown earlier)
}
```

**Configuration to enable Rust:**

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    // 'php' (default) or 'rust'
    'httpClientBackend' => 'rust',

    // Path to compiled Rust library
    'rustLibraryPath' => '/var/lib/typo3/libnrvault.so',
];
```

### 9.5.8 VaultHttpClientFactory

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use GuzzleHttp\Client;
use Netresearch\NrVault\Configuration\VaultConfiguration;
use Netresearch\NrVault\Service\AuditLogServiceInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;

final class VaultHttpClientFactory
{
    public function __construct(
        private readonly VaultServiceInterface $vault,
        private readonly AuditLogServiceInterface $auditLog,
        private readonly VaultConfiguration $configuration,
        private readonly LoggerInterface $logger,
    ) {}

    public function create(): VaultHttpClientInterface
    {
        $backend = $this->configuration->getHttpClientBackend();

        if ($backend === 'rust') {
            return $this->createRustClient();
        }

        return $this->createPhpClient();
    }

    private function createPhpClient(): PhpVaultHttpClient
    {
        return new PhpVaultHttpClient(
            vault: $this->vault,
            guzzle: new Client(),
            auditLog: $this->auditLog,
        );
    }

    private function createRustClient(): RustVaultHttpClient
    {
        if (!extension_loaded('ffi')) {
            $this->logger->warning(
                'Rust HTTP client requested but FFI not available, falling back to PHP'
            );
            return $this->createPhpClient();
        }

        $libraryPath = $this->configuration->getRustLibraryPath();
        if (!file_exists($libraryPath)) {
            $this->logger->warning(
                'Rust library not found at {path}, falling back to PHP',
                ['path' => $libraryPath]
            );
            return $this->createPhpClient();
        }

        return new RustVaultHttpClient(
            libraryPath: $libraryPath,
            dbDsn: $this->configuration->getDatabaseDsn(),
            masterKeyProvider: $this->vault->getMasterKeyProvider(),
            auditLog: $this->auditLog,
        );
    }
}
```

### 9.5.9 Benefits Summary

| Benefit | Without `vault->http()` | PHP `vault->http()` | Rust `vault->http()` |
|---------|------------------------|--------------------|--------------------|
| Secret not in caller code | No | **Yes** | **Yes** |
| Guaranteed secret wiping | Manual | **Automatic** | **Automatic** |
| Audit: API calls logged | No | **Yes** | **Yes** |
| Consistent error handling | No | **Yes** | **Yes** |
| Auto OAuth refresh | Manual | **Yes** | **Yes** |
| Future-proof for Rust | No | **Yes** | N/A |
| Secret never in PHP | No | No | **Yes** |
| Memory not swappable | No | No | **Yes** |
| Performance boost | N/A | Minimal | **~35%** |

---

## 9.6 Scheduler Tasks (Token Rotation Automation)

### ExpiredSecretsTask

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Scheduler;

use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Service\AuditLogServiceInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Check for expired secrets and notify administrators.
 */
final class ExpiredSecretsTask extends AbstractTask
{
    public function __construct(
        private readonly SecretRepository $secretRepository,
        private readonly AuditLogServiceInterface $auditLogService,
    ) {
        parent::__construct();
    }

    public function execute(): bool
    {
        // Find secrets expiring within warning threshold
        $warningDays = 30;
        $expiringSoon = $this->secretRepository->findExpiringSoon($warningDays);
        $alreadyExpired = $this->secretRepository->findExpired();

        // Log expired secrets
        foreach ($alreadyExpired as $secret) {
            $this->auditLogService->log(
                action: 'expired',
                identifier: $secret->getIdentifier(),
                actorType: 'scheduler',
                context: ['expired_at' => $secret->getExpiresAt()->format('c')]
            );
        }

        // Send notification if any secrets need attention
        if (count($expiringSoon) > 0 || count($alreadyExpired) > 0) {
            $this->sendNotification($expiringSoon, $alreadyExpired);
        }

        return true;
    }

    private function sendNotification(array $expiringSoon, array $expired): void
    {
        // Implementation: email to configured recipients
    }
}
```

### OAuthTokenRefreshTask

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Scheduler;

use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Service\VaultServiceInterface;
use TYPO3\CMS\Scheduler\Task\AbstractTask;
use Psr\Log\LoggerInterface;

/**
 * Auto-refresh OAuth tokens that have refresh tokens stored.
 */
final class OAuthTokenRefreshTask extends AbstractTask
{
    public function __construct(
        private readonly SecretRepository $secretRepository,
        private readonly VaultServiceInterface $vaultService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function execute(): bool
    {
        // Find OAuth secrets with refresh capability
        $oauthSecrets = $this->secretRepository->findByMetadataKey('oauth_refresh_token');

        foreach ($oauthSecrets as $secret) {
            $metadata = $secret->getMetadata();

            // Check if access token is expired or expiring soon
            $expiresAt = $metadata['access_token_expires'] ?? null;
            if ($expiresAt === null) {
                continue;
            }

            $expiresAtTime = new \DateTimeImmutable('@' . $expiresAt);
            $threshold = new \DateTimeImmutable('+5 minutes');

            if ($expiresAtTime > $threshold) {
                // Token still valid, skip
                continue;
            }

            try {
                $this->refreshOAuthToken($secret, $metadata);
            } catch (\Exception $e) {
                $this->logger->error('OAuth token refresh failed', [
                    'identifier' => $secret->getIdentifier(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    private function refreshOAuthToken(Secret $secret, array $metadata): void
    {
        $refreshToken = $this->vaultService->retrieve(
            $metadata['refresh_token_identifier']
        );

        if ($refreshToken === null) {
            throw new \RuntimeException('Refresh token not found');
        }

        // Provider-specific refresh logic
        $provider = $metadata['oauth_provider'] ?? 'generic';
        $newTokens = $this->callRefreshEndpoint($provider, $refreshToken, $metadata);

        // Store new access token
        $this->vaultService->rotate(
            $secret->getIdentifier(),
            $newTokens['access_token'],
            [
                'reason' => 'Automatic OAuth token refresh',
                'metadata' => array_merge($metadata, [
                    'access_token_expires' => time() + ($newTokens['expires_in'] ?? 3600),
                ]),
            ]
        );

        // Store new refresh token if provided
        if (isset($newTokens['refresh_token'])) {
            $this->vaultService->rotate(
                $metadata['refresh_token_identifier'],
                $newTokens['refresh_token'],
                ['reason' => 'Automatic OAuth token refresh']
            );
        }
    }

    private function callRefreshEndpoint(string $provider, string $refreshToken, array $metadata): array
    {
        // Provider-specific implementation
        // Returns: ['access_token' => '...', 'expires_in' => 3600, 'refresh_token' => '...']
    }
}
```

**Scheduler registration** (Configuration/Services.yaml):

```yaml
services:
  Netresearch\NrVault\Scheduler\ExpiredSecretsTask:
    public: true

  Netresearch\NrVault\Scheduler\OAuthTokenRefreshTask:
    public: true
```

### 9.6 Service Registry (Future Enhancement)

**Target:** Phase 7+ (post-Rust FFI)

The Service Registry extends the vault HTTP client to abstract away endpoint URLs entirely. The caller only needs to know the service name - the vault handles both credentials AND endpoints.

#### 9.6.1 Vision

```php
// Current approach: caller knows endpoint URL
$response = $vault->http()
    ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
    ->post('https://api.stripe.com/v1/charges', $payload);

// Future approach: caller only knows service name
$response = $vault->http()
    ->withService('stripe')  // Vault knows: URL, auth method, credentials
    ->post('/v1/charges', $payload);

// Even simpler for configured endpoints
$response = $vault->http()
    ->withService('stripe')
    ->call('create_charge', $payload);  // Endpoint path also abstracted
```

#### 9.6.2 Service Configuration

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

/**
 * Service configuration stored in vault.
 *
 * Benefits:
 * - Environment isolation: dev/staging/prod endpoints configured centrally
 * - Endpoint security: internal service URLs never exposed to consuming code
 * - Version management: API version upgrades in one place
 * - Audit completeness: log service + operation, not just secret usage
 */
final readonly class ServiceDefinition
{
    /**
     * @param array<string, string> $endpoints Named endpoint patterns
     * @param array<string, mixed> $defaultHeaders Headers always included
     */
    public function __construct(
        public string $name,
        public string $baseUrl,
        public string $secretIdentifier,
        public SecretPlacement $authPlacement,
        public ?string $authHeaderName = null,
        public array $endpoints = [],
        public array $defaultHeaders = [],
        public ?string $refreshTokenIdentifier = null,
        public ?string $tokenEndpoint = null,
    ) {}
}
```

#### 9.6.3 Database Schema Extension

```sql
CREATE TABLE tx_nrvault_service (
    uid int(11) unsigned NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT 0 NOT NULL,

    -- Service identification
    name varchar(100) DEFAULT '' NOT NULL,
    description text,

    -- Environment-specific base URLs (JSON)
    -- {"production": "https://api.stripe.com", "staging": "https://api.stripe.com/test"}
    base_urls text,

    -- Authentication configuration
    secret_identifier varchar(255) DEFAULT '' NOT NULL,
    auth_placement varchar(50) DEFAULT 'bearer' NOT NULL,
    auth_header_name varchar(100) DEFAULT '' NOT NULL,

    -- OAuth support
    refresh_token_identifier varchar(255) DEFAULT '' NOT NULL,
    token_endpoint varchar(500) DEFAULT '' NOT NULL,

    -- Named endpoints (JSON)
    -- {"create_charge": "/v1/charges", "list_customers": "/v1/customers"}
    endpoints text,

    -- Default headers (JSON)
    default_headers text,

    -- Access control (same as secrets)
    allowed_groups text,
    context varchar(50) DEFAULT '' NOT NULL,

    -- Audit
    crdate int(11) unsigned DEFAULT 0 NOT NULL,
    tstamp int(11) unsigned DEFAULT 0 NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY name (name)
) ENGINE=InnoDB;
```

#### 9.6.4 Enhanced HTTP Client Interface

```php
interface VaultHttpClientInterface
{
    // Existing methods...

    /**
     * Configure client for a registered service.
     *
     * Loads: base URL, authentication, default headers from service registry.
     * Caller only needs service name, no knowledge of credentials or endpoints.
     *
     * @param string $serviceName Registered service identifier
     * @param string|null $environment Override environment detection (dev/staging/prod)
     */
    public function withService(
        string $serviceName,
        ?string $environment = null,
    ): self;

    /**
     * Call a named endpoint on the configured service.
     *
     * @param string $endpointName Named endpoint from service configuration
     * @param array<string, mixed> $pathParams Parameters to substitute in URL template
     * @param array<string, mixed>|string $body Request body
     */
    public function call(
        string $endpointName,
        array $pathParams = [],
        array|string $body = [],
    ): VaultHttpResponse;
}
```

#### 9.6.5 Usage Examples

```php
// Register service (admin operation)
$serviceRegistry->register(new ServiceDefinition(
    name: 'stripe',
    baseUrl: 'https://api.stripe.com',  // or env-specific via base_urls
    secretIdentifier: 'stripe_api_key',
    authPlacement: SecretPlacement::BearerAuth,
    endpoints: [
        'create_charge' => 'POST /v1/charges',
        'get_customer' => 'GET /v1/customers/{customer_id}',
        'list_invoices' => 'GET /v1/invoices',
    ],
));

// Consuming code - knows NOTHING about credentials or URLs
$response = $vault->http()
    ->withService('stripe')
    ->call('create_charge', body: [
        'amount' => 2000,
        'currency' => 'eur',
    ]);

// With path parameters
$response = $vault->http()
    ->withService('stripe')
    ->call('get_customer', pathParams: ['customer_id' => 'cus_123']);

// Internal service example - URL never exposed
$response = $vault->http()
    ->withService('internal-crm')  // baseUrl: https://crm.internal.corp:8443
    ->call('sync_contacts', body: $contacts);
```

#### 9.6.6 Benefits Summary

| Aspect | Without Service Registry | With Service Registry |
|--------|--------------------------|----------------------|
| **Credential exposure** | Secret identifier in caller code | Only service name visible |
| **Endpoint exposure** | Full URL in caller code | Only endpoint name visible |
| **Environment switching** | Conditional logic in each caller | Centralized in registry |
| **API version updates** | Find/replace across codebase | Single registry update |
| **Audit granularity** | "Used secret X" | "Called stripe.create_charge" |
| **Developer onboarding** | Need API docs + credentials | Just service name |

> **Implementation Priority:** This feature builds on the HTTP client foundation and is planned for Phase 7+ after Rust FFI maturity is proven. The HTTP client interface is designed to be forward-compatible with this enhancement.

---

## 10. Event System

### 10.1 Event Definitions

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Event;

final readonly class SecretStoredEvent
{
    public function __construct(
        public string $identifier,
        public int $version,
        public ?int $ownerUid,
    ) {}
}

final readonly class SecretRetrievedEvent
{
    public function __construct(
        public string $identifier,
    ) {}
}

final readonly class SecretRotatedEvent
{
    public function __construct(
        public string $identifier,
        public int $previousVersion,
        public int $newVersion,
    ) {}
}

final readonly class SecretDeletedEvent
{
    public function __construct(
        public string $identifier,
    ) {}
}

final readonly class AccessDeniedEvent
{
    public function __construct(
        public string $identifier,
        public int $userUid,
        public string $action,
        public string $reason,
    ) {}
}

final readonly class MasterKeyRotatedEvent
{
    public function __construct(
        public int $secretsReEncrypted,
        public float $durationSeconds,
    ) {}
}
```

---

## 11. External Adapters

### 11.1 VaultAdapterInterface

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Adapter;

use Netresearch\NrVault\Domain\Model\Secret;

interface VaultAdapterInterface
{
    /**
     * Store encrypted secret data.
     */
    public function store(Secret $secret): void;

    /**
     * Retrieve secret by identifier.
     */
    public function findByIdentifier(string $identifier): ?Secret;

    /**
     * Check if secret exists.
     */
    public function exists(string $identifier): bool;

    /**
     * Update existing secret.
     */
    public function update(Secret $secret): void;

    /**
     * Delete secret.
     */
    public function delete(string $identifier): void;

    /**
     * List secrets matching criteria.
     *
     * @return Secret[]
     */
    public function findAll(array $filters = []): array;

    /**
     * Get adapter type identifier.
     */
    public function getType(): string;
}
```

### 11.2 HashiCorp Vault Adapter (Tier 4)

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Adapter;

use GuzzleHttp\ClientInterface;
use Netresearch\NrVault\Configuration\VaultConfiguration;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\AdapterException;

final class HashiCorpVaultAdapter implements VaultAdapterInterface
{
    private ?string $token = null;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly VaultConfiguration $configuration,
    ) {}

    public function store(Secret $secret): void
    {
        $path = $this->buildPath($secret->getIdentifier());

        $response = $this->httpClient->request('POST', $path, [
            'headers' => $this->getHeaders(),
            'json' => [
                'data' => [
                    'encrypted_dek' => $secret->getEncryptedDek(),
                    'dek_nonce' => $secret->getDekNonce(),
                    'encrypted_value' => $secret->getEncryptedValue(),
                    'value_nonce' => $secret->getValueNonce(),
                    'version' => $secret->getVersion(),
                    'owner_uid' => $secret->getOwnerUid(),
                    'allowed_groups' => $secret->getAllowedGroups(),
                    'description' => $secret->getDescription(),
                    'expires_at' => $secret->getExpiresAt()?->getTimestamp(),
                    'metadata' => $secret->getMetadata(),
                ],
            ],
        ]);

        if ($response->getStatusCode() !== 200 && $response->getStatusCode() !== 204) {
            throw new AdapterException(
                'Failed to store secret in HashiCorp Vault',
                1700000100
            );
        }
    }

    public function findByIdentifier(string $identifier): ?Secret
    {
        $path = $this->buildPath($identifier);

        try {
            $response = $this->httpClient->request('GET', $path, [
                'headers' => $this->getHeaders(),
            ]);
        } catch (\Exception) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = json_decode($response->getBody()->getContents(), true);
        $data = $body['data']['data'] ?? null;

        if ($data === null) {
            return null;
        }

        return $this->hydrateSecret($identifier, $data);
    }

    // ... other methods

    private function buildPath(string $identifier): string
    {
        $basePath = $this->configuration->getHashiCorpVaultPath();
        return rtrim($basePath, '/') . '/' . $identifier;
    }

    private function getHeaders(): array
    {
        return [
            'X-Vault-Token' => $this->getToken(),
            'Content-Type' => 'application/json',
        ];
    }

    private function getToken(): string
    {
        if ($this->token === null) {
            $this->token = $this->authenticate();
        }
        return $this->token;
    }

    private function authenticate(): string
    {
        // Implementation depends on auth method (token, approle, kubernetes)
        $authMethod = $this->configuration->getHashiCorpVaultAuthMethod();

        return match ($authMethod) {
            'token' => $this->configuration->getHashiCorpVaultToken(),
            'approle' => $this->authenticateAppRole(),
            'kubernetes' => $this->authenticateKubernetes(),
            default => throw new AdapterException('Unknown auth method: ' . $authMethod, 1700000101),
        };
    }

    public function getType(): string
    {
        return 'hashicorp';
    }
}
```

---

## 12. Testing Strategy

### 12.1 Unit Tests

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\KeyProvider\FileKeyProvider;
use Netresearch\NrVault\Service\EncryptionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EncryptionServiceTest extends TestCase
{
    private EncryptionService $encryptionService;
    private string $tempKeyFile;

    protected function setUp(): void
    {
        // Create temp key file
        $this->tempKeyFile = sys_get_temp_dir() . '/vault_test_key_' . uniqid();
        $key = random_bytes(32);
        file_put_contents($this->tempKeyFile, base64_encode($key));
        chmod($this->tempKeyFile, 0600);

        $keyProvider = new FileKeyProvider($this->tempKeyFile);
        $this->encryptionService = new EncryptionService($keyProvider);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempKeyFile)) {
            unlink($this->tempKeyFile);
        }
    }

    #[Test]
    public function encryptAndDecryptReturnsOriginalValue(): void
    {
        $plaintext = 'my-secret-api-key-12345';
        $identifier = 'test_secret';

        $encrypted = $this->encryptionService->encrypt($plaintext, $identifier);

        self::assertArrayHasKey('encrypted_dek', $encrypted);
        self::assertArrayHasKey('dek_nonce', $encrypted);
        self::assertArrayHasKey('encrypted_value', $encrypted);
        self::assertArrayHasKey('value_nonce', $encrypted);

        $decrypted = $this->encryptionService->decrypt(
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['encrypted_value'],
            $encrypted['value_nonce'],
            $identifier
        );

        self::assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function encryptProducesUniqueNonces(): void
    {
        $plaintext = 'same-secret';
        $identifier = 'test_secret';

        $encrypted1 = $this->encryptionService->encrypt($plaintext, $identifier);
        $encrypted2 = $this->encryptionService->encrypt($plaintext, $identifier);

        self::assertNotSame($encrypted1['dek_nonce'], $encrypted2['dek_nonce']);
        self::assertNotSame($encrypted1['value_nonce'], $encrypted2['value_nonce']);
    }

    #[Test]
    public function decryptWithWrongIdentifierFails(): void
    {
        $plaintext = 'my-secret';

        $encrypted = $this->encryptionService->encrypt($plaintext, 'correct_id');

        $this->expectException(\Netresearch\NrVault\Exception\DecryptionException::class);

        $this->encryptionService->decrypt(
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            $encrypted['encrypted_value'],
            $encrypted['value_nonce'],
            'wrong_id'
        );
    }

    #[Test]
    public function decryptWithTamperedCiphertextFails(): void
    {
        $plaintext = 'my-secret';
        $identifier = 'test_secret';

        $encrypted = $this->encryptionService->encrypt($plaintext, $identifier);

        // Tamper with the encrypted value
        $tamperedValue = base64_decode($encrypted['encrypted_value']);
        $tamperedValue[0] = chr(ord($tamperedValue[0]) ^ 0xFF);

        $this->expectException(\Netresearch\NrVault\Exception\DecryptionException::class);

        $this->encryptionService->decrypt(
            $encrypted['encrypted_dek'],
            $encrypted['dek_nonce'],
            base64_encode($tamperedValue),
            $encrypted['value_nonce'],
            $identifier
        );
    }
}
```

### 12.2 Functional Tests

```php
<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Service;

use Netresearch\NrVault\Service\VaultService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class VaultServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    private VaultService $vaultService;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test master key
        $keyPath = $this->getInstancePath() . '/vault-test.key';
        file_put_contents($keyPath, base64_encode(random_bytes(32)));
        chmod($keyPath, 0600);

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
            'masterKeyProvider' => 'file',
            'masterKeyPath' => $keyPath,
        ];

        $this->vaultService = $this->get(VaultService::class);
    }

    public function testStoreAndRetrieve(): void
    {
        $identifier = 'test_api_key';
        $secret = 'sk_live_123456789';

        $this->vaultService->store($identifier, $secret);

        $retrieved = $this->vaultService->retrieve($identifier);

        self::assertSame($secret, $retrieved);
    }

    public function testRotateIncreasesVersion(): void
    {
        $identifier = 'rotating_secret';

        $this->vaultService->store($identifier, 'version1');
        $meta1 = $this->vaultService->getMetadata($identifier);

        $this->vaultService->rotate($identifier, 'version2');
        $meta2 = $this->vaultService->getMetadata($identifier);

        self::assertSame(1, $meta1['version']);
        self::assertSame(2, $meta2['version']);
        self::assertSame('version2', $this->vaultService->retrieve($identifier));
    }

    public function testDeleteRemovesSecret(): void
    {
        $identifier = 'to_delete';

        $this->vaultService->store($identifier, 'secret');
        self::assertTrue($this->vaultService->exists($identifier));

        $this->vaultService->delete($identifier);
        self::assertFalse($this->vaultService->exists($identifier));
    }
}
```

---

## 13. Implementation Phases

### Phase 1: Core Foundation (Weeks 1-3)

**Goal:** Working encryption and basic storage

| Task | Files | Priority |
|------|-------|----------|
| Project setup | `composer.json`, `ext_emconf.php` | Critical |
| Database schema | `ext_tables.sql` | Critical |
| Exception hierarchy | `Classes/Exception/*.php` | Critical |
| EncryptionService | `Classes/Service/EncryptionService.php` | Critical |
| MasterKeyProviders | `Classes/KeyProvider/*.php` | Critical |
| LocalDatabaseAdapter | `Classes/Adapter/LocalDatabaseAdapter.php` | Critical |
| Secret model | `Classes/Domain/Model/Secret.php` | Critical |
| SecretRepository | `Classes/Domain/Repository/SecretRepository.php` | Critical |
| VaultService | `Classes/Service/VaultService.php` | Critical |
| Unit tests for crypto | `Tests/Unit/Service/EncryptionServiceTest.php` | Critical |

**Deliverable:** Secrets can be stored and retrieved via PHP API

---

### Phase 2: Access Control & Audit (Weeks 4-5)

**Goal:** Security controls in place

| Task | Files | Priority |
|------|-------|----------|
| AccessControlService | `Classes/Service/AccessControlService.php` | High |
| AuditLogService | `Classes/Service/AuditLogService.php` | High |
| AuditLogEntry model | `Classes/Domain/Model/AuditLogEntry.php` | High |
| AuditLogRepository | `Classes/Domain/Repository/AuditLogRepository.php` | High |
| Event classes | `Classes/Event/*.php` | High |
| Event dispatching in VaultService | Update `VaultService.php` | High |
| Integration tests | `Tests/Functional/Service/*.php` | High |

**Deliverable:** Multi-user access control and audit logging working

---

### Phase 3: TYPO3 Integration (Weeks 6-8)

**Goal:** Backend UI complete

| Task | Files | Priority |
|------|-------|----------|
| TCA configuration | `Configuration/TCA/*.php` | High |
| VaultSecretElement | `Classes/Form/Element/VaultSecretElement.php` | High |
| DataHandler hooks | `Classes/DataHandler/VaultSecretDataHandler.php` | High |
| Backend module | `Classes/Controller/VaultController.php` | High |
| Module registration | `Configuration/Backend/Modules.php` | High |
| Fluid templates | `Resources/Private/Templates/Backend/*.html` | High |
| JavaScript for TCA | `Resources/Public/JavaScript/*.js` | Medium |
| Localization | `Resources/Private/Language/*.xlf` | Medium |

**Deliverable:** Full backend experience

---

### Phase 4: CLI & HTTP Client (Weeks 9-11)

**Goal:** Automation ready + secure HTTP client

| Task | Files | Priority |
|------|-------|----------|
| StoreCommand | `Classes/Command/StoreCommand.php` | High |
| RetrieveCommand | `Classes/Command/RetrieveCommand.php` | High |
| RotateCommand | `Classes/Command/RotateCommand.php` | High |
| DeleteCommand | `Classes/Command/DeleteCommand.php` | High |
| ListCommand | `Classes/Command/ListCommand.php` | High |
| AuditCommand | `Classes/Command/AuditCommand.php` | Medium |
| MasterKeyGenerateCommand | `Classes/Command/MasterKeyGenerateCommand.php` | Medium |
| MasterKeyRotateCommand | `Classes/Command/MasterKeyRotateCommand.php` | Medium |
| **VaultHttpClientInterface** | `Classes/Http/VaultHttpClientInterface.php` | **High** |
| **SecretPlacement enum** | `Classes/Http/SecretPlacement.php` | **High** |
| **PhpVaultHttpClient** | `Classes/Http/PhpVaultHttpClient.php` | **High** |
| **VaultHttpClientFactory** | `Classes/Http/VaultHttpClientFactory.php` | **High** |
| **VaultHttpResponse** | `Classes/Http/VaultHttpResponse.php` | **High** |
| **HTTP client tests** | `Tests/Functional/Http/PhpVaultHttpClientTest.php` | **High** |
| Services.yaml updates | `Configuration/Services.yaml` | High |

**Deliverable:** CLI automation complete + secure HTTP client for API calls

---

### Phase 5: Migration & Polish (Weeks 11-12)

**Goal:** Production ready

| Task | Files | Priority |
|------|-------|----------|
| MigrateCommand | `Classes/Command/MigrateCommand.php` | Medium |
| SecretDetectionService | `Classes/Service/SecretDetectionService.php` | Medium |
| Documentation (RST) | `Documentation/**/*.rst` | High |
| Security guide | `Documentation/Security/*.rst` | High |
| PHPStan configuration | `phpstan.neon` | Medium |
| CI/CD configuration | `.github/workflows/*.yml` | Medium |
| TER release preparation | Various | High |

**Deliverable:** Ready for production use

---

### Phase 6: External Adapters & Rust FFI (Post-Release)

**Goal:** Enterprise features + maximum security option

| Task | Files | Priority |
|------|-------|----------|
| HashiCorpVaultAdapter | `Classes/Adapter/HashiCorpVaultAdapter.php` | Medium |
| AwsSecretsManagerAdapter | `Classes/Adapter/AwsSecretsManagerAdapter.php` | Medium |
| AzureKeyVaultAdapter | `Classes/Adapter/AzureKeyVaultAdapter.php` | Low |
| Adapter factory | `Classes/Adapter/AdapterFactory.php` | Medium |
| **Rust HTTP library** | `rust/src/lib.rs` | Medium |
| **RustVaultHttpClient** | `Classes/Http/RustVaultHttpClient.php` | Medium |
| **Rust build pipeline** | `.github/workflows/rust-build.yml` | Medium |
| **Docker multi-stage build** | `Build/Dockerfile.rust` | Low |
| Enterprise documentation | `Documentation/Enterprise/*.rst` | Medium |

**Deliverable:** Cloud-native options + Rust FFI for zero-PHP-exposure HTTP calls

**Rust FFI Benefits:**
- Secret NEVER enters PHP memory
- mlock'd memory (not swappable to disk)
- ~35% faster HTTP operations
- Connection pooling via Hyper
- Automatic fallback to PHP if FFI unavailable

---

### Phase 7: Service Registry (Future)

**Goal:** Complete endpoint abstraction - callers know only service names

| Task | Files | Priority |
|------|-------|----------|
| ServiceDefinition model | `Classes/Service/ServiceDefinition.php` | Medium |
| ServiceRegistry interface | `Classes/Service/ServiceRegistryInterface.php` | Medium |
| ServiceRegistry implementation | `Classes/Service/ServiceRegistry.php` | Medium |
| Database schema extension | `ext_tables.sql` (tx_nrvault_service) | Medium |
| TCA for service management | `Configuration/TCA/tx_nrvault_service.php` | Medium |
| Backend module extension | `Classes/Controller/ServiceController.php` | Medium |
| HTTP client integration | `Classes/Http/*` (withService, call methods) | High |
| Environment detection | `Classes/Service/EnvironmentDetector.php` | Low |
| Service import/export CLI | `Classes/Command/ServiceCommand.php` | Low |

**Deliverable:** Developers call services by name only, no credential or URL knowledge required

**Key Benefits:**
- Credential abstraction: Secret identifier not visible to caller
- Endpoint abstraction: URLs not visible to caller
- Environment isolation: Dev/staging/prod endpoints centrally managed
- API version management: Single update point for version changes
- Enhanced audit: Logs "called stripe.create_charge" not just "used secret X"

**Prerequisites:**
- Phase 4 HTTP client foundation complete
- Optional: Phase 6 Rust FFI for maximum security

---

## 14. File-by-File Implementation

### 14.1 Phase 1 Implementation Order (Core)

```
1.  composer.json
2.  ext_emconf.php
3.  ext_tables.sql
4.  Classes/Exception/VaultException.php
5.  Classes/Exception/EncryptionException.php
6.  Classes/Exception/DecryptionException.php
7.  Classes/Exception/MasterKeyException.php
8.  Classes/Exception/SecretNotFoundException.php
9.  Classes/Exception/AccessDeniedException.php
10. Classes/Exception/InvalidIdentifierException.php
11. Classes/Exception/SecretExpiredException.php
12. Classes/KeyProvider/MasterKeyProviderInterface.php
13. Classes/KeyProvider/FileKeyProvider.php
14. Classes/KeyProvider/EnvironmentKeyProvider.php
15. Classes/KeyProvider/DerivedKeyProvider.php
16. Classes/KeyProvider/MasterKeyProviderFactory.php
17. Classes/Service/EncryptionServiceInterface.php
18. Classes/Service/EncryptionService.php
19. Classes/Utility/IdentifierValidator.php
20. Classes/Domain/Model/Secret.php
21. Classes/Domain/Repository/SecretRepository.php
22. Classes/Adapter/VaultAdapterInterface.php
23. Classes/Adapter/LocalDatabaseAdapter.php
24. Classes/Configuration/VaultConfiguration.php
25. Classes/Configuration/VaultConfigurationFactory.php
26. Classes/Service/VaultServiceInterface.php
27. Classes/Service/VaultService.php
28. Configuration/Services.yaml
29. ext_localconf.php
30. Tests/Unit/Service/EncryptionServiceTest.php
31. Tests/Unit/KeyProvider/FileKeyProviderTest.php
```

### 14.2 Phase 4 Implementation Order (HTTP Client)

```
1.  Classes/Http/SecretPlacement.php
2.  Classes/Http/SecretBinding.php
3.  Classes/Http/OAuthConfig.php
4.  Classes/Http/VaultHttpResponse.php
5.  Classes/Http/Exception/VaultHttpException.php
6.  Classes/Http/Exception/SecretInjectionException.php
7.  Classes/Http/VaultHttpClientInterface.php
8.  Classes/Http/PhpVaultHttpClient.php
9.  Classes/Http/VaultHttpClientFactory.php
10. Update: Classes/Service/VaultServiceInterface.php (add http() method)
11. Update: Classes/Service/VaultService.php (implement http() method)
12. Update: Configuration/Services.yaml (register HTTP services)
13. Tests/Unit/Http/SecretPlacementTest.php
14. Tests/Functional/Http/PhpVaultHttpClientTest.php
15. Tests/Functional/Http/VaultHttpIntegrationTest.php
```

### 14.3 Phase 6 Implementation Order (Rust FFI - Optional)

```
1.  rust/Cargo.toml
2.  rust/src/lib.rs
3.  rust/src/http.rs
4.  rust/src/crypto.rs
5.  rust/src/db.rs
6.  rust/src/ffi.rs
7.  Build/Dockerfile.rust
8.  .github/workflows/rust-build.yml
9.  Classes/Http/RustVaultHttpClient.php
10. Update: Classes/Http/VaultHttpClientFactory.php (add Rust support)
11. Tests/Functional/Http/RustVaultHttpClientTest.php
12. Documentation/Enterprise/RustFfi.rst
```

### 14.2 Quality Gates

Before proceeding to each phase, ensure:

| Phase | Quality Gate |
|-------|-------------|
| 1 → 2 | All unit tests pass, encryption round-trip verified |
| 2 → 3 | Access control integration tests pass |
| 3 → 4 | Backend module functional, TCA field working |
| 4 → 5 | All CLI commands working, --help documented |
| 5 → 6 | Documentation complete, PHPStan level 8 passing |

---

## Appendix A: Configuration Reference

```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
    // Storage adapter: 'local', 'hashicorp', 'aws', 'azure'
    'adapter' => 'local',

    // Master key provider: 'file', 'env', 'derived'
    'masterKeyProvider' => 'file',

    // File provider settings
    'masterKeyPath' => '/var/secrets/typo3/vault-master.key',

    // Environment provider settings
    'masterKeyEnvVar' => 'NR_VAULT_MASTER_KEY',

    // Derived provider settings
    'derivedKeySaltPath' => '/var/secrets/typo3/vault-salt.key',

    // Audit settings
    'auditLogRetention' => 365,  // days, 0 = forever
    'auditHashChain' => false,   // Enable for Tier 3

    // CLI settings
    'allowCliAccess' => true,

    // HashiCorp Vault adapter settings
    'hashicorp' => [
        'address' => 'https://vault.example.com:8200',
        'path' => 'secret/data/typo3/',
        'authMethod' => 'token',  // 'token', 'approle', 'kubernetes'
        'token' => '',
    ],

    // AWS adapter settings
    'aws' => [
        'region' => 'eu-west-1',
        'secretPrefix' => 'typo3/',
    ],
];
```

---

## Appendix B: Security Invariants

These conditions MUST always hold:

1. **Secrets never stored in plaintext** - Database always contains encrypted blob
2. **Master key never in database** - Separate file/env/derived storage
3. **Decrypted secrets never cached persistently** - Request-scoped only
4. **Every access is logged** - No silent retrieval
5. **Access control checked before decryption** - Fail fast
6. **Cryptographic failures are fatal** - No fallback to weak crypto
7. **Unique nonce per encryption** - AES-GCM security depends on this

---

## Appendix C: Recommended BE Group Setup

### Dedicated Permission Groups

Create dedicated BE groups for secret management:

```
Backend User Groups:
├── secret_managers           # Full vault access (admins)
│   ├── secret_managers_hr    # Access to context: "hr"
│   ├── secret_managers_marketing  # Access to context: "marketing"
│   ├── secret_managers_api   # Access to context: "api"
│   └── secret_managers_devops # Access to context: "devops"
```

### TSconfig for Backend Module Access

```typoscript
# Configuration/TsConfig/Page/BackendLayouts.tsconfig

# Hide vault module from non-secret-managers
[backend.user.isAdmin == 0] && [backend.user.groupIds not matches '/^(1|2|3)$/']
  options.hideModules := addToList(system_vault)
[end]
```

### Context-Based Permission Scoping

The `context` field enables department-level permission control:

| Context | Description | Typical Users |
|---------|-------------|---------------|
| `hr` | HR system integrations | HR Admins |
| `marketing` | Newsletter, CRM, analytics | Marketing Managers |
| `api` | Third-party API keys | Integrators |
| `payment` | Payment gateway credentials | Shop Managers |
| `devops` | Infrastructure credentials | System Admins |

---

## Appendix D: UX Guardrails

### Forced Rotation Reason

For compliance, require reason when rotating/deleting secrets:

```php
// In VaultSecretElement
$html[] = '<div class="vault-rotation-reason" style="display:none">';
$html[] = '<label for="' . $fieldId . '_reason">Reason for change (required)</label>';
$html[] = sprintf(
    '<textarea id="%s_reason" name="%s[_vault_reason]" class="form-control" ' .
    'placeholder="e.g., Quarterly rotation, Key compromised, Employee departure" required></textarea>',
    htmlspecialchars($fieldId),
    htmlspecialchars($itemFormElementName)
);
$html[] = '</div>';
```

### Expiry Warning Badges

Display visual warnings for expiring secrets:

```html
<!-- In Backend Module -->
<f:if condition="{secret.expiresAt}">
    <f:variable name="daysUntilExpiry">{secret.expiresAt -> f:format.date(format: 'U') -> f:math.subtract(operand: 'now' -> f:format.date(format: 'U')) -> f:math.divide(operand: 86400)}</f:variable>

    <f:if condition="{daysUntilExpiry} < 0">
        <span class="badge badge-danger">EXPIRED</span>
    </f:if>
    <f:if condition="{daysUntilExpiry} >= 0 && {daysUntilExpiry} < 7">
        <span class="badge badge-warning">Expires in {daysUntilExpiry} days</span>
    </f:if>
    <f:if condition="{daysUntilExpiry} >= 7 && {daysUntilExpiry} < 30">
        <span class="badge badge-info">Expires in {daysUntilExpiry} days</span>
    </f:if>
</f:if>
```

### Read-Only Display for Non-Owners

```php
// In AccessControlService
public function canEdit(Secret $secret): bool
{
    if ($this->isAdmin()) {
        return true;
    }

    // Only owner or users in allowed groups with edit permission
    if ($secret->getOwnerUid() === $this->getCurrentUserUid()) {
        return true;
    }

    // Check if user's group has explicit edit permission
    $editGroups = $this->getGroupsWithEditPermission($secret);
    return !empty(array_intersect($editGroups, $this->getCurrentUserGroups()));
}
```

### Secret Never Displayed After Save

**Critical UX rule**: Once a secret is saved, it can NEVER be retrieved and displayed in the backend.

The TCA field shows:
- Status indicator (configured/not configured)
- Last rotation date
- Version number
- Expiry warning if applicable

But NEVER the actual value. Users must enter a new value to replace.

---

## Appendix E: What We Explicitly Do NOT Implement

Based on security best practices and scope management:

| Excluded Feature | Reason |
|------------------|--------|
| YAML-based secret config | Secrets in version control = breach waiting to happen |
| Plaintext fallbacks | Violates security invariant #1 |
| ENV editing from backend | Attack surface: backend compromise → all secrets |
| Inline storage in foreign tables | Scattered secrets = audit nightmare |
| Secret length hints | Information leakage |
| Password strength meter | API keys aren't passwords |
| Copy-to-clipboard | Audit trail bypass |
| Bulk export of decrypted values | Mass exfiltration vector |

---

*Document Version: 1.3.0*
*Last Updated: 2025-12-28*
*Status: Implementation Ready*
*Incorporates feedback from alternative planning document*
