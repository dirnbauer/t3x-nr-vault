.. include:: /Includes.rst.txt

.. _developer-api:

===
API
===

This chapter documents the public API of the nr-vault extension.

.. _api-vault-service:

VaultService
============

The main service for interacting with the vault.

.. php:namespace:: Netresearch\NrVault\Service

.. php:interface:: VaultServiceInterface

   Main interface for vault operations.

   .. php:method:: store(string $identifier, string $secret, array $options = []): void

      Store a secret in the vault.

      :param string $identifier: Unique identifier for the secret
      :param string $secret: The secret value to store
      :param array $options: Optional configuration (owner, groups, context, expiresAt, metadata)

   .. php:method:: retrieve(string $identifier)

      Retrieve a secret from the vault.

      :param string $identifier: The secret identifier
      :returns: The decrypted secret value or null if not found
      :returntype: string|null
      :throws AccessDeniedException: If user lacks read permission
      :throws SecretExpiredException: If the secret has expired

   .. php:method:: exists(string $identifier): bool

      Check if a secret exists.

      :param string $identifier: The secret identifier
      :returns: True if the secret exists

   .. php:method:: delete(string $identifier, string $reason = ''): void

      Delete a secret from the vault.

      :param string $identifier: The secret identifier
      :param string $reason: Optional reason for deletion (logged)
      :throws SecretNotFoundException: If secret doesn't exist
      :throws AccessDeniedException: If user lacks delete permission

   .. php:method:: rotate(string $identifier, string $newSecret, string $reason = ''): void

      Rotate a secret with a new value.

      :param string $identifier: The secret identifier
      :param string $newSecret: The new secret value
      :param string $reason: Optional reason for rotation (logged)

   .. php:method:: list(?string $pattern = null): array

      List accessible secrets.

      :param string|null $pattern: Optional pattern to filter identifiers
      :returns: Array of secret metadata

   .. php:method:: getMetadata(string $identifier): array

      Get metadata for a secret without retrieving its value.

      :param string $identifier: The secret identifier
      :returns: Array with identifier, description, owner, groups, version, etc.

   .. php:method:: http(): VaultHttpClientInterface

      Get an HTTP client that can inject secrets into requests.

      :returns: A vault-aware HTTP client

.. _api-usage-examples:

Usage examples
--------------

Storing a secret
~~~~~~~~~~~~~~~~

.. code-block:: php

   use Netresearch\NrVault\Service\VaultServiceInterface;

   class MyService
   {
       public function __construct(
           private readonly VaultServiceInterface $vault,
       ) {}

       public function storeApiKey(string $apiKey): void
       {
           $this->vault->store(
               'my_extension_api_key',
               $apiKey,
               [
                   'description' => 'API key for external service',
                   'groups' => [1, 2], // Admin, Editor groups
                   'context' => 'payment',
                   'expiresAt' => time() + 86400 * 90, // 90 days
               ]
           );
       }
   }

Retrieving a secret
~~~~~~~~~~~~~~~~~~~

.. code-block:: php

   public function getApiKey(): ?string
   {
       return $this->vault->retrieve('my_extension_api_key');
   }

.. _api-http-client:

Vault HTTP client
-----------------

The vault provides an HTTP client that can inject secrets into requests
without exposing them to your code.

.. php:interface:: VaultHttpClientInterface

   .. php:method:: withSecret(string $identifier, SecretPlacement $placement, ?string $name = null): self

      Configure a secret to be injected into requests.

      :param string $identifier: The secret identifier in the vault
      :param SecretPlacement $placement: How to inject the secret
      :param string|null $name: Header/parameter name (optional for some placements)

.. code-block:: php

   use Netresearch\NrVault\Http\SecretPlacement;

   // Bearer authentication
   $response = $this->vault->http()
       ->withSecret('stripe_api_key', SecretPlacement::BearerAuth)
       ->post('https://api.stripe.com/v1/charges', $payload);

   // Custom header
   $response = $this->vault->http()
       ->withSecret('api_token', SecretPlacement::Header, 'X-API-Key')
       ->get('https://api.example.com/data');

.. _api-events:

PSR-14 events
-------------

The vault dispatches events during secret operations.

.. php:class:: SecretCreatedEvent

   Dispatched when a new secret is created.

   - :php:`getIdentifier()`: The secret identifier
   - :php:`getSecret()`: The Secret entity
   - :php:`getActorUid()`: User ID who created it

.. php:class:: SecretAccessedEvent

   Dispatched when a secret is read.

   - :php:`getIdentifier()`: The secret identifier
   - :php:`getActorUid()`: User ID who accessed it
   - :php:`getContext()`: The secret's context

.. php:class:: SecretRotatedEvent

   Dispatched when a secret is rotated.

   - :php:`getIdentifier()`: The secret identifier
   - :php:`getNewVersion()`: The new version number
   - :php:`getActorUid()`: User ID who rotated it
   - :php:`getReason()`: The rotation reason

.. php:class:: SecretDeletedEvent

   Dispatched when a secret is deleted.

   - :php:`getIdentifier()`: The secret identifier
   - :php:`getActorUid()`: User ID who deleted it
   - :php:`getReason()`: The deletion reason
