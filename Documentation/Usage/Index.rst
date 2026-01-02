.. include:: /Includes.rst.txt

=====
Usage
=====

Backend module
==============

Access the vault through the TYPO3 backend:

1. Go to :guilabel:`Admin Tools > Vault`
2. The overview shows statistics and quick-start examples
3. Navigate to :guilabel:`Secrets` to manage your secrets

Creating secrets
----------------

1. Click :guilabel:`Create Secret` (+ button)
2. Fill in the form:

   Identifier
      Unique identifier for the secret (e.g., ``stripe_api_key``)

   Value
      The secret value to encrypt

   Description
      Optional description for documentation

   Context
      Optional context for organization (e.g., ``payment``)

   Allowed groups
      Backend user groups that can access this secret

   Expiration
      Optional expiration date after which the secret becomes inaccessible

3. Click :guilabel:`Save`

Viewing and editing secrets
---------------------------

Secrets are displayed with their metadata but not their values.
Click :guilabel:`Reveal` to temporarily show a secret value.

.. note::

   Revealing a secret creates an audit log entry.

Site configuration
==================

Reference secrets in your site configuration files using the
``%vault(identifier)%`` syntax:

.. code-block:: yaml
   :caption: config/sites/mysite/config.yaml

   settings:
     payment:
       stripePublicKey: 'pk_live_...'
       stripeSecretKey: '%vault(stripe_secret_key)%'
     email:
       mailchimpApiKey: '%vault(mailchimp_api_key)%'
       sendgridToken: '%vault(sendgrid_token)%'

Secrets are resolved at runtime when the site configuration is loaded.
This keeps sensitive values out of your version control while still
allowing you to configure them through the familiar site settings.

.. important::

   Site configuration secrets are resolved on every request. Ensure
   your vault storage is performant (the default local adapter caches
   lookups).

TypoScript integration
======================

Use vault references in TypoScript for frontend-accessible secrets:

.. code-block:: typoscript

   lib.googleMapsKey = TEXT
   lib.googleMapsKey.value = %vault(google_maps_api_key)%

   page.headerData.10 = TEXT
   page.headerData.10.value = <script>var API_KEY = '%vault(public_api_key)%';</script>

.. warning::

   **Security considerations:**

   -  Only secrets marked as ``frontend_accessible`` can be resolved
   -  Resolved values may be cached - use ``cache.disable = 1`` for
      secrets that should not be cached
   -  Consider using ``USER_INT`` for content containing secrets

Example with caching disabled:

.. code-block:: typoscript

   lib.apiKey = TEXT
   lib.apiKey {
     value = %vault(my_api_key)%
     stdWrap.cache.disable = 1
   }

CLI commands
============

vault:init
----------

Initialize the vault and generate a master key:

.. code-block:: bash

   vendor/bin/typo3 vault:init

   # Output as environment variable format
   vendor/bin/typo3 vault:init --env

   # Specify custom output location
   vendor/bin/typo3 vault:init --output=/secure/path/vault.key

vault:store
-----------

Create or update a secret:

.. code-block:: bash

   # Interactive (prompts for value)
   vendor/bin/typo3 vault:store stripe_api_key

   # With all options
   vendor/bin/typo3 vault:store payment_key \
     --value="sk_live_..." \
     --description="Stripe production key" \
     --context="payment" \
     --expires="+90 days" \
     --groups="1,2"

vault:retrieve
--------------

Retrieve a secret value:

.. code-block:: bash

   vendor/bin/typo3 vault:retrieve stripe_api_key

   # Quiet mode for scripting
   API_KEY=$(vendor/bin/typo3 vault:retrieve -q stripe_api_key)

vault:list
----------

List all accessible secrets:

.. code-block:: bash

   vendor/bin/typo3 vault:list

   # Filter by pattern
   vendor/bin/typo3 vault:list --pattern="payment_*"

   # JSON output for automation
   vendor/bin/typo3 vault:list --format=json

vault:rotate
------------

Rotate a secret with a new value:

.. code-block:: bash

   vendor/bin/typo3 vault:rotate stripe_api_key \
     --reason="Scheduled quarterly rotation"

vault:delete
------------

Delete a secret:

.. code-block:: bash

   vendor/bin/typo3 vault:delete old_api_key \
     --reason="Service deprecated" \
     --force

vault:audit
-----------

View the audit log:

.. code-block:: bash

   # View recent entries
   vendor/bin/typo3 vault:audit --days=7

   # Filter by secret
   vendor/bin/typo3 vault:audit --identifier=stripe_api_key

   # Export to JSON
   vendor/bin/typo3 vault:audit --format=json > audit.json

