.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

Extension configuration
=======================

Configure nr-vault in :guilabel:`Admin Tools > Settings > Extension Configuration`.

.. confval:: adapter

   :type: string
   :Default: local
   :Options: local, hashicorp, aws

   Storage adapter for secrets. Use "local" for database storage,
   or configure external providers like HashiCorp Vault or AWS Secrets Manager.

.. confval:: masterKeyProvider

   :type: string
   :Default: file
   :Options: file, env, derived

   How to retrieve the master encryption key.

   file
      Read from a file on the filesystem.

   env
      Read from an environment variable.

   derived
      Derive from a passphrase using Argon2id.

.. confval:: masterKeyPath

   :type: string
   :Default: empty

   Path to the master key file when using the "file" provider.
   Must be outside the web root with restrictive permissions (0400).

.. confval:: masterKeyEnvVar

   :type: string
   :Default: NR_VAULT_MASTER_KEY

   Environment variable name when using the "env" provider.

.. confval:: allowCliAccess

   :type: boolean
   :Default: false

   Allow CLI commands to access secrets without a backend user session.

.. confval:: cliAccessGroups

   :type: string
   :Default: empty

   Comma-separated list of backend user group UIDs that CLI can access.
   Empty means all secrets are accessible when CLI access is enabled.

.. confval:: auditLogRetention

   :type: integer
   :Default: 365

   Number of days to retain audit log entries. Set to 0 for unlimited retention.

.. confval:: preferXChaCha20

   :type: boolean
   :Default: false

   Prefer XChaCha20-Poly1305 over AES-256-GCM. XChaCha20 is recommended
   when hardware AES acceleration is not available.

Master key providers
====================

File provider
-------------

Store the master key in a file with restrictive permissions:

.. code-block:: bash

   # Generate a new key
   openssl rand -base64 32 > /secure/path/vault-master.key
   chmod 0400 /secure/path/vault-master.key

Configure in extension settings:

-  :confval:`masterKeyProvider`: file
-  :confval:`masterKeyPath`: /secure/path/vault-master.key

.. warning::

   The key file must be:

   -  Outside the web root
   -  Readable only by the web server user
   -  Not in version control
   -  Backed up separately from the database

Environment provider
--------------------

Store the master key in an environment variable:

.. code-block:: bash

   export NR_VAULT_MASTER_KEY="base64-encoded-key"

Configure in extension settings:

-  :confval:`masterKeyProvider`: env
-  :confval:`masterKeyEnvVar`: NR_VAULT_MASTER_KEY

This is ideal for containerized deployments where secrets are injected
via environment variables.

Derived provider
----------------

Derive the master key from a passphrase using Argon2id:

Configure in extension settings:

-  :confval:`masterKeyProvider`: derived

The passphrase will be prompted during CLI operations or must be
provided via secure configuration.

Access control
==============

Access to secrets is controlled by:

1. **Ownership**: The user who created the secret has full access.
2. **Group membership**: Secrets can be shared with backend user groups.
3. **Admin access**: Backend administrators have access to all secrets.
4. **CLI access**: Configurable via :confval:`allowCliAccess`.

Context-based scoping
=====================

Organize secrets by context for easier management:

-  :samp:`payment` - Payment gateway credentials
-  :samp:`email` - Email service API keys
-  :samp:`api` - Third-party API tokens
-  :samp:`database` - External database credentials

Contexts are user-defined strings that help organize and filter secrets.
