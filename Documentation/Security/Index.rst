.. include:: /Includes.rst.txt

========
Security
========

Encryption architecture
=======================

nr-vault uses envelope encryption, an industry-standard pattern for
protecting sensitive data.

.. figure:: /Images/envelope-encryption.png
   :alt: Envelope encryption diagram
   :class: with-shadow

   Envelope encryption: Each secret has its own DEK encrypted by the master key.

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

The master key is the root of trust for all secrets. Protect it carefully:

Storage recommendations
-----------------------

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

Email security concerns to: **security@netresearch.de**

See :file:`SECURITY.md` for the full security policy.
