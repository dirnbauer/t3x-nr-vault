.. include:: /Includes.rst.txt

============
Introduction
============

What is nr-vault?
=================

nr-vault is a TYPO3 extension that provides secure secrets management with
enterprise-grade encryption and access control. It allows you to store
sensitive data like API keys, database credentials, and other secrets
securely within your TYPO3 installation.

Features
========

Envelope encryption
   Industry-standard envelope encryption using AES-256-GCM or XChaCha20-Poly1305.
   Each secret gets its own Data Encryption Key (DEK), which is encrypted with
   a Master Key.

Access control
   Fine-grained access control based on TYPO3 backend user groups. Secrets can
   be owned by users and shared with specific groups.

Audit logging
   Tamper-evident audit logging with hash chain verification. Every access to
   secrets is logged for compliance and security monitoring.

Context-based organization
   Organize secrets by context (e.g., "payment", "email", "api") for easier
   management and scoped access.

CLI integration
   Command-line tools for secret management, key rotation, and administrative
   tasks.

Backend module
   User-friendly backend module for managing secrets through the TYPO3 interface.

Use cases
=========

-  Storing payment gateway API keys
-  Managing email service credentials
-  Securing third-party API tokens
-  Protecting database connection strings
-  Storing OAuth client secrets
-  Managing encryption keys for other systems

Requirements
============

-  TYPO3 v14.0 or higher
-  PHP 8.5 or higher
-  PHP sodium extension
-  Composer-based TYPO3 installation
