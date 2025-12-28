.. include:: /Includes.rst.txt

========
Security
========

Encryption architecture
=======================

nr-vault uses envelope encryption, an industry-standard pattern for
protecting sensitive data.

.. uml::
   :caption: Envelope encryption: Each secret has its own DEK encrypted by the master key.

   skinparam backgroundColor white
   skinparam shadowing false
   skinparam componentStyle rectangle

   rectangle "Master Key" as MK #2F99A4
   rectangle "Secret 1" as S1 {
      rectangle "DEK₁ (encrypted)" as DEK1 #CCCDCC
      rectangle "Value (encrypted)" as V1 #F5F5F5
   }
   rectangle "Secret 2" as S2 {
      rectangle "DEK₂ (encrypted)" as DEK2 #CCCDCC
      rectangle "Value (encrypted)" as V2 #F5F5F5
   }
   rectangle "Secret 3" as S3 {
      rectangle "DEK₃ (encrypted)" as DEK3 #CCCDCC
      rectangle "Value (encrypted)" as V3 #F5F5F5
   }

   MK --> DEK1 : encrypts
   MK --> DEK2 : encrypts
   MK --> DEK3 : encrypts
   DEK1 --> V1 : encrypts
   DEK2 --> V2 : encrypts
   DEK3 --> V3 : encrypts

How it works
------------

1. **Data Encryption Key (DEK)**: Each secret gets a unique 256-bit key
   generated using cryptographically secure random bytes.

2. **Value encryption**: The secret value is encrypted with its DEK using
   AES-256-GCM (or XChaCha20-Poly1305).

3. **DEK encryption**: The DEK is encrypted with the Master Key and stored
   alongside the encrypted value.

4. **Decryption**: To read a secret, first decrypt the DEK with the Master Key,
   then use the DEK to decrypt the value.

Benefits
--------

-  **Key rotation**: Rotating the master key only requires re-encrypting DEKs,
   not the actual secret values.
-  **Blast radius**: If a DEK is compromised, only one secret is affected.
-  **Performance**: Bulk operations on secrets don't require the master key
   for each operation.

Algorithms
==========

AES-256-GCM (default)
   Advanced Encryption Standard with 256-bit keys in Galois/Counter Mode.
   Provides authenticated encryption with hardware acceleration on modern CPUs.

XChaCha20-Poly1305 (optional)
   ChaCha20 stream cipher with extended nonce and Poly1305 MAC.
   Recommended when hardware AES is not available.

Both algorithms provide:

-  256-bit key strength
-  Authenticated encryption (AEAD)
-  Protection against tampering

Master key security
===================

The master key is the root of trust for all secrets.

Provider security comparison
----------------------------

**TYPO3 provider** (default, recommended for most users)
   Security depends on TYPO3's encryption key protection. Suitable for
   environments where the encryption key is properly secured in settings.php.
   No additional configuration required.

**File provider** (recommended for high-security environments)
   Allows storing the key outside the database and web root with strict
   permissions. Requires server access to configure.

**Environment provider** (recommended for containers)
   Ideal for containerized deployments where secrets are injected at runtime.
   Follows 12-factor app methodology.

**Derived provider** (for air-gapped systems)
   Key is derived from a passphrase using Argon2id. Useful when no persistent
   key storage is available.

File storage recommendations
----------------------------

When using the file provider:

1. **Outside web root**: Never store in publicly accessible directories.

2. **Restrictive permissions**: Use 0400 (read-only by owner).

3. **Separate backup**: Back up the master key separately from the database.

4. **Access logging**: Monitor access to the key file.

5. **Key rotation**: Rotate the master key periodically.

.. warning::

   If the master key is compromised, all secrets must be considered compromised.
   Rotate the master key and all secrets immediately.

Audit logging
=============

All secret operations are logged with:

-  Timestamp
-  Action (create, read, update, delete)
-  Actor (user ID, username, type)
-  Secret identifier
-  IP address
-  Result (success/failure)

Hash chain integrity
--------------------

Audit log entries form a hash chain where each entry includes a hash of
the previous entry. This provides:

-  **Tamper detection**: Any modification to log entries breaks the chain.
-  **Completeness**: Deleted entries are detectable.
-  **Non-repudiation**: Actions cannot be denied after logging.

Access control
==============

Secret access is controlled at multiple levels:

1. **Authentication**: Backend user must be logged in.
2. **Ownership**: Creator has full access.
3. **Group membership**: Shared access via backend groups.
4. **Admin override**: Administrators can access all secrets.

.. note::

   CLI access requires explicit configuration and can be restricted
   to specific groups.

Security best practices
=======================

1. **Regular key rotation**: Rotate the master key annually or after
   security incidents.

2. **Audit log review**: Regularly review audit logs for suspicious access.

3. **Minimal permissions**: Grant access only to users who need it.

4. **Secret rotation**: Rotate secrets when personnel changes occur.

5. **Monitoring**: Set up alerts for access_denied events.

6. **Backup security**: Encrypt backups and store them securely.

Reporting vulnerabilities
=========================

If you discover a security vulnerability, please report it responsibly:

**DO NOT** create a public GitHub issue.

Use GitHub's private security reporting feature:
`Report a vulnerability <https://github.com/netresearch/t3x-nr-vault/security/advisories/new>`__

See :file:`SECURITY.md` for the full security policy.
