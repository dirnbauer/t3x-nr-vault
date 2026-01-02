.. include:: /Includes.rst.txt

.. _tca-integration:

===============
TCA integration
===============

nr-vault provides a custom TCA field type that allows any TYPO3 extension
to store sensitive data (API keys, credentials, tokens) securely in the vault
instead of plaintext in the database.

.. contents:: Table of contents
   :local:
   :depth: 2

.. _tca-quick-start:

Quick start
===========

.. _tca-step1-dependency:

Step 1: Add dependency
----------------------

Add nr-vault as a dependency in your extension's :file:`composer.json`:

.. code-block:: json

   {
       "require": {
           "netresearch/nr-vault": "^1.0"
       }
   }

.. _tca-step2-configure:

Step 2: Configure TCA field
---------------------------

Use the ``vaultSecret`` renderType in your TCA configuration:

.. code-block:: php
   :caption: Configuration/TCA/tx_myext_settings.php

   <?php
   return [
       'ctrl' => [
           'title' => 'My Extension Settings',
           // ... other ctrl settings
       ],
       'columns' => [
           'api_key' => [
               'label' => 'API Key',
               'config' => [
                   'type' => 'input',
                   'renderType' => 'vaultSecret',
                   'size' => 30,
               ],
           ],
       ],
   ];

.. _tca-step3-database:

Step 3: Add database column
---------------------------

Add the column to your extension's :file:`ext_tables.sql`:

.. code-block:: sql

   CREATE TABLE tx_myext_settings (
       api_key varchar(255) DEFAULT '' NOT NULL
   );

The column stores the vault identifier, not the actual secret.

.. _tca-step4-retrieve:

Step 4: Retrieve secrets in code
--------------------------------

Use the :php:`VaultFieldResolver` utility to retrieve actual secret values:

.. code-block:: php

   use Netresearch\NrVault\Utility\VaultFieldResolver;

   class MyService
   {
       public function callExternalApi(array $settings): void
       {
           // Resolve vault identifiers to actual values
           $resolved = VaultFieldResolver::resolveFields(
               $settings,
               ['api_key', 'api_secret']
           );

           // Now $resolved['api_key'] contains the actual secret
           $client->authenticate($resolved['api_key']);
       }
   }


.. _tca-helper:

Using the TCA helper
====================

For cleaner TCA configuration, use the :php:`VaultFieldHelper`:

.. code-block:: php
   :caption: Configuration/TCA/tx_myext_settings.php

   <?php
   use Netresearch\NrVault\TCA\VaultFieldHelper;

   return [
       'columns' => [
           'api_key' => VaultFieldHelper::getFieldConfig([
               'label' => 'API Key',
               'description' => 'Your API authentication key',
               'size' => 30,
           ]),

           // Secure field with common defaults (exclude: true, l10n_mode: exclude)
           'api_secret' => VaultFieldHelper::getSecureFieldConfig(
               'API Secret',
               ['required' => true]
           ),
       ],
   ];

.. _tca-helper-options:

Available options
-----------------

==================  ======  ===================================================
Option              Type    Description
==================  ======  ===================================================
``label``           string  Field label.
``description``     string  Field description/help text.
``size``            int     Input field size (default: 30).
``required``        bool    Whether field is required (default: false).
``placeholder``     string  Placeholder text.
``displayCond``     string  TCA display condition.
``l10n_mode``       string  Localization mode.
``exclude``         bool    Exclude from non-admin access.
==================  ======  ===================================================


.. _tca-flexform:

FlexForm integration
====================

Vault secrets also work in FlexForm fields:

.. code-block:: xml
   :caption: Configuration/FlexForms/Settings.xml

   <T3DataStructure>
       <sheets>
           <settings>
               <ROOT>
                   <el>
                       <apiKey>
                           <label>API Key</label>
                           <config>
                               <type>input</type>
                               <renderType>vaultSecret</renderType>
                               <size>30</size>
                           </config>
                       </apiKey>
                   </el>
               </ROOT>
           </settings>
       </sheets>
   </T3DataStructure>

Resolve FlexForm secrets using :php:`FlexFormVaultResolver`:

