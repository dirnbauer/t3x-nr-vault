.. include:: /Includes.rst.txt

.. _developer:

=========
Developer
=========

.. toctree::
   :maxdepth: 2

   Api
   Commands
   TcaIntegration
   Adr/Index

.. _developer-architecture:

Architecture overview
=====================

nr-vault follows clean architecture principles with these main components:

Service layer
   :php:`VaultService` - Main facade for all vault operations.

Crypto layer
   :php:`EncryptionService` - Envelope encryption implementation.
   :php:`MasterKeyProvider` - Master key retrieval abstraction.

Storage layer
   :php:`SecretRepository` - Database persistence.
   :php:`VaultAdapterInterface` - Storage backend abstraction.

Security layer
   :php:`AccessControlService` - Permission checks.
   :php:`AuditLogService` - Operation logging.

.. _developer-extending:

Extending nr-vault
==================

.. _developer-custom-adapters:

Custom storage adapters
-----------------------

.. note::
   nr-vault currently includes only the **local database adapter**. External
   vault adapters (HashiCorp Vault, AWS Secrets Manager, Azure Key Vault) are
   planned for future releases. The adapter architecture below allows you to
   implement your own custom adapters in the meantime.

Implement :php:`VaultAdapterInterface` to add new storage backends:

.. code-block:: php
   :caption: EXT:my_extension/Classes/Adapter/CustomAdapter.php

   namespace MyVendor\MyExtension\Adapter;

   use Netresearch\NrVault\Adapter\VaultAdapterInterface;
   use Netresearch\NrVault\Domain\Model\Secret;

   final class CustomAdapter implements VaultAdapterInterface
   {
       public function getIdentifier(): string
       {
           return 'custom';
       }

       public function isAvailable(): bool
       {
           // Check if your backend is configured and reachable
       }

       public function store(Secret $secret): void
       {
           // Store secret in your backend
       }

       public function retrieve(string $identifier): ?Secret
       {
           // Retrieve secret from your backend
       }

       public function delete(string $identifier): void
       {
           // Delete from your backend
       }

       public function exists(string $identifier): bool
       {
           // Check if secret exists
       }

       public function list(array $filters = []): array
       {
           // List secret identifiers
       }

       public function getMetadata(string $identifier): ?array
       {
           // Get secret metadata
       }

       public function updateMetadata(string $identifier, array $metadata): void
       {
           // Update metadata
       }
   }

Register in :file:`Services.yaml`:

.. code-block:: yaml
   :caption: EXT:my_extension/Configuration/Services.yaml

   MyVendor\MyExtension\Adapter\CustomAdapter:
     tags:
       - name: nr_vault.adapter
         identifier: custom

.. _developer-custom-key-providers:

Custom master key providers
---------------------------

.. note::
   nr-vault includes three built-in master key providers: **typo3** (derives
   from TYPO3's encryption key), **file** (reads from filesystem), and **env**
   (reads from environment variable). The example below shows how to implement
   a custom provider for enterprise key management systems like HashiCorp Vault
   Transit or AWS KMS.

Implement :php:`MasterKeyProviderInterface` for custom key sources:

.. code-block:: php
   :caption: EXT:my_extension/Classes/Crypto/VaultKeyProvider.php

   namespace MyVendor\MyExtension\Crypto;

   use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;

   final class VaultKeyProvider implements MasterKeyProviderInterface
   {
       public function getIdentifier(): string
       {
           return 'hashicorp';
       }

       public function isAvailable(): bool
       {
           // Check if HashiCorp Vault is accessible
       }

       public function getMasterKey(): string
       {
           // Retrieve key from HashiCorp Vault
       }

       public function storeMasterKey(string $key): void
       {
           // Store key in HashiCorp Vault
       }

       public function generateMasterKey(): string
       {
           return random_bytes(32);
       }
   }

.. _developer-events:

Events
======

nr-vault dispatches PSR-14 events for extensibility:

SecretAccessedEvent
   Dispatched when a secret is read.

SecretCreatedEvent
   Dispatched when a new secret is created.

SecretRotatedEvent
   Dispatched when a secret is rotated with a new value.

SecretUpdatedEvent
   Dispatched when a secret value is updated (without rotation).

SecretDeletedEvent
   Dispatched when a secret is deleted.

MasterKeyRotatedEvent
   Dispatched after master key rotation completes.

Example listener:

.. code-block:: php
   :caption: EXT:my_extension/Classes/EventListener/SecretAccessLogger.php

   namespace MyVendor\MyExtension\EventListener;

   use Netresearch\NrVault\Event\SecretAccessedEvent;

   final class SecretAccessLogger
   {
       public function __invoke(SecretAccessedEvent $event): void
       {
           // Custom logging or alerting
           $identifier = $event->getIdentifier();
           $actorUid = $event->getActorUid();
       }
   }

.. _developer-testing:

Testing
=======

.. _developer-testing-setup:

Development setup
-----------------

Use DDEV for local development:

.. code-block:: bash
   :caption: Start DDEV environment

   ddev start
   ddev install-v14
   ddev vault-init

.. _developer-testing-running:

Running tests
-------------

.. code-block:: bash
   :caption: Run test suites

   # All tests
   ddev exec composer test

   # Unit tests only
   ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Unit

   # With coverage
   ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --coverage-html .Build/coverage

.. _developer-testing-quality:

Code quality
------------

.. code-block:: bash
   :caption: Run code quality tools

   # PHP-CS-Fixer
   ddev exec composer fix

   # PHPStan
   ddev exec composer stan

.. _developer-contributing:

Contributing
============

See :file:`CONTRIBUTING.md` for contribution guidelines.

1. Fork the repository.
2. Create a feature branch.
3. Write tests for your changes.
4. Ensure all tests pass.
5. Submit a pull request.

.. _developer-api-reference:

API reference
=============

.. _developer-api-vault-service:

VaultService
------------

.. php:class:: Netresearch\\NrVault\\Service\\VaultService

   Main facade for vault operations.

   .. php:method:: retrieve(string $identifier)

      Retrieve a decrypted secret value.

      :returntype: string|null

   .. php:method:: store(string $identifier, string $secret, array $options = []): void

      Create or update a secret.

   .. php:method:: exists(string $identifier): bool

      Check if a secret exists and is accessible.

   .. php:method:: delete(string $identifier, string $reason = ''): void

      Delete a secret.

   .. php:method:: rotate(string $identifier, string $newSecret, string $reason = ''): void

      Rotate a secret with a new value.

   .. php:method:: list(string $pattern = null): array

      List accessible secrets.

      :param string|null $pattern: Optional filter pattern.

   .. php:method:: getMetadata(string $identifier): array

      Get metadata for a secret.

   .. php:method:: http(): VaultHttpClientInterface

      Get the Vault HTTP Client.

.. _developer-api-encryption-service:

EncryptionService
-----------------

.. php:class:: Netresearch\\NrVault\\Crypto\\EncryptionService

   Envelope encryption implementation.

   .. php:method:: encrypt(string $plaintext, string $identifier): array

      Encrypt a value using envelope encryption.

   .. php:method:: decrypt(string $encryptedValue, string $encryptedDek, string $dekNonce, string $valueNonce, string $identifier): string

      Decrypt an envelope-encrypted value.

   .. php:method:: generateDek(): string

      Generate a new Data Encryption Key.

   .. php:method:: calculateChecksum(string $plaintext): string

      Calculate value checksum for change detection.

   .. php:method:: reEncryptDek(string $encryptedDek, string $dekNonce, string $identifier, string $oldMasterKey, string $newMasterKey): array

      Re-encrypt a DEK with a new master key (for master key rotation).
