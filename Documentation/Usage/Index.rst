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

vault:get
---------

Retrieve a secret value:

.. code-block:: bash

   vendor/bin/typo3 vault:get payment/stripe-api-key

vault:set
---------

Create or update a secret:

.. code-block:: bash

   vendor/bin/typo3 vault:set payment/stripe-api-key "sk_live_..."

Options:

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

   # Filter by context
   vendor/bin/typo3 vault:list --context=payment

vault:delete
------------

Delete a secret:

.. code-block:: bash

   vendor/bin/typo3 vault:delete payment/stripe-api-key

vault:rotate
------------

Rotate the master key:

.. code-block:: bash

   vendor/bin/typo3 vault:rotate-master-key

This re-encrypts all DEKs with a new master key.

vault:status
------------

Check vault status:

.. code-block:: bash

   vendor/bin/typo3 vault:status

PHP API
=======

Inject the VaultService to access secrets programmatically:

.. code-block:: php

   use Netresearch\NrVault\Service\VaultService;

   final class PaymentService
   {
       public function __construct(
           private readonly VaultService $vaultService,
       ) {}

       public function getApiKey(): string
       {
           return $this->vaultService->get('payment/stripe-api-key');
       }
   }

Creating secrets
----------------

.. code-block:: php

   $this->vaultService->set(
       identifier: 'payment/stripe-api-key',
       value: 'sk_live_...',
       options: [
           'description' => 'Stripe production API key',
           'context' => 'payment',
           'groups' => [1, 2], // Backend user group UIDs
       ],
   );

Checking access
---------------

.. code-block:: php

   if ($this->vaultService->exists('payment/stripe-api-key')) {
       $value = $this->vaultService->get('payment/stripe-api-key');
   }

Listing secrets
---------------

.. code-block:: php

   // Get all accessible secrets
   $secrets = $this->vaultService->list();

   // Filter by context
   $paymentSecrets = $this->vaultService->list(context: 'payment');