.. code-block:: php

   use Netresearch\NrVault\Utility\FlexFormVaultResolver;
   use TYPO3\CMS\Core\Service\FlexFormService;

   class MyPlugin
   {
       public function __construct(
           private readonly FlexFormService $flexFormService,
       ) {}

       public function processSettings(array $contentElement): array
       {
           $settings = $this->flexFormService->convertFlexFormContentToArray(
               $contentElement['pi_flexform']
           );

           // Resolve specific fields
           return FlexFormVaultResolver::resolveSettings(
               $settings,
               ['apiKey', 'apiSecret']
           );

           // Or resolve all vault identifiers automatically
           return FlexFormVaultResolver::resolveAll($settings);
       }
   }


.. _tca-resolver-api:

VaultFieldResolver API
======================

The :php:`VaultFieldResolver` class provides utilities for working with
vault-backed TCA fields.

.. _tca-resolver-resolve-fields:

resolveFields()
---------------

Resolve specific fields in a data array:

.. code-block:: php

   $resolved = VaultFieldResolver::resolveFields(
       $data,           // Array with potential vault identifiers
       ['field1'],      // Fields to resolve
       false            // Throw on error (default: false)
   );

.. _tca-resolver-resolve:

resolve()
---------

Resolve a single vault identifier:

.. code-block:: php

   $secret = VaultFieldResolver::resolve('tx_myext_settings__api_key__1');

.. _tca-resolver-resolve-record:

resolveRecord()
---------------

Automatically resolve all vault fields in a record based on TCA:

.. code-block:: php

   $resolved = VaultFieldResolver::resolveRecord('tx_myext_settings', $record);

.. _tca-resolver-is-identifier:

isVaultIdentifier()
-------------------

Check if a value is a vault identifier:

.. code-block:: php

   if (VaultFieldResolver::isVaultIdentifier($value)) {
       // This is a vault identifier
   }

.. _tca-resolver-get-fields:

getVaultFieldsForTable()
------------------------

Get list of vault field names for a table:

.. code-block:: php

   $fields = VaultFieldResolver::getVaultFieldsForTable('tx_myext_settings');
   // Returns: ['api_key', 'api_secret']


.. _tca-how-it-works:

How it works
============

.. _tca-data-flow:

Data flow
---------

1. **Form display**: The :php:`VaultSecretElement` renders an obfuscated
   password field with reveal/copy buttons.

2. **Form submit**: The :php:`DataHandlerHook` intercepts the form data:

   - Extracts the secret value from the form.
   - Generates a vault identifier: ``{table}__{field}__{uid}``.
   - Stores the secret in the vault.
   - Saves only the identifier to the database.

3. **Runtime retrieval**: Your code uses :php:`VaultFieldResolver` to
   look up the actual secret from the vault.

.. _tca-identifier-format:

Identifier format
-----------------

Standard TCA fields: ``{table}__{field}__{uid}``

Example: ``tx_myext_settings__api_key__42``

FlexForm fields: ``{table}__{flexfield}__{sheet}__{fieldpath}__{uid}``

Example: ``tt_content__pi_flexform__settings__apiKey__123``

.. _tca-record-operations:

Record operations
-----------------

-  **Create**: New vault secret is stored automatically.
-  **Update**: Secret is rotated (maintains audit trail).
-  **Delete**: Vault secret is removed when record is deleted.
-  **Copy**: Vault secret is copied to new record.


.. _tca-security:

Security considerations
=======================

.. _tca-security-access-control:

Access control
--------------

Vault secrets inherit the access control of the record they belong to.
If a backend user can edit the record, they can update the vault secret.

The reveal button requires explicit user action and is logged.

.. _tca-security-audit:

Audit trail
-----------

All vault operations are logged:

-  Secret creation.
-  Secret reads (via reveal button).
-  Secret updates.
-  Secret deletion.

Review the audit log in the backend module under
:guilabel:`Admin Tools > Vault > Audit Log`.

.. _tca-security-no-plaintext:

No plaintext in database
------------------------

Only vault identifiers are stored in your extension's database tables.
The actual secrets are encrypted with AES-256-GCM in the vault.


.. _tca-migration:

Migration
=========

To migrate existing plaintext credentials to vault storage:

1. Add the ``renderType`` to your existing TCA field configuration.
2. Run the migration command:

   .. code-block:: bash

      vendor/bin/typo3 vault:migrate-field tx_myext_settings api_key

This will:

-  Read existing plaintext values.
-  Store them securely in the vault.
-  Update records with vault identifiers.

.. attention::

   Always backup your database before running migrations.
