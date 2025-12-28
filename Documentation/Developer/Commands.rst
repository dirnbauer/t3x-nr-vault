.. include:: /Includes.rst.txt

.. _developer-commands:

============
CLI commands
============

nr-vault provides several CLI commands for DevOps automation and management.

.. _command-init:

vault:init
==========

Initialize the vault by creating a master key.

.. code-block:: bash

   vendor/bin/typo3 vault:init [options]

Options
-------

--key-file=PATH
   Path to store the master key file (default: var/vault/master.key)

--force
   Overwrite existing master key (dangerous!)

Example
-------

.. code-block:: bash

   # Initialize with default location
   vendor/bin/typo3 vault:init

   # Specify custom key file location
   vendor/bin/typo3 vault:init --key-file=/secure/path/vault.key

.. warning::

   The master key file should be stored outside the webroot with restricted
   permissions (0400 or 0600). Never commit it to version control.

.. _command-store:

vault:store
===========

Store a secret in the vault.

.. code-block:: bash

   vendor/bin/typo3 vault:store <identifier> [options]

Arguments
---------

identifier
   Unique identifier for the secret

Options
-------

--value=SECRET
   The secret value (will prompt if not provided)

--description=TEXT
   Optional description

--context=CONTEXT
   Optional context for permission scoping

--expires=TIMESTAMP
   Expiration timestamp or relative time (e.g., "+90 days")

--groups=GROUPS
   Comma-separated list of allowed backend user group IDs

Example
-------

.. code-block:: bash

   # Interactive (prompts for secret)
   vendor/bin/typo3 vault:store stripe_api_key

   # With options
   vendor/bin/typo3 vault:store payment_key \
     --value="sk_live_..." \
     --description="Stripe production key" \
     --context="payment" \
     --expires="+90 days" \
     --groups="1,2"

.. _command-retrieve:

vault:retrieve
==============

Retrieve a secret from the vault.

.. code-block:: bash

   vendor/bin/typo3 vault:retrieve <identifier> [options]

Options
-------

--quiet, -q
   Output only the secret value (for scripting)

Example
-------

.. code-block:: bash

   # Display with metadata
   vendor/bin/typo3 vault:retrieve stripe_api_key

   # For use in scripts
   API_KEY=$(vendor/bin/typo3 vault:retrieve -q stripe_api_key)

.. _command-list:

vault:list
==========

List all accessible secrets.

.. code-block:: bash

   vendor/bin/typo3 vault:list [options]

Options
-------

--pattern=PATTERN
   Filter by identifier pattern (supports * wildcard)

--format=FORMAT
   Output format: table (default), json, csv

Example
-------

.. code-block:: bash

   # List all secrets
   vendor/bin/typo3 vault:list

   # Filter by pattern
   vendor/bin/typo3 vault:list --pattern="payment_*"

   # JSON output for automation
   vendor/bin/typo3 vault:list --format=json

.. _command-rotate:

vault:rotate
============

Rotate a secret with a new value.

.. code-block:: bash

   vendor/bin/typo3 vault:rotate <identifier> [options]

Options
-------

--value=SECRET
   The new secret value (will prompt if not provided)

--reason=TEXT
   Reason for rotation (logged in audit)

Example
-------

.. code-block:: bash

   vendor/bin/typo3 vault:rotate stripe_api_key \
     --reason="Scheduled quarterly rotation"

.. _command-delete:

vault:delete
============

Delete a secret from the vault.

.. code-block:: bash

   vendor/bin/typo3 vault:delete <identifier> [options]

Options
-------

--reason=TEXT
   Reason for deletion (logged in audit)

--force, -f
   Skip confirmation prompt

Example
-------

.. code-block:: bash

   vendor/bin/typo3 vault:delete old_api_key \
     --reason="Service deprecated" \
     --force

.. _command-audit:

vault:audit
===========

View the audit log.

.. code-block:: bash

   vendor/bin/typo3 vault:audit [options]

Options
-------

--identifier=ID
   Filter by secret identifier

--action=ACTION
   Filter by action (create, read, update, delete, rotate)

--days=N
   Show entries from last N days (default: 30)

--limit=N
   Maximum entries to show (default: 100)

--format=FORMAT
   Output format: table (default), json

Example
-------

.. code-block:: bash

   # View recent audit log
   vendor/bin/typo3 vault:audit --days=7

   # Filter by secret
   vendor/bin/typo3 vault:audit --identifier=stripe_api_key

   # Export to JSON
   vendor/bin/typo3 vault:audit --format=json > audit.json

.. _command-master-key-rotate:

vault:master-key:rotate
=======================

Rotate the master encryption key.

.. code-block:: bash

   vendor/bin/typo3 vault:master-key:rotate [options]

Options
-------

--new-key-file=PATH
   Path to the new master key file

--backup
   Create backup of old key before rotation

.. warning::

   Master key rotation re-encrypts all Data Encryption Keys (DEKs).
   Ensure you have a backup before proceeding.

Example
-------

.. code-block:: bash

   vendor/bin/typo3 vault:master-key:rotate \
     --new-key-file=/secure/path/new-master.key \
     --backup

.. _command-export:

vault:export
============

Export secrets for backup (encrypted).

.. code-block:: bash

   vendor/bin/typo3 vault:export [options]

Options
-------

--output=PATH
   Output file path (default: vault-backup.enc)

--password=PASS
   Encryption password (will prompt if not provided)

Example
-------

.. code-block:: bash

   vendor/bin/typo3 vault:export --output=/backup/secrets.enc
