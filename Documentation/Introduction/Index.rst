.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

.. _introduction-what-is-nr-vault:

What is nr-vault?
=================

nr-vault is a TYPO3 extension that provides secure secrets management with
enterprise-grade encryption and access control. It allows you to store
sensitive data like API keys, database credentials, and other secrets
securely within your TYPO3 installation.

Unlike storing secrets in plaintext configuration files or database fields,
nr-vault uses envelope encryption (the same pattern used by AWS KMS and
Google Cloud KMS) to ensure that even if your database is compromised,
secrets remain protected.

.. _introduction-problem:

The problem
===========

TYPO3 lacks a proper secrets management solution. Current approaches are inadequate:

.. list-table::
   :header-rows: 1
   :widths: 30 70

   * - Approach
     - Problem
   * - TCA :php:`type=password`
     - Hashes by default (irreversible) or stores plaintext.
   * - Extension configuration
     - Stored in :file:`LocalConfiguration.php` (often in git).
   * - Environment variables
     - Not suitable for multi-user, runtime-configurable secrets.
   * - Database plaintext
     - No encryption, exposed in backups, SQL injection risk.

Every extension that needs to store API keys reinvents this wheel, often insecurely.

.. _introduction-features:

Features
========

.. _introduction-features-encryption:

Core encryption
---------------

Envelope encryption
   Industry-standard envelope encryption using AES-256-GCM or XChaCha20-Poly1305.
   Each secret gets its own Data Encryption Key (DEK), which is encrypted with
   a Master Key. This means master key rotation only requires re-encrypting DEKs,
   not all secret values.

Master key management
   Flexible master key sources: derive from TYPO3's encryption key (default),
   read from a secure file, or inject via environment variable.

Access control
   Fine-grained access control based on TYPO3 backend user groups. Secrets can
   be owned by users and shared with specific groups.

Audit logging
   Tamper-evident audit logging with hash chain verification. Every access to
   secrets is logged for compliance and security monitoring.

Secret versioning
   Secrets maintain a version number that increments on each rotation,
   enabling tracking of secret lifecycle.

Secret expiration
   Secrets can be configured to expire at a specific time, after which they
   become inaccessible.

.. _introduction-features-integration:

Integration features
--------------------

TCA field type
   Custom :typoscript:`vaultSecret` TCA render type that allows any TYPO3 extension
   to store sensitive data securely. Values are encrypted and stored in the
   vault; only identifiers are kept in your database tables.

Site configuration support
   Reference secrets in :file:`config/sites/*/config.yaml` using the
   :yaml:`%vault(identifier)%` syntax. Secrets are resolved at runtime.

TypoScript integration
   Use :typoscript:`%vault(identifier)%` in TypoScript values. Requires the secret
   to be marked as frontend-accessible.

Vault HTTP Client
   Make authenticated API calls without exposing secrets to your code.
   The client injects authentication (Bearer tokens, API keys, Basic Auth,
   OAuth 2.0) directly from the vault.

CLI integration
   Command-line tools for secret management, key rotation, scanning for
   plaintext secrets, and administrative tasks.

Backend module
   User-friendly backend module for managing secrets through the TYPO3 interface.
   Access via :guilabel:`Admin Tools > Vault`.

Context-based organization
   Organize secrets by context (e.g., "payment", "email", "api") for easier
   management and scoped access.

.. _introduction-use-cases:

Use cases
=========

-  Storing payment gateway API keys (Stripe, PayPal, etc.).
-  Managing email service credentials (Mailchimp, SendGrid).
-  Securing third-party API tokens.
-  Protecting database connection strings for external systems.
-  Storing OAuth client secrets with automatic token refresh.
-  Managing encryption keys for other systems.
-  Per-record credentials in TCA (e.g., per-client API keys).
-  Site-specific configuration secrets in multi-site installations.

.. _introduction-requirements:

Requirements
============

-  TYPO3 v14.0 or higher.
-  PHP 8.5 or higher.
-  PHP sodium extension (included in PHP 8.5).
-  Composer-based TYPO3 installation.
-  AES-NI CPU support recommended (XChaCha20-Poly1305 fallback available).
