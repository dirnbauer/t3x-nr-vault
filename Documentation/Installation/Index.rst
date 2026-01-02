.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

.. _installation-requirements:

Requirements
============

Before installing nr-vault, ensure your system meets these requirements:

-  TYPO3 v14.0 or higher.
-  PHP 8.5 or higher.
-  PHP sodium extension (usually included in PHP 8.5).
-  Composer-based TYPO3 installation.

.. _installation-composer:

Installation via Composer
=========================

Install the extension using Composer:

.. code-block:: bash

   composer require netresearch/nr-vault

.. _installation-activate:

Activate the extension
======================

After installation, activate the extension in the TYPO3 backend:

1. Go to :guilabel:`Admin Tools > Extensions`.
2. Find "nr-vault" in the list.
3. Click the activation icon.

Or use the command line:

.. code-block:: bash

   vendor/bin/typo3 extension:activate nr_vault

.. _installation-database:

Database schema
===============

Update the database schema to create the required tables:

.. code-block:: bash

   vendor/bin/typo3 database:updateschema

This creates the following tables:

-  :sql:`tx_nrvault_secret` - Stores encrypted secrets with metadata.
-  :sql:`tx_nrvault_audit_log` - Stores audit log entries with hash chain.

.. _installation-master-key:

Master key setup
================

Before using nr-vault, you must configure a master key. See the
:ref:`configuration` section for details on master key providers.

.. warning::

   Never commit master keys to version control. Store them securely
   outside the web root.

.. _installation-master-key-quickstart:

Quick start with environment variable
-------------------------------------

The fastest way to get started is using an environment variable:

1. Generate a master key:

   .. code-block:: bash

      openssl rand -base64 32

2. Set the environment variable:

   .. code-block:: bash

      export NR_VAULT_MASTER_KEY="your-generated-key"

3. Configure the extension to use the environment provider
   (see :ref:`configuration`).

.. _installation-verify:

Verify installation
===================

Verify the installation by listing secrets (should return empty if newly installed):

.. code-block:: bash

   vendor/bin/typo3 vault:list

If the command executes without errors, the extension is properly configured.

You can also test by storing and retrieving a test secret:

.. code-block:: bash

   # Store a test secret
   vendor/bin/typo3 vault:store test_secret --value="test-value"

   # Retrieve it
   vendor/bin/typo3 vault:retrieve test_secret

   # Clean up
   vendor/bin/typo3 vault:delete test_secret --force
