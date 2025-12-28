.. include:: /Includes.rst.txt

=========
Developer
=========

.. toctree::
   :maxdepth: 2

   Api
   Commands

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
   :php:`StorageAdapterInterface` - External storage abstraction.

Security layer
   :php:`AccessControlService` - Permission checks.
   :php:`AuditLogService` - Operation logging.

Extending nr-vault
==================

Custom storage adapters
-----------------------

Implement :php:`StorageAdapterInterface` to add new storage backends:

.. code-block:: php

   namespace MyVendor\MyExtension\Storage;

   use Netresearch\NrVault\Storage\StorageAdapterInterface;

   final class CustomStorageAdapter implements StorageAdapterInterface
   {
       public function store(string $identifier, array $data): void
       {
           // Store encrypted data in your backend
       }

       public function retrieve(string $identifier): ?array
       {
           // Retrieve encrypted data from your backend
       }

       public function delete(string $identifier): void
       {
           // Delete from your backend
       }

       public function list(?string $context = null): array
       {
           // List available secrets
       }
   }

Register in :file:`Services.yaml`:

.. code-block:: yaml

   MyVendor\MyExtension\Storage\CustomStorageAdapter:
     tags:
       - name: nr_vault.storage_adapter
         identifier: custom

Custom master key providers
---------------------------

Implement :php:`MasterKeyProviderInterface` for custom key sources:

.. code-block:: php

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

Events
======

nr-vault dispatches PSR-14 events for extensibility:

SecretAccessedEvent
   Dispatched when a secret is read.

SecretCreatedEvent
   Dispatched when a new secret is created.

SecretUpdatedEvent
   Dispatched when a secret value is updated.

SecretDeletedEvent
   Dispatched when a secret is deleted.

MasterKeyRotatedEvent
   Dispatched after master key rotation completes.

Example listener:

.. code-block:: php

   namespace MyVendor\MyExtension\EventListener;

   use Netresearch\NrVault\Event\SecretAccessedEvent;

   final class SecretAccessLogger
   {
       public function __invoke(SecretAccessedEvent $event): void
       {
           // Custom logging or alerting
           $identifier = $event->getIdentifier();
           $actor = $event->getActor();
       }
   }

Testing
=======

Development setup
-----------------

Use DDEV for local development:

.. code-block:: bash

   ddev start
   ddev install-v14
   ddev vault-init

Running tests
-------------

.. code-block:: bash

   # All tests
   ddev exec composer test

   # Unit tests only
   ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --testsuite Unit

   # With coverage
   ddev exec .Build/bin/phpunit -c Tests/Build/phpunit.xml --coverage-html .Build/coverage

Code quality
------------

.. code-block:: bash

   # PHP-CS-Fixer
   ddev exec composer fix

   # PHPStan
   ddev exec composer stan

Contributing
============

See :file:`CONTRIBUTING.md` for contribution guidelines.

1. Fork the repository
2. Create a feature branch
3. Write tests for your changes
4. Ensure all tests pass
5. Submit a pull request

API reference
=============

VaultService
------------

.. php:class:: Netresearch\\NrVault\\Service\\VaultService

   Main facade for vault operations.

   .. php:method:: get(string $identifier): string

      Retrieve a decrypted secret value.

   .. php:method:: set(string $identifier, string $value, array $options = []): void

      Create or update a secret.

   .. php:method:: exists(string $identifier): bool

      Check if a secret exists and is accessible.

   .. php:method:: delete(string $identifier): void

      Delete a secret.

   .. php:method:: list(?string $context = null): array

      List accessible secrets.

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
