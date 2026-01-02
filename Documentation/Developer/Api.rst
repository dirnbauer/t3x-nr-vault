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

      :param string $identifier: Unique identifier for the secret.
      :param string $secret: The secret value to store.
      :param array $options: Optional configuration (owner, groups, context, expiresAt, metadata).

   .. php:method:: retrieve(string $identifier)

      Retrieve a secret from the vault.

      :param string $identifier: The secret identifier.
      :returns: The decrypted secret value or null if not found.
      :returntype: string|null
      :throws AccessDeniedException: If user lacks read permission.
      :throws SecretExpiredException: If the secret has expired.

   .. php:method:: exists(string $identifier): bool

      Check if a secret exists.

      :param string $identifier: The secret identifier.
      :returns: True if the secret exists.

   .. php:method:: delete(string $identifier, string $reason = ''): void

      Delete a secret from the vault.

      :param string $identifier: The secret identifier.
      :param string $reason: Optional reason for deletion (logged).
      :throws SecretNotFoundException: If secret doesn't exist.
      :throws AccessDeniedException: If user lacks delete permission.

   .. php:method:: rotate(string $identifier, string $newSecret, string $reason = ''): void

      Rotate a secret with a new value.

      :param string $identifier: The secret identifier.
      :param string $newSecret: The new secret value.
      :param string $reason: Optional reason for rotation (logged).

   .. php:method:: list(string $pattern = null): array

      List accessible secrets.

      :param string|null $pattern: Optional pattern to filter identifiers.
      :returns: Array of secret metadata.

   .. php:method:: getMetadata(string $identifier): array

      Get metadata for a secret without retrieving its value.

      :param string $identifier: The secret identifier.
      :returns: Array with identifier, description, owner, groups, version, etc.

   .. php:method:: http(): VaultHttpClientInterface

      Get an HTTP client that can inject secrets into requests.

      :returns: A vault-aware HTTP client.

.. _api-usage-examples:

Usage examples
--------------

.. _api-example-storing:

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

.. _api-example-retrieving:

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
without exposing them to your code. You can inject it directly or access
it via VaultService.

.. _api-http-direct-injection:

Direct injection (recommended)
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

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

.. _api-http-via-service:

Via VaultService
~~~~~~~~~~~~~~~~

.. code-block:: php

   $response = $this->vaultService->http()->post(
       'https://api.stripe.com/v1/charges',
       [
           'auth_secret' => 'stripe_api_key',
           'placement' => SecretPlacement::Bearer,
           'json' => $payload,
       ],
   );

.. php:interface:: VaultHttpClientInterface

   .. php:method:: request(string $method, string $url, array $options = []): ResponseInterface

      Make an HTTP request with vault-provided authentication.

      :param string $method: HTTP method (GET, POST, PUT, DELETE, etc.).
      :param string $url: Request URL.
      :param array $options: Request options including auth_secret, placement, headers, etc.

   .. php:method:: get(string $url, array $options = []): ResponseInterface

      Shorthand for GET request.

   .. php:method:: post(string $url, array $options = []): ResponseInterface

      Shorthand for POST request.

   .. php:method:: put(string $url, array $options = []): ResponseInterface

      Shorthand for PUT request.

   .. php:method:: delete(string $url, array $options = []): ResponseInterface

      Shorthand for DELETE request.

   .. php:method:: patch(string $url, array $options = []): ResponseInterface

      Shorthand for PATCH request.

   .. php:method:: send(string $method, string $url, array $options = []): VaultHttpResponse

      Make a request and return a VaultHttpResponse wrapper with helper methods.

.. _api-http-auth-options:

Authentication options
~~~~~~~~~~~~~~~~~~~~~~

placement
   Authentication placement using :php:`SecretPlacement` enum:

   -  :php:`SecretPlacement::Bearer` - Bearer token in Authorization header.
   -  :php:`SecretPlacement::BasicAuth` - HTTP Basic Authentication.
   -  :php:`SecretPlacement::Header` - Custom header value.
   -  :php:`SecretPlacement::QueryParam` - Query parameter.
   -  :php:`SecretPlacement::BodyField` - Field in request body.
   -  :php:`SecretPlacement::OAuth2` - OAuth 2.0 with automatic token refresh.
   -  :php:`SecretPlacement::ApiKey` - X-API-Key header (shorthand).

auth_secret
   Secret identifier for authentication.

auth_header
   Custom header name (for :php:`SecretPlacement::Header`, default: ``X-API-Key``).

auth_query_param
   Query parameter name (for :php:`SecretPlacement::QueryParam`, default: ``api_key``).

auth_body_field
   Body field name (for :php:`SecretPlacement::BodyField`, default: ``api_key``).

auth_username_secret
   Separate username secret (for :php:`SecretPlacement::BasicAuth`).

oauth_config
   :php:`OAuthConfig` instance (for :php:`SecretPlacement::OAuth2`).

reason
   Reason for access (logged in audit).

.. _api-http-auth-examples:

.. code-block:: php

   use Netresearch\NrVault\Http\SecretPlacement;

   // Bearer authentication
   $response = $this->vault->http()->post(
       'https://api.stripe.com/v1/charges',
       [
           'auth_secret' => 'stripe_api_key',
           'placement' => SecretPlacement::Bearer,
           'json' => $payload,
       ]
   );

   // Custom header
   $response = $this->vault->http()->get(
       'https://api.example.com/data',
       [
           'auth_secret' => 'api_token',
           'placement' => SecretPlacement::Header,
           'auth_header' => 'X-API-Key',
       ]
   );

   // Basic authentication with separate credentials
   $response = $this->vault->http()->get(
       'https://api.example.com/secure',
       [
           'auth_username_secret' => 'service_username',
           'auth_secret' => 'service_password',
           'placement' => SecretPlacement::BasicAuth,
           'reason' => 'Fetching secure data',
       ]
   );

.. _api-events:

PSR-14 events
-------------

The vault dispatches events during secret operations.

.. php:class:: SecretCreatedEvent

   Dispatched when a new secret is created.

   -  :php:`getIdentifier()`: The secret identifier.
   -  :php:`getSecret()`: The Secret entity.
   -  :php:`getActorUid()`: User ID who created it.

.. php:class:: SecretAccessedEvent

   Dispatched when a secret is read.

   -  :php:`getIdentifier()`: The secret identifier.
   -  :php:`getActorUid()`: User ID who accessed it.
   -  :php:`getContext()`: The secret's context.

.. php:class:: SecretRotatedEvent

   Dispatched when a secret is rotated.

   -  :php:`getIdentifier()`: The secret identifier.
   -  :php:`getNewVersion()`: The new version number.
   -  :php:`getActorUid()`: User ID who rotated it.
   -  :php:`getReason()`: The rotation reason.

.. php:class:: SecretDeletedEvent

   Dispatched when a secret is deleted.

   -  :php:`getIdentifier()`: The secret identifier.
   -  :php:`getActorUid()`: User ID who deleted it.
   -  :php:`getReason()`: The deletion reason.

.. php:class:: SecretUpdatedEvent

   Dispatched when a secret value is updated (without rotation).

   -  :php:`getIdentifier()`: The secret identifier.
   -  :php:`getNewVersion()`: The new version number.
   -  :php:`getActorUid()`: User ID who updated it.

.. php:class:: MasterKeyRotatedEvent

   Dispatched after master key rotation completes.

   -  :php:`getSecretsReEncrypted()`: Number of secrets re-encrypted.
   -  :php:`getActorUid()`: User ID who performed the rotation.
   -  :php:`getRotatedAt()`: DateTimeImmutable of when rotation completed.