vault:rotate-master-key
-----------------------

Rotate the master encryption key (re-encrypts all DEKs):

.. code-block:: bash

   # Using old key from file, new key from current config
   vendor/bin/typo3 vault:rotate-master-key \
     --old-key=/path/to/old.key \
     --confirm

   # Dry run to simulate
   vendor/bin/typo3 vault:rotate-master-key \
     --old-key=/path/to/old.key \
     --dry-run

vault:scan
----------

Scan for potential plaintext secrets in database:

.. code-block:: bash

   vendor/bin/typo3 vault:scan

   # Only critical issues
   vendor/bin/typo3 vault:scan --severity=critical

   # JSON for CI/CD
   vendor/bin/typo3 vault:scan --format=json

vault:migrate-field
-------------------

Migrate existing plaintext field values to vault:

.. code-block:: bash

   # Preview
   vendor/bin/typo3 vault:migrate-field tx_myext_settings api_key --dry-run

   # Execute
   vendor/bin/typo3 vault:migrate-field tx_myext_settings api_key

vault:cleanup-orphans
---------------------

Remove orphaned secrets from deleted records:

.. code-block:: bash

   vendor/bin/typo3 vault:cleanup-orphans --dry-run
   vendor/bin/typo3 vault:cleanup-orphans --retention-days=30

PHP API
=======

VaultService
------------

Inject the VaultService to access secrets programmatically:

.. code-block:: php

   use Netresearch\NrVault\Service\VaultServiceInterface;

   final class PaymentService
   {
       public function __construct(
           private readonly VaultServiceInterface $vaultService,
       ) {}

       public function getApiKey(): ?string
       {
           return $this->vaultService->retrieve('stripe_api_key');
       }
   }

Storing secrets
~~~~~~~~~~~~~~~

.. code-block:: php

   $this->vaultService->store(
       identifier: 'payment_api_key',
       secret: 'sk_live_...',
       options: [
           'description' => 'Stripe production API key',
           'context' => 'payment',
           'groups' => [1, 2], // Backend user group UIDs
           'expiresAt' => time() + (86400 * 90), // 90 days
       ],
   );

Checking existence
~~~~~~~~~~~~~~~~~~

.. code-block:: php

   if ($this->vaultService->exists('stripe_api_key')) {
       $value = $this->vaultService->retrieve('stripe_api_key');
   }

Listing secrets
~~~~~~~~~~~~~~~

.. code-block:: php

   // Get all accessible secrets
   $secrets = $this->vaultService->list();

   // Filter by pattern
   $paymentSecrets = $this->vaultService->list(pattern: 'payment_*');

Vault HTTP Client
-----------------

Make authenticated API calls without exposing secrets to your code.
Inject :php:`VaultHttpClientInterface` directly:

.. code-block:: php

   use Netresearch\NrVault\Http\SecretPlacement;
   use Netresearch\NrVault\Http\VaultHttpClientInterface;

   final class ExternalApiService
   {
       public function __construct(
           private readonly VaultHttpClientInterface $httpClient,
       ) {}

       public function fetchData(): array
       {
           $response = $this->httpClient->get(
               'https://api.example.com/data',
               [
                   'auth_secret' => 'api_token',
                   'placement' => SecretPlacement::Bearer,
               ],
           );

           return json_decode($response->getBody()->getContents(), true);
       }
   }

Or access via VaultService:

.. code-block:: php

   $response = $this->vaultService->http()->post(
       'https://api.stripe.com/v1/charges',
       [
           'auth_secret' => 'stripe_api_key',
           'placement' => SecretPlacement::Bearer,
           'json' => $payload,
       ],
   );

Authentication options
~~~~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   use Netresearch\NrVault\Http\SecretPlacement;

   // Bearer token
   $options = [
       'auth_secret' => 'api_token',
       'placement' => SecretPlacement::Bearer,
   ];

   // API key header
   $options = [
       'auth_secret' => 'api_key',
       'placement' => SecretPlacement::ApiKey,  // X-API-Key header
   ];

   // Custom header
   $options = [
       'auth_secret' => 'api_key',
       'placement' => SecretPlacement::Header,
       'auth_header' => 'X-Custom-Auth',
   ];

   // Basic authentication
   $options = [
       'auth_username_secret' => 'service_user',
       'auth_secret' => 'service_password',
       'placement' => SecretPlacement::BasicAuth,
   ];

   // Query parameter
   $options = [
       'auth_secret' => 'api_key',
       'placement' => SecretPlacement::QueryParam,
       'auth_query_param' => 'key',
   ];
