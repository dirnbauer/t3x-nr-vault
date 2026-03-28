.. include:: /Includes.rst.txt

.. _security:

========
Security
========

.. _security-encryption-architecture:

Encryption architecture
=======================

nr-vault uses envelope encryption, an industry-standard pattern for
protecting sensitive data.

.. code-block:: text
   :caption: Envelope encryption: Each secret has its own DEK encrypted by the master key.

   +-------------------+
   |    Master Key     |
   +--------+----------+
            |
            | encrypts
            |
      +-----+------+--------+
      |            |         |
      v            v         v
   +------+    +------+   +------+
   | DEK1 |    | DEK2 |   | DEK3 |
   +--+---+    +--+---+   +--+---+
      |           |          |
      | encrypts  | encrypts | encrypts
      v           v          v
   +------+    +------+   +------+
   |Value1|    |Value2|   |Value3|
   +------+    +------+   +------+

   Secret 1    Secret 2   Secret 3

.. _security-how-it-works:

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

.. _security-benefits:

Benefits
--------

-  **Key rotation**: Rotating the master key only requires re-encrypting DEKs,
   not the actual secret values.
-  **Blast radius**: If a DEK is compromised, only one secret is affected.
-  **Performance**: Bulk operations on secrets don't require the master key
   for each operation.

.. _security-algorithms:

Algorithms
==========

AES-256-GCM (default)
   Advanced Encryption Standard with 256-bit keys in Galois/Counter Mode.
   Provides authenticated encryption with hardware acceleration on modern CPUs.

XChaCha20-Poly1305 (optional)
   ChaCha20 stream cipher with extended nonce and Poly1305 MAC.
   Recommended when hardware AES is not available.

Both algorithms provide:

-  256-bit key strength.
-  Authenticated encryption (AEAD).
-  Protection against tampering.

.. _security-master-key:

Master key security
===================

The master key is the root of trust for all secrets.

.. _security-master-key-providers:

Provider security comparison
----------------------------

**TYPO3 provider** (default, recommended for most users)
   Security depends on TYPO3's encryption key protection. Suitable for
   environments where the encryption key is properly secured in :file:`settings.php`.
   No additional configuration required.

**File provider** (recommended for high-security environments)
   Allows storing the key outside the database and web root with strict
   permissions. Requires server access to configure.

**Environment provider** (recommended for containers)
   Ideal for containerized deployments where secrets are injected at runtime.
   Follows 12-factor app methodology.

.. _security-file-storage:

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

.. _security-audit-logging:

Audit logging
=============

All secret operations are logged with:

-  Timestamp.
-  Action (create, read, update, delete).
-  Actor (user ID, username, type).
-  Secret identifier.
-  IP address.
-  Result (success/failure).

.. _security-hash-chain:

Hash chain integrity
--------------------

Audit log entries form a hash chain where each entry includes a hash of
the previous entry. This provides:

-  **Tamper detection**: Any modification to log entries breaks the chain.
-  **Completeness**: Deleted entries are detectable.
-  **Non-repudiation**: Actions cannot be denied after logging.

.. _security-access-control:

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

.. _security-best-practices:

Security best practices
=======================

1. **Regular key rotation**: Rotate the master key annually or after
   security incidents.

2. **Audit log review**: Regularly review audit logs for suspicious access.

3. **Minimal permissions**: Grant access only to users who need it.

4. **Secret rotation**: Rotate secrets when personnel changes occur.

5. **Monitoring**: Set up alerts for access_denied events.

6. **Backup security**: Encrypt backups and store them securely.

.. _security-reporting-vulnerabilities:

Reporting vulnerabilities
=========================

If you discover a security vulnerability, please report it responsibly:

**DO NOT** create a public GitHub issue.

Use GitHub's private security reporting feature:
`Report a vulnerability <https://github.com/netresearch/t3x-nr-vault/security/advisories/new>`__

See :file:`SECURITY.md` for the full security policy.
