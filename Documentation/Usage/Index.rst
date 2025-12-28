.. include:: /Includes.rst.txt

=====
Usage
=====

Backend module
==============

Access the vault through the TYPO3 backend:

1. Go to :guilabel:`System > Vault`
2. The module displays all secrets you have access to

Creating secrets
----------------

1. Click :guilabel:`Create Secret`
2. Fill in the form:

   Identifier
      Unique identifier for the secret (e.g., "stripe-api-key")

   Value
      The secret value to encrypt

   Description
      Optional description for documentation

   Context
      Optional context for organization (e.g., "payment")

   Allowed groups
      Backend user groups that can access this secret

3. Click :guilabel:`Save`

Viewing secrets
---------------

Secrets are displayed with their metadata but not their values.
Click :guilabel:`Reveal` to temporarily show a secret value.

.. note::

   Revealing a secret creates an audit log entry.

CLI commands
============

vault:retrieve
--------------

Retrieve a secret value:

.. code-block:: bash

   vendor/bin/typo3 vault:retrieve payment/stripe-api-key

   # Quiet mode for scripting
   API_KEY=$(vendor/bin/typo3 vault:retrieve -q payment/stripe-api-key)

vault:store
-----------

Create or update a secret:

.. code-block:: bash

   vendor/bin/typo3 vault:store payment/stripe-api-key --value="sk_live_..."

Options:

--value
   The secret value (prompts if not provided)

--description
   Add a description

--context
   Set the context

--groups
   Comma-separated group UIDs

vault:list
----------

List all accessible secrets:

.. code-block:: bash

   vendor/bin/typo3 vault:list

   # Filter by pattern
   vendor/bin/typo3 vault:list --pattern="payment/*"

vault:delete
------------

Delete a secret:

.. code-block:: bash

   vendor/bin/typo3 vault:delete payment/stripe-api-key --reason="No longer needed"

vault:rotate
------------

Rotate a secret with a new value:

.. code-block:: bash

   vendor/bin/typo3 vault:rotate payment/stripe-api-key --reason="Quarterly rotation"

vault:rotate-master-key
-----------------------

Rotate the master encryption key (re-encrypts all DEKs):

.. code-block:: bash

   # Using old key from file, new key from current config
   vendor/bin/typo3 vault:rotate-master-key --old-key=/path/to/old.key --confirm

   # Dry run to simulate
   vendor/bin/typo3 vault:rotate-master-key --old-key=/path/to/old.key --dry-run

PHP API
=======

Inject the VaultService to access secrets programmatically:

.. code-block:: php

   use Netresearch\NrVault\Service\VaultServiceInterface;

   final class PaymentService
   {
       public function __construct(
           private readonly VaultServiceInterface $vaultService,
       ) {}

       public function getApiKey(): string
       {
           return $this->vaultService->retrieve('payment/stripe-api-key');
       }
   }

Storing secrets
---------------

.. code-block:: php

   $this->vaultService->store(
       identifier: 'payment/stripe-api-key',
       secret: 'sk_live_...',
       options: [
           'description' => 'Stripe production API key',
           'context' => 'payment',
           'groups' => [1, 2], // Backend user group UIDs
       ],
   );

Checking existence
------------------

.. code-block:: php

   if ($this->vaultService->exists('payment/stripe-api-key')) {
       $value = $this->vaultService->retrieve('payment/stripe-api-key');
   }

Listing secrets
---------------

.. code-block:: php

   // Get all accessible secrets
   $secrets = $this->vaultService->list();

   // Filter by pattern
   $paymentSecrets = $this->vaultService->list(pattern: 'payment/*');
