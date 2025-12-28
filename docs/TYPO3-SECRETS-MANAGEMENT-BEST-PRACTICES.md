# TYPO3 Secrets Management Best Practices

A comprehensive guide to storing and managing user-manageable secrets in TYPO3.

## Table of Contents

1. [Overview](#overview)
2. [How TYPO3 Core Handles Sensitive Configuration](#how-typo3-core-handles-sensitive-configuration)
3. [Configuration File Hierarchy](#configuration-file-hierarchy)
4. [Environment Variable Usage](#environment-variable-usage)
5. [Built-in Security Features](#built-in-security-features)
6. [Extension Configuration Patterns](#extension-configuration-patterns)
7. [Security Gaps and Risks](#security-gaps-and-risks)
8. [Recommendations](#recommendations)

---

## Overview

TYPO3 does not have a native, built-in secrets management system or vault. Instead, it relies on a combination of configuration files, environment variables, and third-party packages to handle sensitive data. This document analyzes current practices and identifies areas for improvement.

### Key Findings Summary

| Aspect | TYPO3 Approach | Security Level |
|--------|----------------|----------------|
| Encryption Key | Stored in LocalConfiguration.php | Medium |
| Install Tool Password | Hashed (Argon2id) | High |
| Database Credentials | Plaintext in config files | Low |
| Extension API Keys | Typically plaintext in Extension Manager | Low |
| Site Configuration Secrets | Environment variable placeholders supported | Medium |

---

## How TYPO3 Core Handles Sensitive Configuration

### Encryption Key

The encryption key is a critical security component stored in `$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']`.

**Purpose:**
- Salt for various encryption operations
- Checksums and validations (e.g., cHash)
- HMAC generation for security tokens
- Session cookie validation (JWT HS256)

**Location:** `config/system/settings.php` (or `typo3conf/LocalConfiguration.php` in classic mode)

**Format:** Typically a 96-character hexadecimal hash

**Security Advisory (2024):** [TYPO3-CORE-SA-2024-004](https://typo3.org/security/advisory/typo3-core-sa-2024-004) disclosed that the plaintext encryption key was visible in Install Tool editing forms. This has been fixed.

```php
// Example: Encryption key in settings.php
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'a1b2c3d4e5f6...'; // 96 chars
```

**Risk:** Stored in plaintext. If the configuration file is exposed (backup leak, misconfigured server), the key is compromised.

### Install Tool Password

**Storage:** `$GLOBALS['TYPO3_CONF_VARS']['BE']['installToolPassword']`

**Hashing:** Uses TYPO3's password hashing system (Argon2id by default)

**Security:** High - cannot be reversed from the hash

```php
// Example: Hashed install tool password
$GLOBALS['TYPO3_CONF_VARS']['BE']['installToolPassword'] = '$argon2id$v=19$m=65536,t=16,p=2$...';
```

### Database Credentials

**Location:** `$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']`

**Storage:** Plaintext in configuration files

```php
// Default database configuration - INSECURE
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default'] = [
    'host' => 'localhost',
    'dbname' => 'typo3',
    'user' => 'typo3_user',
    'password' => 'super_secret_password', // Plaintext!
];
```

---

## Configuration File Hierarchy

### File Priority (Highest to Lowest)

1. **`config/system/additional.php`** - Always takes precedence
2. **`config/system/settings.php`** - GUI-managed settings
3. **Environment variables** - When properly configured

### Classic Mode Paths

| Composer Mode | Classic Mode |
|---------------|--------------|
| `config/system/settings.php` | `typo3conf/LocalConfiguration.php` |
| `config/system/additional.php` | `typo3conf/AdditionalConfiguration.php` |
| `config/sites/<site>/config.yaml` | `typo3conf/sites/<site>/config.yaml` |

### Recommended Pattern: additional.php for Secrets

```php
<?php
// config/system/additional.php

// Load environment variables (requires symfony/dotenv or vlucas/phpdotenv)
use Symfony\Component\Dotenv\Dotenv;
use TYPO3\CMS\Core\Core\Environment;

$dotenv = new Dotenv();
$dotenv->load(Environment::getProjectPath() . '/.env');

// Database credentials from environment
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['host']
    = $_ENV['TYPO3_DB_HOST'];
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['dbname']
    = $_ENV['TYPO3_DB_NAME'];
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['user']
    = $_ENV['TYPO3_DB_USER'];
$GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password']
    = $_ENV['TYPO3_DB_PASSWORD'];

// Encryption key from environment
$GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']
    = $_ENV['TYPO3_ENCRYPTION_KEY'];

// Mail configuration
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server']
    = $_ENV['SMTP_HOST'] . ':' . $_ENV['SMTP_PORT'];
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_username']
    = $_ENV['SMTP_USER'];
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_password']
    = $_ENV['SMTP_PASSWORD'];
```

---

## Environment Variable Usage

### Native Support in Site Configuration

TYPO3 supports environment variable placeholders in site configuration YAML files using the `%env(VAR_NAME)%` syntax.

**Source:** [TYPO3 Documentation - Using Environment Variables](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/SiteHandling/UsingEnvVars.html)

```yaml
# config/sites/my-site/config.yaml
base: 'https://%env(SITE_BASE_URL)%/'
errorHandling:
  -
    errorCode: 404
    errorHandler: Page
    errorContentSource: 't3://page?uid=%env(ERROR_404_PAGE)%'
```

### Important Limitation

> **TYPO3 does not provide a loader for .env files** - you must handle this yourself.

### .env File Loading Options

#### Option 1: symfony/dotenv

```bash
composer require symfony/dotenv
```

```php
// config/system/additional.php
use Symfony\Component\Dotenv\Dotenv;
use TYPO3\CMS\Core\Core\Environment;

$dotenv = new Dotenv();
$dotenv->load(Environment::getProjectPath() . '/.env');
```

#### Option 2: vlucas/phpdotenv

```bash
composer require vlucas/phpdotenv
```

```php
// config/system/additional.php
use Dotenv\Dotenv;
use TYPO3\CMS\Core\Core\Environment;

$dotenv = Dotenv::createUnsafeImmutable(Environment::getProjectPath());
$dotenv->load();
```

#### Option 3: helhum/dotenv-connector (Recommended)

```bash
composer require helhum/dotenv-connector
```

This package loads `.env` during Composer autoload, making variables available immediately without manual loading code.

**Source:** [Liquid Light Blog - Using .env files with TYPO3](https://www.liquidlight.co.uk/blog/use-env-files-and-variables-to-keep-sensitive-information-out-of-your-typo3-project/)

### Example .env File

```env
# .env (DO NOT COMMIT TO VERSION CONTROL)
TYPO3_CONTEXT="Production"

# Database
TYPO3_DB_HOST="localhost"
TYPO3_DB_NAME="typo3_production"
TYPO3_DB_USER="typo3_app"
TYPO3_DB_PASSWORD="secure_random_password_here"

# Security
TYPO3_ENCRYPTION_KEY="96_character_hex_string_here"

# Mail
SMTP_HOST="smtp.example.com"
SMTP_PORT="587"
SMTP_USER="notifications@example.com"
SMTP_PASSWORD="smtp_password_here"

# API Keys
GOOGLE_MAPS_API_KEY="AIza..."
PAYMENT_API_KEY="sk_live_..."
```

### .env.example Pattern

```env
# .env.example (SAFE TO COMMIT)
TYPO3_CONTEXT="Development"

# Database
TYPO3_DB_HOST="localhost"
TYPO3_DB_NAME=""
TYPO3_DB_USER=""
TYPO3_DB_PASSWORD=""

# Security
TYPO3_ENCRYPTION_KEY=""

# Mail
SMTP_HOST=""
SMTP_PORT="587"
SMTP_USER=""
SMTP_PASSWORD=""
```

---

## Built-in Security Features

### Password Hashing

**Source:** [TYPO3 Documentation - Password Hashing](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/PasswordHashing/Index.html)

TYPO3 uses modern password hashing with **Argon2id** as the default (since TYPO3 13.0).

#### Supported Algorithms

| Algorithm | Availability | Security Level | Notes |
|-----------|--------------|----------------|-------|
| Argon2id | PHP 7.3+ | Highest | Default in TYPO3 13+ |
| Argon2i | PHP 7.2+ | Very High | Previous default |
| bcrypt | All PHP | High | Fallback option |
| PBKDF2 | All PHP | High | FIPS-compliant |
| phpass | All PHP | Medium | Legacy support |

#### Argon2id Configuration Options

```php
$GLOBALS['TYPO3_CONF_VARS']['BE']['passwordHashing'] = [
    'className' => \TYPO3\CMS\Core\Crypto\PasswordHashing\Argon2idPasswordHash::class,
    'options' => [
        'memory_cost' => 65536,  // kibibytes (64 MB)
        'time_cost' => 16,       // iterations
        'threads' => 2,          // parallel threads
    ],
];
```

#### Using Password Hashing in Extensions

```php
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// Hash a password
$hashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
$hashInstance = $hashFactory->getDefaultHashInstance('FE');
$hashedPassword = $hashInstance->getHashedPassword($clearTextPassword);

// Verify a password
$storedHash = '...'; // From database
$isValid = $hashFactory->get($storedHash, 'FE')
    ->checkPassword($clearTextPassword, $storedHash);
```

### HashService for HMAC

**Source:** [TYPO3 Core Changelog - Feature 102761](https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog/13.0/Feature-102761-IntroduceClassToGeneratevalidateHMACHashes.html)

TYPO3 13.0 introduced `\TYPO3\CMS\Core\Crypto\HashService` for secure HMAC generation.

```php
use TYPO3\CMS\Core\Crypto\HashService;

// Inject via DI or create instance
$hashService = GeneralUtility::makeInstance(HashService::class);

// Generate HMAC with mandatory secret
$hmac = $hashService->generateHmac($data, $additionalSecret);

// Validate HMAC
$isValid = $hashService->validateHmac($data, $hmac, $additionalSecret);

// Append HMAC to string
$stringWithHmac = $hashService->appendHmac($data, $additionalSecret);

// Validate and strip HMAC
$originalData = $hashService->validateAndStripHmac($stringWithHmac, $additionalSecret);
```

**Security Note:** The mandatory `$additionalSecret` parameter prevents HMAC reuse attacks across different contexts.

### No Native Encryption Service

TYPO3 Core does **not** provide a general-purpose encryption service for storing encrypted data. The encryption key is used for:
- HMAC generation
- Session tokens
- cHash validation

It is **not** designed for encrypting arbitrary data at rest.

---

## Extension Configuration Patterns

### Common Approaches by Extension Type

#### Payment Extensions (PayPal, Stripe)

**Typical Pattern:** Store API keys in Extension Manager configuration or TypoScript constants.

**Example from Aimeos/PayPal integration:**
```typoscript
# TypoScript Constants (often plaintext)
plugin.tx_aimeos {
    settings {
        paypalexpress.AccountEmail = merchant@example.com
        paypalexpress.ApiUsername = api_username
        paypalexpress.ApiPassword = api_password
        paypalexpress.ApiSignature = signature
    }
}
```

**Better Pattern with Environment Variables:**
```typoscript
plugin.tx_aimeos {
    settings {
        paypalexpress.ApiUsername = {$env.PAYPAL_API_USERNAME}
        paypalexpress.ApiPassword = {$env.PAYPAL_API_PASSWORD}
    }
}
```

**Source:** [TYPO3 Payment Extensions](https://extensions.typo3.org/extension/transactor)

#### Newsletter Extensions (Mailchimp, Sendinblue/Brevo)

**Mailchimp Integration:**
```php
// tev_mailchimp stores API key in Extension Manager
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['tev_mailchimp']['apiKey'] = '...';
```

**Symfony Mailer DSN Pattern (Recommended):**
```php
// LocalConfiguration.php or via environment
$GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
    'transport' => 'dsn',
    'dsn' => 'ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1',
];

// Better: Use environment variable
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['dsn'] = $_ENV['MAILER_DSN'];
```

**Source:** [TYPO3 Emails with AWS SES and Others](https://markus-code.com/2021/03/typo3-emails-with-aws-ses-mailchimp-and-more/)

#### OAuth Extensions

**waldhacker/typo3-oauth2-client:**
- Stores OAuth provider configurations in database
- RSA keypairs required for OAuth2 Server extensions

**Key Security Requirement:**
> "For a fully working setup a RSA keypair needs to be generated... This keypair must be stored safely which means outside of the TYPO3 web directory and should be readonly."

**Source:** [TYPO3 OAuth2 Client Configuration](https://docs.typo3.org/p/waldhacker/typo3-oauth2-client/main/en-us/Configuration/Index.html)

### Third-Party Encryption: nnhelpers Extension

**Source:** [nnhelpers Encrypt Documentation](https://docs.typo3.org/p/nng/nnhelpers/main/en-us/Helpers/Classes/encrypt.html)

The `nng/nnhelpers` extension provides reversible encryption:

```php
use \nn\t3;

// Encrypt data
$encrypted = \nn\t3::Encrypt()->encode(['api_key' => 'secret123']);

// Decrypt data
$decrypted = \nn\t3::Encrypt()->decode($encrypted);

// NOT suitable for passwords - use this instead:
$hashedPassword = \nn\t3::Encrypt()->password($clearText);
$isValid = \nn\t3::Encrypt()->checkPassword($input, $hashedPassword);
```

**Warning:** The extension documentation explicitly states:
> "This method is not suitable for storing sensitive data such as passwords in a database."

### Advanced: helhum/typo3-config-handling

**Source:** [GitHub - typo3-config-handling](https://github.com/helhum/typo3-config-handling)

This package provides encrypted configuration values:

```yaml
# config/settings.yaml
DB:
  Connections:
    Default:
      password: '%decrypt(encrypted_base64_string)%'
```

**Encryption Process:**
```bash
# Encrypt values in configuration file
vendor/bin/typo3cms settings:encrypt -c config/live.yaml
```

**Requirements:**
- `defuse/php-encryption` package
- Encryption key stored separately (e.g., environment variable)
- Custom decryption processor registration

---

## Security Gaps and Risks

### 1. Plaintext Storage in Configuration Files

**Risk Level: HIGH**

| Data Type | Default Storage | Risk |
|-----------|-----------------|------|
| Database password | Plaintext in settings.php | Critical |
| Encryption key | Plaintext in settings.php | Critical |
| SMTP credentials | Plaintext in settings.php | High |
| API keys | Plaintext in Extension Manager | High |

**Mitigation:** Use environment variables loaded from `.env` files outside version control.

### 2. Backup Exposure

**Risk Level: HIGH**

**Source:** [TYPO3 Secure Backup Strategy](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Production/Backups/Index.html)

> "Never store backup files inside the web server's document root. If backups are accessible via a browser, they pose a serious security risk."

**Common Exposure Vectors:**
- `.sql` dumps in web root
- `.tar.gz` archives accessible via URL
- Configuration file backups (`.bak`, `.old`)

**Mitigation:**
- Store backups outside document root
- Encrypt backups containing sensitive data
- Configure web server to deny access to backup extensions

```apache
# Apache: Deny access to sensitive files
<FilesMatch "\.(bak|sql|log|old|tar|gz|zip|env)$">
    Require all denied
</FilesMatch>
```

### 3. Database Exposure

**Risk Level: MEDIUM-HIGH**

**Sensitive Data Often Stored in Plaintext:**
- Extension configuration with API keys
- OAuth tokens
- Third-party service credentials

**Source:** [TYPO3 Security Guidelines](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Security/Index.html)

### 4. Log Exposure

**Risk Level: MEDIUM**

**Source:** [TYPO3 Logging Considerations](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Production/Logging/Index.html)

**Known Issue:** Install tool password was logged as plaintext when hashing mechanism was incorrect.

**Risks:**
- IP addresses in logs (GDPR concern)
- Debug output may contain credentials
- Log files in publicly accessible directories

**Mitigation:**
```php
// Production logging configuration
$GLOBALS['TYPO3_CONF_VARS']['LOG']['writerConfiguration'] = [
    \Psr\Log\LogLevel::ERROR => [
        \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
            'logFile' => Environment::getVarPath() . '/log/error.log',
        ],
    ],
];
```

### 5. Version Control Exposure

**Risk Level: HIGH**

**Common Mistakes:**
- Committing `LocalConfiguration.php` with credentials
- Committing `.env` files
- Credentials in TypoScript files

**Mitigation:** Proper `.gitignore`:
```gitignore
# .gitignore
.env
config/system/settings.php
config/system/additional.php
*.local.php
*.local.yaml
```

### 6. Extension Manager Plaintext Storage

**Risk Level: MEDIUM**

Extension configurations are stored in:
```php
$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extension_key']
```

These are written to `settings.php` in plaintext.

---

## Recommendations

### Immediate Actions

1. **Move all credentials to environment variables**
   ```php
   // additional.php
   $GLOBALS['TYPO3_CONF_VARS']['DB']['Connections']['Default']['password']
       = $_ENV['TYPO3_DB_PASSWORD'];
   ```

2. **Use .env files with proper loader**
   ```bash
   composer require helhum/dotenv-connector
   ```

3. **Exclude sensitive files from version control**
   ```gitignore
   .env
   config/system/settings.php
   ```

4. **Set proper file permissions**
   ```bash
   chmod 600 config/system/settings.php
   chmod 600 config/system/additional.php
   chmod 600 .env
   ```

### For Extension Developers

1. **Never store secrets in Extension Manager configuration**

2. **Support environment variable placeholders**
   ```php
   $apiKey = $_ENV['MY_EXTENSION_API_KEY']
       ?? $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['my_ext']['apiKey']
       ?? '';
   ```

3. **Use TYPO3's password hashing for credentials**
   ```php
   $hashFactory = GeneralUtility::makeInstance(PasswordHashFactory::class);
   $hashedValue = $hashFactory->getDefaultHashInstance('FE')
       ->getHashedPassword($sensitiveData);
   ```

4. **Implement encryption for sensitive stored data**
   ```php
   // Consider using defuse/php-encryption
   use Defuse\Crypto\Crypto;
   use Defuse\Crypto\Key;

   $key = Key::loadFromAsciiSafeString($_ENV['APP_ENCRYPTION_KEY']);
   $encrypted = Crypto::encrypt($plaintext, $key);
   $decrypted = Crypto::decrypt($encrypted, $key);
   ```

### Production Deployment Checklist

- [ ] Environment set to `Production`
- [ ] Debug mode disabled
- [ ] HTTPS enforced with HSTS
- [ ] Encryption key set via environment variable
- [ ] Database credentials via environment variable
- [ ] Install tool disabled or password-protected
- [ ] Backups encrypted and stored outside web root
- [ ] Log files not web-accessible
- [ ] File permissions correctly set (644 files, 755 directories, 600 config)

### Future Considerations

**What TYPO3 Could Improve:**

1. **Native .env loader** - Currently requires third-party packages
2. **Built-in secrets management API** - Similar to Symfony Secrets
3. **Encrypted extension configuration** - Store Extension Manager settings encrypted
4. **Database field encryption** - Native support for encrypted columns
5. **Secrets rotation support** - Tooling for key rotation without downtime

---

## References

### Official TYPO3 Documentation
- [Environment Variables in Site Configuration](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/SiteHandling/UsingEnvVars.html)
- [Password Hashing](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/PasswordHashing/Index.html)
- [Security Guidelines](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Security/Index.html)
- [Configuring Environments](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Deployment/EnvironmentStages/Configuration/Index.html)
- [Secure Backup Strategy](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Production/Backups/Index.html)
- [Logging Considerations](https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Production/Logging/Index.html)

### Third-Party Packages
- [helhum/dotenv-connector](https://github.com/helhum/dotenv-connector)
- [helhum/typo3-config-handling](https://github.com/helhum/typo3-config-handling)
- [symfony/dotenv](https://github.com/symfony/dotenv)
- [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv)
- [defuse/php-encryption](https://github.com/defuse/php-encryption)

### Security Advisories
- [TYPO3-CORE-SA-2024-004: Encryption Key Disclosure](https://typo3.org/security/advisory/typo3-core-sa-2024-004)

### Community Resources
- [Liquid Light - Using .env Files with TYPO3](https://www.liquidlight.co.uk/blog/use-env-files-and-variables-to-keep-sensitive-information-out-of-your-typo3-project/)
- [TYPO3 Talk - Improved Configuration for Environments](https://talk.typo3.org/t/improved-typo3-configuration-for-environments/5228)

---

*Document Version: 1.0*
*Last Updated: December 2024*
*Research Methodology: Official TYPO3 documentation, security advisories, extension analysis, and community best practices*
