# nr-vault Use Cases and User Stories

## Executive Summary

This document defines real-world use cases for secret storage in TYPO3, user personas, and prioritized user stories to guide nr-vault development. The goal is to provide a comprehensive secrets management solution that addresses the security gaps in current TYPO3 implementations.

---

## Table of Contents

1. [Secret Storage Use Cases](#1-secret-storage-use-cases)
2. [User Personas](#2-user-personas)
3. [User Stories](#3-user-stories)
4. [Use Case Prioritization](#4-use-case-prioritization)
5. [Implementation Roadmap](#5-implementation-roadmap)

---

## 1. Secret Storage Use Cases

### 1.1 Payment Gateway Credentials

#### Providers
- Stripe (API keys, webhook secrets)
- PayPal (Client ID, Client Secret, Webhook ID)
- Mollie (API keys)
- Klarna (API credentials)
- Adyen (API key, Client key, Merchant account)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Full access for payment processing |
| TYPO3 Admin | Configure/rotate credentials |
| Shop Manager (Editor) | View masked, cannot modify |
| Developer | No production access |

#### Credential Lifecycle
- **Change Frequency**: Annually or after security incidents
- **Rotation Trigger**: Employee departure, suspected breach, compliance audit
- **Versioning**: Required for rollback capability

#### Compliance Requirements
- **PCI-DSS**: Payment Card Industry Data Security Standard
  - Requirement 3.4: Render PAN unreadable anywhere stored
  - Requirement 8.2.1: Strong cryptography for credential storage
  - Requirement 10.2: Audit trails for access to cardholder data
- **Audit Trail**: All access must be logged with timestamp, user, IP

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | Critical | Medium | **High** |
| Unauthorized transactions | Critical | Low | Medium |
| Compliance violation | High | Medium | **High** |

#### Current TYPO3 Implementation (Problematic)
```php
// Typical insecure pattern in extension settings
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['cart_stripe'] = [
    'secretKey' => 'sk_live_...',  // Stored in LocalConfiguration.php
    'publishableKey' => 'pk_live_...',
];
```

**Problems**:
- Credentials in version control
- Visible in TYPO3 Install Tool
- No access control
- No audit logging
- Plaintext in database dumps

---

### 1.2 Email Service Credentials

#### Providers
- SMTP servers (username/password)
- Mailchimp (API key)
- Sendinblue/Brevo (API key)
- SendGrid (API key)
- Amazon SES (Access key, Secret key)
- Mailjet (API key, Secret key)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Send emails |
| TYPO3 Admin | Configure credentials |
| Marketing Editor | Configure campaigns (Mailchimp integration) |
| Developer | Local test credentials only |

#### Credential Lifecycle
- **Change Frequency**: Every 6-12 months (best practice)
- **Rotation Trigger**: Suspected spam abuse, employee changes
- **Service-specific**: Some providers require key regeneration after breach

#### Compliance Requirements
- **GDPR**: Email addresses are personal data; access must be controlled
- **CAN-SPAM/GDPR**: Audit trail for who sent what

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | High | Medium | **High** |
| Spam abuse | High | Medium | **High** |
| Service quota exhaustion | Medium | Low | Low |
| Reputation damage | High | Medium | **High** |

#### Current TYPO3 Implementation (Problematic)
```php
// Install Tool / Settings
$GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
    'transport' => 'smtp',
    'transport_smtp_server' => 'smtp.example.com:587',
    'transport_smtp_username' => 'user@example.com',
    'transport_smtp_password' => 'plaintext_password',  // Visible everywhere
];
```

---

### 1.3 CRM Integration Tokens

#### Providers
- Salesforce (OAuth tokens, API credentials)
- HubSpot (API key, OAuth tokens)
- Pipedrive (API token)
- Microsoft Dynamics (OAuth credentials)
- Zoho CRM (OAuth tokens)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Sync data with CRM |
| TYPO3 Admin | Initial configuration |
| Sales Team Lead | May need to reconfigure after Salesforce changes |
| Marketing Editor | Read-only sync status |

#### Credential Lifecycle
- **Change Frequency**: OAuth tokens auto-rotate; API keys 6-12 months
- **Rotation Trigger**: User deauthorization, permission changes
- **Special Consideration**: OAuth refresh tokens must be stored securely

#### Compliance Requirements
- **GDPR**: Customer data flows between systems
- **Data Processing Agreements**: Audit who accessed what data
- **SOC 2**: For enterprise CRM integrations

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | High | Medium | **High** |
| Unauthorized data access | Critical | Medium | **High** |
| Compliance violation | High | Low | Medium |
| Business disruption | Medium | Medium | Medium |

#### Current TYPO3 Implementation (Problematic)
```php
// Extension configuration record
$GLOBALS['TCA']['tx_mycrm_settings'] = [
    'columns' => [
        'api_token' => [
            'config' => [
                'type' => 'input',
                // Stored as plaintext in database
            ],
        ],
    ],
];
```

---

### 1.4 Social Media API Keys

#### Providers
- Meta (Facebook/Instagram) - App ID, App Secret, Access Tokens
- Twitter/X - API Key, API Secret, Bearer Token
- LinkedIn - Client ID, Client Secret
- YouTube/Google - API Key, OAuth credentials
- TikTok - App credentials

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Post/fetch content |
| TYPO3 Admin | Configure API connections |
| Social Media Manager | May need to reconnect accounts |
| Content Editor | Use features, not configure |

#### Credential Lifecycle
- **Change Frequency**: Access tokens expire; app secrets rarely change
- **Rotation Trigger**: Account compromise, app review failure
- **OAuth Complexity**: Tokens have varying lifetimes (hours to never expire)

#### Compliance Requirements
- **Platform Terms of Service**: Secure storage required by all platforms
- **GDPR**: Social data may contain personal information

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | Medium | Medium | Medium |
| Account takeover | High | Low | Medium |
| Unauthorized posting | High | Low | Medium |
| API rate limit exhaustion | Low | Medium | Low |

---

### 1.5 Search Service Credentials

#### Providers
- Elasticsearch (API key, basic auth)
- Algolia (Application ID, Admin API Key, Search API Key)
- Meilisearch (Master key, API keys)
- Apache Solr (Basic auth if configured)
- Typesense (API key)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Full search operations |
| TYPO3 Admin | Configure and manage |
| Developer | Read access for debugging |
| Editor | No direct access needed |

#### Credential Lifecycle
- **Change Frequency**: Algolia recommends annual rotation
- **Key Types**: Admin keys (full access) vs Search keys (read-only)
- **Rotation Trigger**: Key exposure, security audit

#### Compliance Requirements
- **Data Residency**: Search indices may replicate data; access must be controlled
- **PII Handling**: If search indexes personal data, GDPR applies

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Admin key exposure | High | Medium | **High** |
| Search key exposure | Low | Medium | Low |
| Data manipulation | High | Low | Medium |
| Service disruption | Medium | Low | Low |

---

### 1.6 CDN and Storage Credentials

#### Providers
- AWS S3 (Access Key ID, Secret Access Key)
- Azure Blob Storage (Connection string, SAS tokens)
- Google Cloud Storage (Service account JSON)
- Cloudflare (API token)
- DigitalOcean Spaces (Access keys)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Upload/download files |
| TYPO3 Admin | Configure storage backends |
| DevOps | Rotate credentials |
| Editor | No direct access |

#### Credential Lifecycle
- **Change Frequency**: Every 90 days (AWS best practice)
- **Rotation Trigger**: Employee departure, suspected exposure
- **Automation**: Consider IAM roles over static credentials

#### Compliance Requirements
- **Data Residency**: Storage location matters for GDPR
- **Access Logging**: Cloud providers have their own audit logs
- **Least Privilege**: Credentials should have minimal required permissions

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | Critical | Medium | **Critical** |
| Data breach | Critical | Medium | **Critical** |
| Unexpected costs | High | Medium | **High** |
| Data loss | Critical | Low | High |

#### Current TYPO3 Implementation (Problematic)
```php
// AWS FAL driver configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['aus_driver_amazon_s3'] = [
    'key' => 'AKIAIOSFODNN7EXAMPLE',  // In LocalConfiguration.php
    'secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
];
```

---

### 1.7 Analytics Tokens

#### Providers
- Google Analytics (API credentials, Measurement Protocol secret)
- Matomo (Token auth)
- Adobe Analytics (API credentials)
- Plausible (API key)
- Fathom (Site ID - less sensitive)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Send tracking data |
| TYPO3 Admin | Configure integration |
| Marketing Analyst | May need for advanced config |
| Editor | No access needed |

#### Credential Lifecycle
- **Change Frequency**: Rarely, unless compromised
- **Rotation Trigger**: Personnel changes, security audit

#### Compliance Requirements
- **GDPR**: Analytics involves personal data processing
- **ePrivacy**: Cookie consent implications
- **Audit Requirements**: Document who can access analytics data

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Token exposure | Low | Medium | Low |
| Data manipulation | Medium | Low | Low |
| Privacy violation | Medium | Low | Low |

---

### 1.8 Newsletter Service API Keys

#### Providers
- CleverReach (API token, OAuth)
- Newsletter2Go (API key)
- Mailerlite (API key)
- ConvertKit (API secret)
- ActiveCampaign (API URL, API Key)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Sync subscribers |
| TYPO3 Admin | Configure integration |
| Marketing Manager | May need reconfiguration access |
| Editor | Use features only |

#### Credential Lifecycle
- **Change Frequency**: Annually or on personnel change
- **Rotation Trigger**: Suspected spam, API abuse

#### Compliance Requirements
- **GDPR**: Newsletter subscriptions are consent records
- **Audit Trail**: Who added/removed subscribers
- **Double Opt-In**: May need credential access for verification

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | High | Medium | **High** |
| Spam sending | High | Medium | **High** |
| List manipulation | Medium | Low | Medium |
| GDPR violation | High | Low | Medium |

---

### 1.9 SMS Gateway Credentials

#### Providers
- Twilio (Account SID, Auth Token)
- Vonage/Nexmo (API Key, API Secret)
- MessageBird (Access Key)
- Plivo (Auth ID, Auth Token)
- AWS SNS (IAM credentials)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Send SMS (2FA, notifications) |
| TYPO3 Admin | Configure credentials |
| Security Officer | Audit 2FA usage |
| Editor | No access |

#### Credential Lifecycle
- **Change Frequency**: Annually
- **Rotation Trigger**: Unusual billing, suspected abuse

#### Compliance Requirements
- **PSD2/SCA**: If used for payment authentication
- **GDPR**: Phone numbers are personal data
- **Audit Trail**: All SMS sends should be logged

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | High | Medium | **High** |
| Fraud/smishing | Critical | Low | High |
| Billing abuse | High | Medium | **High** |
| 2FA bypass | Critical | Low | High |

---

### 1.10 OAuth Client Secrets

#### Purpose
Application-level OAuth credentials for server-to-server authentication.

#### Examples
- Google OAuth (Client ID, Client Secret)
- Microsoft Azure AD (Client Secret)
- Auth0 (Domain, Client ID, Client Secret)
- Keycloak (Client credentials)
- Generic OIDC providers

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Authenticate with providers |
| TYPO3 Admin | Configure authentication |
| Identity Admin | Manage OAuth applications |
| Editor | No access |

#### Credential Lifecycle
- **Change Frequency**: 12-24 months (provider-dependent)
- **Rotation Trigger**: Security incident, compliance requirement
- **Auto-Expiry**: Some providers enforce credential expiration

#### Compliance Requirements
- **SOC 2**: Secure credential storage
- **OAuth 2.0 Security BCP**: RFC 6819 guidelines
- **GDPR**: If user data flows through OAuth

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Client secret exposure | Critical | Medium | **Critical** |
| Account impersonation | Critical | Low | High |
| Data breach | Critical | Low | High |

---

### 1.11 External Database Credentials

#### Purpose
Connections to external databases for data synchronization, ETL, or reporting.

#### Examples
- Legacy system databases
- Data warehouse connections
- External CMS migrations
- Reporting database replicas
- Third-party system integrations

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Query/sync data |
| TYPO3 Admin | Configure connections |
| Database Admin | Rotate credentials |
| Developer | Read-only for debugging |

#### Credential Lifecycle
- **Change Frequency**: Every 90 days (best practice)
- **Rotation Trigger**: DBA changes, security audit
- **Complexity**: May require coordinated rotation with external teams

#### Compliance Requirements
- **SOX**: For financial data
- **HIPAA**: For healthcare data
- **GDPR**: For personal data synchronization

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | Critical | Medium | **Critical** |
| Unauthorized data access | Critical | Medium | **Critical** |
| Data manipulation | Critical | Low | High |
| Compliance violation | High | Medium | **High** |

---

### 1.12 LDAP/Active Directory Credentials

#### Purpose
Backend authentication and user synchronization.

#### Components
- Service account DN and password
- TLS certificates (optional)
- API tokens for cloud directories (Azure AD, Okta)

#### Access Requirements
| Role | Access Level |
|------|-------------|
| System | Authenticate users |
| TYPO3 Admin | Configure LDAP integration |
| Identity Admin | Manage service accounts |
| Editor | No access |

#### Credential Lifecycle
- **Change Frequency**: Every 90 days (AD policy)
- **Rotation Trigger**: Service account password expiry
- **Complexity**: Must coordinate with IT/AD team

#### Compliance Requirements
- **SOC 2**: Identity management controls
- **GDPR**: User directory contains personal data
- **Internal Policies**: AD typically has password policies

#### Risk Assessment
| Risk | Impact | Likelihood | Overall |
|------|--------|------------|---------|
| Credential exposure | Critical | Medium | **Critical** |
| Privilege escalation | Critical | Low | High |
| Authentication bypass | Critical | Low | High |
| User enumeration | Medium | Medium | Medium |

#### Current TYPO3 Implementation (Problematic)
```php
// ldap_fe_users extension configuration
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['ldap'] = [
    'bind_dn' => 'cn=typo3,ou=services,dc=example,dc=com',
    'bind_password' => 'plaintext_password',  // Visible in Install Tool
];
```

---

## 2. User Personas

### 2.1 TYPO3 Administrator (Anna)

**Profile**
- **Role**: System Administrator
- **Technical Level**: High
- **Primary Responsibilities**:
  - Server and TYPO3 installation management
  - Extension installation and configuration
  - User management and access control
  - Backup and disaster recovery
  - Security monitoring

**Goals**
- Centralize secret management across all extensions
- Implement proper access controls for credentials
- Maintain audit trails for compliance
- Simplify credential rotation
- Reduce security risks from misconfigured extensions

**Pain Points**
- Secrets scattered across LocalConfiguration.php, extension settings, database
- No visibility into who accessed which credentials
- Credential rotation requires touching multiple places
- Cannot delegate specific credential management to other roles
- Database dumps contain plaintext credentials

**Needs from nr-vault**
- Single dashboard for all secrets
- Granular access control via BE user groups
- Audit log with export capability
- CLI tools for automation
- Easy credential rotation

---

### 2.2 Marketing Editor (Martin)

**Profile**
- **Role**: Marketing Manager / Content Editor
- **Technical Level**: Medium
- **Primary Responsibilities**:
  - Email campaign management
  - Social media integrations
  - Newsletter configuration
  - Analytics review

**Goals**
- Configure marketing tool integrations
- Update API keys when services require it
- Not deal with overly technical interfaces

**Pain Points**
- Needs admin help for every API key change
- Unclear what credentials are stored where
- Accidentally exposed API key in shared screen

**Needs from nr-vault**
- Self-service for marketing-related credentials
- Clear UI for updating specific integrations
- Masked display by default
- No access to other system credentials

---

### 2.3 Extension Developer (David)

**Profile**
- **Role**: TYPO3 Extension Developer
- **Technical Level**: Expert
- **Primary Responsibilities**:
  - Develop extensions requiring API integrations
  - Implement secure credential storage
  - Test integrations across environments

**Goals**
- Easy-to-use API for secret storage/retrieval
- Don't reinvent encryption wheel
- Proper separation of dev/staging/prod credentials
- Clear documentation and examples

**Pain Points**
- Every extension handles credentials differently
- No standard way to ask for API keys
- TCA `type=password` hashes values (unusable for API keys)
- Testing with production credentials is risky

**Needs from nr-vault**
- Simple PHP API: `$vault->retrieve('my_secret')`
- TCA field type for secret input
- Environment-aware configuration
- Clear security guarantees

---

### 2.4 DevOps Engineer (Diana)

**Profile**
- **Role**: DevOps / Platform Engineer
- **Technical Level**: Expert
- **Primary Responsibilities**:
  - CI/CD pipeline management
  - Container orchestration
  - Secret injection at deployment
  - Infrastructure as Code

**Goals**
- Integrate TYPO3 secrets with external secret management
- Automate credential rotation
- Maintain consistency across environments
- No secrets in git repositories

**Pain Points**
- TYPO3 LocalConfiguration.php doesn't fit 12-factor app model
- No standard way to inject secrets at runtime
- Different extensions store secrets differently
- Hard to audit what secrets are used

**Needs from nr-vault**
- HashiCorp Vault / AWS Secrets Manager integration
- Environment variable support
- CLI for automation
- Secrets inventory export

---

## 3. User Stories

### 3.1 Administrator User Stories

#### US-A01: Store Payment Gateway Credentials
**As a** TYPO3 administrator
**I want to** securely store Stripe API keys in a central vault
**So that** they are encrypted and access-controlled, not visible in LocalConfiguration.php

**Acceptance Criteria**:
- [ ] Can store Stripe secret key with a descriptive identifier
- [ ] Secret is encrypted at rest using AES-256-GCM
- [ ] Secret is not visible in database dumps without master key
- [ ] Can assign access to specific BE user groups
- [ ] Storage action is logged in audit trail

**Priority**: Critical
**Estimate**: 3 story points

---

#### US-A02: Rotate Compromised Credentials
**As a** TYPO3 administrator
**I want to** rotate a compromised API key without service interruption
**So that** the new credential takes effect immediately across all usages

**Acceptance Criteria**:
- [ ] Can update secret value through backend module
- [ ] Old value is replaced atomically
- [ ] All code using `vault->retrieve()` gets new value immediately
- [ ] Rotation is logged with timestamp and actor
- [ ] Optional: Keep version history for rollback

**Priority**: High
**Estimate**: 2 story points

---

#### US-A03: Audit Secret Access
**As a** TYPO3 administrator
**I want to** view who accessed which secrets and when
**So that** I can investigate security incidents and demonstrate compliance

**Acceptance Criteria**:
- [ ] Audit log shows all read/write operations
- [ ] Each entry includes: timestamp, user, IP, action, identifier
- [ ] Can filter by date range, user, secret identifier
- [ ] Can export audit log as CSV/JSON
- [ ] Log entries cannot be modified or deleted through UI

**Priority**: High
**Estimate**: 3 story points

---

#### US-A04: Manage Secret Expiration
**As a** TYPO3 administrator
**I want to** set expiration dates on secrets
**So that** I'm notified when credentials need rotation and expired secrets are rejected

**Acceptance Criteria**:
- [ ] Can set optional expiration date when storing secret
- [ ] Dashboard shows secrets expiring in next 30/60/90 days
- [ ] Optional: Email notification before expiration
- [ ] Retrieving expired secret throws clear exception
- [ ] Can extend expiration without changing value

**Priority**: Medium
**Estimate**: 2 story points

---

#### US-A05: Delegate Credential Management
**As a** TYPO3 administrator
**I want to** allow marketing editors to manage newsletter API keys
**So that** they can update credentials without admin intervention while maintaining security

**Acceptance Criteria**:
- [ ] Can create secrets accessible to specific BE groups
- [ ] Users in those groups can view/edit only those secrets
- [ ] Marketing users cannot access payment or LDAP credentials
- [ ] All actions by delegated users are audit logged
- [ ] Delegation can be revoked by admin

**Priority**: High
**Estimate**: 3 story points

---

#### US-A06: Backup and Restore Secrets
**As a** TYPO3 administrator
**I want to** backup all secrets securely and restore them on a new installation
**So that** I can migrate TYPO3 instances without losing encrypted credentials

**Acceptance Criteria**:
- [ ] CLI command exports encrypted secrets bundle
- [ ] Export file is useless without master key
- [ ] Can import secrets to fresh installation with master key
- [ ] Import respects or remaps user group assignments
- [ ] Process is documented for disaster recovery

**Priority**: Medium
**Estimate**: 3 story points

---

### 3.2 Developer User Stories

#### US-D01: Store Secrets via PHP API
**As an** extension developer
**I want to** store API keys using a simple service method
**So that** I don't have to implement encryption myself

**Acceptance Criteria**:
- [ ] Can inject `VaultService` via constructor
- [ ] `$vault->store($identifier, $secret)` encrypts and persists
- [ ] Can specify owner and allowed groups
- [ ] Invalid identifiers throw clear exception
- [ ] Duplicate identifiers update existing secret

**Priority**: Critical
**Estimate**: 5 story points

```php
// Example usage
public function __construct(
    private readonly VaultService $vault,
) {}

public function configurePayment(string $apiKey): void
{
    $this->vault->store(
        identifier: 'my_extension_payment_api_key',
        secret: $apiKey,
        options: ['groups' => [1, 2]]
    );
}
```

---

#### US-D02: Retrieve Secrets via PHP API
**As an** extension developer
**I want to** retrieve decrypted secrets using a simple method
**So that** my extension can use API keys without knowing encryption details

**Acceptance Criteria**:
- [ ] `$vault->retrieve($identifier)` returns decrypted value
- [ ] Returns `null` for non-existent secrets
- [ ] Throws `AccessDeniedException` if user lacks permission
- [ ] Read operation is audit logged
- [ ] Value is cached for current request (no repeated decryption)

**Priority**: Critical
**Estimate**: 3 story points

```php
// Example usage
$apiKey = $this->vault->retrieve('my_extension_payment_api_key');
if ($apiKey === null) {
    throw new ConfigurationException('Payment API key not configured');
}
$this->paymentGateway->setApiKey($apiKey);
```

---

#### US-D03: TCA Secret Field
**As an** extension developer
**I want to** add a vault-backed secret field to my TCA configuration
**So that** editors can enter API keys through familiar TYPO3 forms

**Acceptance Criteria**:
- [ ] `renderType => 'vaultSecret'` renders masked input field
- [ ] Submitted value is stored in vault, not record field
- [ ] Record field stores only vault identifier reference
- [ ] Can optionally show "Rotate" button
- [ ] Field respects TCA access settings

**Priority**: High
**Estimate**: 5 story points

```php
// Example TCA
'api_key' => [
    'label' => 'API Key',
    'config' => [
        'type' => 'user',
        'renderType' => 'vaultSecret',
        'parameters' => [
            'vaultIdentifier' => 'myext_{uid}_api_key',
            'showRotateButton' => true,
        ],
    ],
],
```

---

#### US-D04: Check Secret Existence
**As an** extension developer
**I want to** check if a secret exists without retrieving it
**So that** I can provide helpful configuration status messages

**Acceptance Criteria**:
- [ ] `$vault->exists($identifier)` returns boolean
- [ ] Does not require full decryption
- [ ] Does not log as "read" access (only "exists check")
- [ ] Works without access to the secret value

**Priority**: Medium
**Estimate**: 1 story point

---

#### US-D05: List Available Secrets
**As an** extension developer
**I want to** list secrets my extension has stored
**So that** I can provide configuration overview in backend module

**Acceptance Criteria**:
- [ ] `$vault->list(['prefix' => 'myext_'])` returns matching identifiers
- [ ] Returns only identifiers user has access to
- [ ] Does not return secret values
- [ ] Can filter by metadata (owner, groups, expiration)

**Priority**: Medium
**Estimate**: 2 story points

---

#### US-D06: Environment-Specific Secrets
**As an** extension developer
**I want to** use different secrets per TYPO3 application context
**So that** development, staging, and production use appropriate credentials

**Acceptance Criteria**:
- [ ] Identifier can include context: `{context}/payment_key`
- [ ] Or: separate vault databases per environment
- [ ] Or: adapter that maps identifiers by context
- [ ] Clear documentation for multi-environment setup

**Priority**: Medium
**Estimate**: 3 story points

---

### 3.3 Editor User Stories

#### US-E01: View Configured Integrations
**As a** marketing editor
**I want to** see which integrations have credentials configured
**So that** I know what's working and what needs setup

**Acceptance Criteria**:
- [ ] Can view list of secrets in my allowed groups
- [ ] Shows identifier, description, last updated, expires
- [ ] Does NOT show actual secret values
- [ ] Can see "configured" vs "not configured" status

**Priority**: High
**Estimate**: 2 story points

---

#### US-E02: Update Integration Credentials
**As a** marketing editor
**I want to** update the Mailchimp API key when it changes
**So that** newsletter integration continues working without admin help

**Acceptance Criteria**:
- [ ] Can access secrets management for allowed groups only
- [ ] Can update secret value through masked input
- [ ] Cannot see current value (enter-to-replace only)
- [ ] Update is audit logged with my user ID
- [ ] Cannot delete secrets (admin only)

**Priority**: High
**Estimate**: 2 story points

---

#### US-E03: Request New Integration Setup
**As an** editor
**I want to** request a new integration be configured
**So that** admins know what credentials to set up

**Acceptance Criteria**:
- [ ] Can see list of "required but not configured" secrets
- [ ] Can trigger notification to admin
- [ ] Status shows "pending" until admin configures

**Priority**: Low
**Estimate**: 2 story points

---

### 3.4 DevOps User Stories

#### US-O01: CLI Secret Management
**As a** DevOps engineer
**I want to** manage secrets via CLI commands
**So that** I can automate credential deployment in CI/CD pipelines

**Acceptance Criteria**:
- [ ] `vault:store <identifier> <value>` stores secret
- [ ] `vault:retrieve <identifier>` outputs decrypted value
- [ ] `vault:rotate <identifier> <new-value>` rotates secret
- [ ] `vault:list` shows all secrets
- [ ] `vault:delete <identifier>` removes secret
- [ ] All commands respect `--quiet` and `--json` flags

**Priority**: High
**Estimate**: 3 story points

```bash
# Example CI/CD usage
./vendor/bin/typo3 vault:store stripe_api_key "$STRIPE_SECRET" --groups=1
./vendor/bin/typo3 vault:rotate aws_access_key "$NEW_AWS_KEY"
./vendor/bin/typo3 vault:list --json | jq '.[] | .identifier'
```

---

#### US-O02: HashiCorp Vault Integration
**As a** DevOps engineer
**I want to** use HashiCorp Vault as the secret backend
**So that** TYPO3 secrets are managed alongside other infrastructure secrets

**Acceptance Criteria**:
- [ ] Adapter implements VaultAdapterInterface
- [ ] Configuration specifies Vault address, auth method, mount path
- [ ] Supports token, AppRole, and Kubernetes auth methods
- [ ] Secrets read from Vault on retrieve(), not cached permanently
- [ ] Works with Vault Enterprise namespaces

**Priority**: Medium
**Estimate**: 5 story points

---

#### US-O03: AWS Secrets Manager Integration
**As a** DevOps engineer
**I want to** use AWS Secrets Manager as the backend
**So that** I can leverage existing AWS security infrastructure

**Acceptance Criteria**:
- [ ] Adapter uses AWS SDK
- [ ] Configuration specifies region, credentials (or IAM role)
- [ ] Secret identifiers map to Secrets Manager names
- [ ] Supports secret versioning
- [ ] Handles API throttling gracefully

**Priority**: Medium
**Estimate**: 5 story points

---

#### US-O04: Environment Variable Master Key
**As a** DevOps engineer
**I want to** provide master key via environment variable
**So that** containerized TYPO3 doesn't need persistent file storage for keys

**Acceptance Criteria**:
- [ ] Configuration option `masterKeyProvider: env`
- [ ] Reads from `NR_VAULT_MASTER_KEY` environment variable
- [ ] Key expected as base64-encoded 32 bytes
- [ ] Clear error if variable missing or invalid
- [ ] Works with Docker, Kubernetes, and platform-as-a-service

**Priority**: High
**Estimate**: 2 story points

---

#### US-O05: Master Key Rotation
**As a** DevOps engineer
**I want to** rotate the master key
**So that** I can comply with key rotation policies without re-entering all secrets

**Acceptance Criteria**:
- [ ] `vault:rotate-master-key` CLI command
- [ ] Reads new key from file or stdin
- [ ] Re-encrypts all DEKs with new master key
- [ ] Atomic operation (all-or-nothing)
- [ ] Can verify by decrypting random secret
- [ ] Operation logged with duration and secret count

**Priority**: High
**Estimate**: 3 story points

---

#### US-O06: Secrets Inventory Export
**As a** DevOps engineer
**I want to** export a list of all secret identifiers and metadata
**So that** I can audit what secrets exist and plan migrations

**Acceptance Criteria**:
- [ ] `vault:export-inventory` outputs JSON/YAML
- [ ] Includes: identifier, owner, groups, created, updated, expires
- [ ] Does NOT include secret values
- [ ] Can filter by prefix, group, expiration status
- [ ] Output suitable for documentation/compliance

**Priority**: Low
**Estimate**: 2 story points

---

## 4. Use Case Prioritization

### 4.1 Prioritization Matrix

| Use Case | Frequency | Security Impact | Complexity | Priority Score | Final Priority |
|----------|-----------|-----------------|------------|----------------|----------------|
| Payment Gateway Credentials | High | Critical | Medium | 9.5 | **P1 - Critical** |
| CDN/Storage Credentials | High | Critical | Medium | 9.5 | **P1 - Critical** |
| OAuth Client Secrets | High | Critical | Medium | 9.0 | **P1 - Critical** |
| LDAP/AD Credentials | High | Critical | Low | 9.0 | **P1 - Critical** |
| Email Service Credentials | High | High | Low | 8.0 | **P2 - High** |
| CRM Integration Tokens | Medium | High | Medium | 7.0 | **P2 - High** |
| External Database Credentials | Medium | Critical | Medium | 8.0 | **P2 - High** |
| SMS Gateway Credentials | Medium | High | Low | 6.5 | **P3 - Medium** |
| Newsletter API Keys | Medium | High | Low | 6.0 | **P3 - Medium** |
| Search Service Credentials | Medium | Medium | Low | 5.5 | **P3 - Medium** |
| Social Media API Keys | Medium | Medium | Medium | 5.0 | **P4 - Low** |
| Analytics Tokens | Low | Low | Low | 3.0 | **P4 - Low** |

### 4.2 Scoring Methodology

**Frequency of Need** (1-3 scale)
- 3 = Nearly every TYPO3 project uses this
- 2 = Common in e-commerce or enterprise projects
- 1 = Niche use cases

**Security Impact** (1-5 scale)
- 5 = Critical (financial fraud, data breach, compliance violation)
- 4 = High (service abuse, significant data exposure)
- 3 = Medium (limited data exposure, service disruption)
- 2 = Low (minor exposure, easily revocable)
- 1 = Minimal (informational only)

**Implementation Complexity** (inverse, 1-3 scale)
- 3 = Low complexity (easy to implement, template available)
- 2 = Medium (some custom work needed)
- 1 = High (significant development effort)

**Priority Score** = (Frequency * 1.5) + (Security Impact * 1.5) + (3 - Complexity)

---

### 4.3 Implementation Phases

#### Phase 1: Core Foundation (MVP)
**Target**: Minimum viable secrets management

| Feature | User Stories |
|---------|-------------|
| Encryption engine | US-D01, US-D02 |
| Basic access control | US-A01, US-A05 |
| Audit logging | US-A03 |
| CLI commands | US-O01 |
| TCA integration | US-D03 |

**Duration**: 4-6 weeks
**Outcome**: Usable for payment and LDAP credentials

#### Phase 2: Enterprise Features
**Target**: Compliance and delegation

| Feature | User Stories |
|---------|-------------|
| Secret expiration | US-A04 |
| Secret rotation | US-A02, US-O05 |
| Backend module UI | US-E01, US-E02 |
| Backup/restore | US-A06 |

**Duration**: 3-4 weeks
**Outcome**: Ready for regulated environments

#### Phase 3: External Adapters
**Target**: Enterprise vault integration

| Feature | User Stories |
|---------|-------------|
| HashiCorp Vault adapter | US-O02 |
| AWS Secrets Manager adapter | US-O03 |
| Azure Key Vault adapter | (future) |
| Environment variable key | US-O04 |

**Duration**: 4-5 weeks
**Outcome**: Integration with enterprise secret management

#### Phase 4: Polish and Ecosystem
**Target**: Developer experience

| Feature | User Stories |
|---------|-------------|
| Inventory export | US-O06 |
| Environment contexts | US-D06 |
| Helper methods | US-D04, US-D05 |
| Request integration setup | US-E03 |

**Duration**: 2-3 weeks
**Outcome**: Complete feature set

---

## 5. Implementation Roadmap

### 5.1 Milestones

```
Q1 2025
├── M1: Core Encryption (Week 1-2)
│   ├── Envelope encryption implementation
│   ├── Master key providers (file, env, derived)
│   └── Database schema and repository
│
├── M2: API and CLI (Week 3-4)
│   ├── VaultService facade
│   ├── CLI commands (store, retrieve, rotate, list, delete)
│   └── Basic access control
│
├── M3: TYPO3 Integration (Week 5-6)
│   ├── TCA vaultSecret field type
│   ├── Backend module (list, edit)
│   └── Audit logging
│
├── M4: Enterprise Features (Week 7-9)
│   ├── Secret expiration
│   ├── Master key rotation
│   ├── Backup/restore
│   └── Delegation and group management
│
└── M5: External Adapters (Week 10-12)
    ├── Adapter interface refinement
    ├── HashiCorp Vault adapter
    ├── AWS Secrets Manager adapter
    └── Documentation and examples
```

### 5.2 Success Metrics

| Metric | Target | Measurement |
|--------|--------|-------------|
| Adoption | 50+ installations in 6 months | Packagist downloads |
| Security incidents | 0 from nr-vault vulnerabilities | Issue tracker |
| Developer satisfaction | > 4/5 rating | Survey |
| Documentation coverage | 100% of public API | Code review |
| Test coverage | > 80% | PHPUnit |

### 5.3 Risk Register

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Encryption bugs | Low | Critical | Security audit, well-tested libsodium |
| Performance impact | Medium | Medium | Request-scoped caching, benchmarks |
| Migration complexity | Medium | Medium | Clear migration guides, CLI tools |
| External vault latency | Medium | Low | Caching strategy, timeout configuration |
| TYPO3 version incompatibility | Low | Medium | CI matrix testing v12.4, v13.x |

---

## Appendix A: Compliance Quick Reference

### PCI-DSS Requirements Addressed

| Requirement | Description | nr-vault Feature |
|-------------|-------------|------------------|
| 3.4 | Render PAN unreadable | AES-256-GCM encryption |
| 3.5 | Protect encryption keys | Master key outside database |
| 3.6 | Document key management | Key rotation CLI and audit |
| 8.2.1 | Strong authentication | BE user group access control |
| 10.2 | Audit trails | Complete access logging |

### GDPR Requirements Addressed

| Article | Requirement | nr-vault Feature |
|---------|-------------|------------------|
| Art. 32 | Security of processing | Encryption at rest |
| Art. 30 | Records of processing | Audit log |
| Art. 5(1)(f) | Integrity and confidentiality | AEAD encryption |

---

## Appendix B: Current State Analysis

### Secrets Found in Typical TYPO3 Projects

Based on analysis of common extension configurations:

| Location | Typical Secrets | Security Status |
|----------|-----------------|-----------------|
| `LocalConfiguration.php` | SMTP, LDAP, encryption key | Plaintext, often in git |
| `AdditionalConfiguration.php` | Environment overrides | Slightly better, still plaintext |
| Extension settings | API keys, tokens | Plaintext in database |
| TCA password fields | User passwords | Hashed (correct) or plaintext (wrong) |
| Scheduler task settings | Integration credentials | Plaintext in database |

### Migration Path

Extensions to target for nr-vault integration examples:

1. **cart** (payment gateways)
2. **form_mailchimp** (newsletter)
3. **ig_ldap_sso_auth** (LDAP)
4. **aws_fal** (S3 storage)
5. **solr** (search)

---

## Appendix C: Glossary

| Term | Definition |
|------|------------|
| **DEK** | Data Encryption Key - unique per-secret key that encrypts the actual value |
| **Envelope Encryption** | Pattern where secrets are encrypted with DEKs, and DEKs are encrypted with master key |
| **Master Key** | Root key that encrypts all DEKs; stored outside database |
| **Secret** | Any sensitive credential: API key, password, token, certificate |
| **Vault** | Central storage for secrets with encryption and access control |
| **HSM** | Hardware Security Module - specialized hardware for key protection |
| **AEAD** | Authenticated Encryption with Associated Data - provides both encryption and integrity |
| **GCM** | Galois/Counter Mode - AEAD mode used in AES-256-GCM |
