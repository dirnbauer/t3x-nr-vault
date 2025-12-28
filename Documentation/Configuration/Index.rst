.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

Extension configuration
=======================

Configure nr-vault in :guilabel:`Admin Tools > Settings > Extension Configuration`.

.. confval:: storageAdapter

   :type: string
   :Default: local
   :Options: local, hashicorp, aws

   Where secrets are stored.

   local
      Store secrets in the TYPO3 database (default). Secrets are encrypted
      with envelope encryption before storage.

   hashicorp
      Use HashiCorp Vault as the backend. Requires additional configuration.

   aws
      Use AWS Secrets Manager. Requires AWS credentials configuration.

.. confval:: masterKeyProvider

   :type: string
   :Default: typo3
   :Options: typo3, file, env

   How to retrieve the master encryption key.

   typo3
      Derive from TYPO3's encryption key. This is the recommended default
      as it requires no additional configuration and works out of the box.

   file
      Read from a file on the filesystem.

   env
      Read from an environment variable.

.. confval:: masterKeySource

   :type: string
   :Default: NR_VAULT_MASTER_KEY

   Source location for the master key. Interpretation depends on the provider:

   -  **file**: Path to the key file (e.g., ``/secure/path/vault.key``).
   -  **env**: Environment variable name (e.g., ``NR_VAULT_MASTER_KEY``).
   -  **typo3**: Not used (key derived from TYPO3's encryption key).

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

TYPO3 provider (default)
------------------------

Uses TYPO3's built-in encryption key to derive the master key. This is the
recommended default because:

-  **Zero configuration**: Works immediately after installation.
-  **No server access required**: Ideal for users without shell access.
-  **Unique per installation**: Each TYPO3 instance has its own key.
-  **Already secured**: TYPO3's encryption key is already protected.

The master key is derived from the encryption key using HKDF-SHA256 with a
nr-vault-specific context, ensuring it cannot be used to compromise other
TYPO3 functionality.

.. code-block:: php

   // How it works internally
   $masterKey = hash_hkdf(
       'sha256',
       $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'],
       32,
       'nr-vault-master-key'
   );

.. note::

   If you rotate TYPO3's encryption key, all secrets will need to be
   re-encrypted. Use the key rotation command before changing the
   encryption key.

File provider
-------------

Store the master key in a file with restrictive permissions:

.. code-block:: bash

   # Generate a new key
   openssl rand -base64 32 > /secure/path/vault-master.key
   chmod 0400 /secure/path/vault-master.key

Configure in extension settings:

-  :confval:`masterKeyProvider`: file
-  :confval:`masterKeySource`: /secure/path/vault-master.key

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
-  :confval:`masterKeySource`: NR_VAULT_MASTER_KEY

This is ideal for containerized deployments where secrets are injected
via environment variables.

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
