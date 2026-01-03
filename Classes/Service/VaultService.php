<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use DateTimeInterface;
use Netresearch\NrVault\Adapter\VaultAdapterInterface;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Event\SecretAccessedEvent;
use Netresearch\NrVault\Event\SecretCreatedEvent;
use Netresearch\NrVault\Event\SecretDeletedEvent;
use Netresearch\NrVault\Event\SecretRotatedEvent;
use Netresearch\NrVault\Event\SecretUpdatedEvent;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Http\AuthenticatedPsr18Client;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Security\AccessControlServiceInterface;
use Netresearch\NrVault\Utility\IdentifierValidator;
use GuzzleHttp\Client;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Main vault service implementation.
 */
final class VaultService implements VaultServiceInterface, SingletonInterface
{
    /** @var array<string, string> Request-scoped cache */
    private array $cache = [];

    public function __construct(
        private readonly VaultAdapterInterface $adapter,
        private readonly EncryptionServiceInterface $encryptionService,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly AuditLogServiceInterface $auditLogService,
        private readonly ExtensionConfigurationInterface $configuration,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
    ) {}

    public function store(string $identifier, string $secret, array $options = []): void
    {
        // Validate identifier
        IdentifierValidator::validate($identifier);

        if ($secret === '') {
            throw ValidationException::emptySecret();
        }

        try {
            // Encrypt the secret
            $encrypted = $this->encryptionService->encrypt($secret, $identifier);

            // Build secret entity
            $secretEntity = new Secret();
            $secretEntity->setIdentifier($identifier);
            $secretEntity->setEncryptedValue($encrypted['encrypted_value']);
            $secretEntity->setEncryptedDek($encrypted['encrypted_dek']);
            $secretEntity->setDekNonce($encrypted['dek_nonce']);
            $secretEntity->setValueNonce($encrypted['value_nonce']);
            $secretEntity->setValueChecksum($encrypted['value_checksum']);
            $secretEntity->setAdapter('local');

            // Apply options
            if (isset($options['owner'])) {
                $secretEntity->setOwnerUid((int) $options['owner']);
            } else {
                $secretEntity->setOwnerUid($this->accessControlService->getCurrentActorUid());
            }

            if (isset($options['groups'])) {
                $secretEntity->setAllowedGroups((array) $options['groups']);
            }

            if (isset($options['context'])) {
                $secretEntity->setContext((string) $options['context']);
            }

            if (isset($options['description'])) {
                $secretEntity->setDescription((string) $options['description']);
            }

            if (isset($options['metadata'])) {
                $secretEntity->setMetadata((array) $options['metadata']);
            }

            if (isset($options['scopePid'])) {
                $secretEntity->setScopePid((int) $options['scopePid']);
            }

            if (isset($options['expiresAt'])) {
                $expiresAt = $options['expiresAt'];
                if ($expiresAt instanceof DateTimeInterface) {
                    $expiresAt = $expiresAt->getTimestamp();
                }
                $secretEntity->setExpiresAt((int) $expiresAt);
            }

            if (isset($options['frontendAccessible'])) {
                $secretEntity->setFrontendAccessible((bool) $options['frontendAccessible']);
            }

            // Check if updating existing
            $existing = $this->adapter->retrieve($identifier);
            $isNew = !$existing instanceof Secret;

            if (!$isNew) {
                $secretEntity->setUid($existing->getUid());
                $secretEntity->setCrdate($existing->getCrdate());
                $secretEntity->setVersion($existing->getVersion());
            } else {
                $secretEntity->setCrdate(time());
                $secretEntity->setCruserId($this->accessControlService->getCurrentActorUid());
            }

            // Store the secret
            $this->adapter->store($secretEntity);

            // Log the action
            $this->auditLogService->log(
                $identifier,
                $isNew ? 'create' : 'update',
                true,
                null,
                null,
                $isNew ? null : $existing->getValueChecksum(),
                $encrypted['value_checksum'],
            );

            // Dispatch PSR-14 event
            if ($isNew) {
                $this->eventDispatcher?->dispatch(new SecretCreatedEvent(
                    $identifier,
                    $secretEntity,
                    $this->accessControlService->getCurrentActorUid(),
                ));
            } else {
                $this->eventDispatcher?->dispatch(new SecretUpdatedEvent(
                    $identifier,
                    $secretEntity->getVersion(),
                    $this->accessControlService->getCurrentActorUid(),
                ));
            }

            // Clear cache
            unset($this->cache[$identifier]);
        } finally {
            // Securely wipe the plaintext even if an exception occurred
            sodium_memzero($secret);
        }
    }

    public function retrieve(string $identifier): ?string
    {
        // Check request-scoped cache
        if ($this->configuration->isCacheEnabled() && isset($this->cache[$identifier])) {
            return $this->cache[$identifier];
        }

        $secret = $this->adapter->retrieve($identifier);
        if (!$secret instanceof Secret) {
            return null;
        }

        // Check access
        if (!$this->accessControlService->canRead($secret)) {
            $this->auditLogService->log($identifier, 'access_denied', false, 'Read access denied');

            throw AccessDeniedException::forIdentifier($identifier, 'insufficient permissions');
        }

        // Check expiration
        if ($secret->isExpired()) {
            $this->auditLogService->log($identifier, 'read', false, 'Secret has expired');

            throw SecretExpiredException::forIdentifier($identifier, $secret->getExpiresAt());
        }

        // Decrypt
        try {
            $plaintext = $this->encryptionService->decrypt(
                $secret->getEncryptedValue() ?? '',
                $secret->getEncryptedDek(),
                $secret->getDekNonce(),
                $secret->getValueNonce(),
                $identifier,
            );
        } catch (EncryptionException $e) {
            $this->auditLogService->log($identifier, 'read', false, 'Decryption failed: ' . $e->getMessage());

            throw $e;
        }

        // Update read statistics
        $secret->incrementReadCount();
        $secret->setLastReadAt(time());
        $this->adapter->store($secret);

        // Log success
        $this->auditLogService->log($identifier, 'read', true);

        // Dispatch PSR-14 event
        $this->eventDispatcher?->dispatch(new SecretAccessedEvent(
            $identifier,
            $this->accessControlService->getCurrentActorUid(),
            $secret->getContext(),
        ));

        // Cache for this request
        if ($this->configuration->isCacheEnabled()) {
            $this->cache[$identifier] = $plaintext;
        }

        return $plaintext;
    }

    public function exists(string $identifier): bool
    {
        return $this->adapter->exists($identifier);
    }

    public function delete(string $identifier, string $reason = ''): void
    {
        $secret = $this->adapter->retrieve($identifier);
        if (!$secret instanceof Secret) {
            throw SecretNotFoundException::forIdentifier($identifier);
        }

        // Check access
        if (!$this->accessControlService->canDelete($secret)) {
            $this->auditLogService->log($identifier, 'access_denied', false, 'Delete access denied');

            throw AccessDeniedException::forIdentifier($identifier, 'delete permission denied');
        }

        $hashBefore = $secret->getValueChecksum();

        // Delete
        $this->adapter->delete($identifier);

        // Log
        $this->auditLogService->log(
            $identifier,
            'delete',
            true,
            null,
            $reason,
            $hashBefore,
        );

        // Dispatch PSR-14 event
        $this->eventDispatcher?->dispatch(new SecretDeletedEvent(
            $identifier,
            $this->accessControlService->getCurrentActorUid(),
            $reason,
        ));

        // Clear cache
        unset($this->cache[$identifier]);
    }

    public function rotate(string $identifier, string $newSecret, string $reason = ''): void
    {
        $secret = $this->adapter->retrieve($identifier);
        if (!$secret instanceof Secret) {
            throw SecretNotFoundException::forIdentifier($identifier);
        }

        // Check access
        if (!$this->accessControlService->canWrite($secret)) {
            $this->auditLogService->log($identifier, 'access_denied', false, 'Rotate access denied');

            throw AccessDeniedException::forIdentifier($identifier, 'rotate permission denied');
        }

        if ($newSecret === '') {
            throw ValidationException::emptySecret();
        }

        try {
            $hashBefore = $secret->getValueChecksum();

            // Encrypt the new secret
            $encrypted = $this->encryptionService->encrypt($newSecret, $identifier);

            // Update secret
            $secret->setEncryptedValue($encrypted['encrypted_value']);
            $secret->setEncryptedDek($encrypted['encrypted_dek']);
            $secret->setDekNonce($encrypted['dek_nonce']);
            $secret->setValueNonce($encrypted['value_nonce']);
            $secret->setValueChecksum($encrypted['value_checksum']);
            $secret->incrementVersion();
            $secret->setLastRotatedAt(time());

            // Store
            $this->adapter->store($secret);

            // Log
            $this->auditLogService->log(
                $identifier,
                'rotate',
                true,
                null,
                $reason,
                $hashBefore,
                $encrypted['value_checksum'],
            );

            // Dispatch PSR-14 event
            $this->eventDispatcher?->dispatch(new SecretRotatedEvent(
                $identifier,
                $secret->getVersion(),
                $this->accessControlService->getCurrentActorUid(),
                $reason,
            ));

            // Clear cache
            unset($this->cache[$identifier]);
        } finally {
            // Securely wipe the plaintext even if an exception occurred
            sodium_memzero($newSecret);
        }
    }

    public function list(?string $pattern = null): array
    {
        $filters = [];
        if ($pattern !== null) {
            $filters['pattern'] = $pattern;
        }

        $identifiers = $this->adapter->list($filters);

        // Build metadata array for accessible secrets
        $secrets = [];
        foreach ($identifiers as $identifier) {
            $secret = $this->adapter->retrieve($identifier);
            if (!$secret instanceof Secret) {
                continue;
            }

            // Check access
            if (!$this->accessControlService->canRead($secret)) {
                continue;
            }

            $secrets[] = [
                'identifier' => $secret->getIdentifier(),
                'owner_uid' => $secret->getOwnerUid(),
                'crdate' => $secret->getCrdate(),
                'tstamp' => $secret->getTstamp(),
                'read_count' => $secret->getReadCount(),
                'last_read_at' => $secret->getLastReadAt(),
                'description' => $secret->getDescription(),
                'version' => $secret->getVersion(),
                'hidden' => $secret->isHidden(),
            ];
        }

        return $secrets;
    }

    public function getMetadata(string $identifier): array
    {
        $secret = $this->adapter->retrieve($identifier);
        if (!$secret instanceof Secret) {
            throw SecretNotFoundException::forIdentifier($identifier);
        }

        // Check access
        if (!$this->accessControlService->canRead($secret)) {
            $this->auditLogService->log($identifier, 'access_denied', false, 'Metadata access denied');

            throw AccessDeniedException::forIdentifier($identifier, 'insufficient permissions');
        }

        return [
            'uid' => $secret->getUid(),
            'identifier' => $secret->getIdentifier(),
            'description' => $secret->getDescription(),
            'owner' => $secret->getOwnerUid(),
            'owner_uid' => $secret->getOwnerUid(),
            'groups' => $secret->getAllowedGroups(),
            'context' => $secret->getContext(),
            'frontend_accessible' => $secret->isFrontendAccessible(),
            'version' => $secret->getVersion(),
            'createdAt' => $secret->getCrdate(),
            'updatedAt' => $secret->getTstamp(),
            'expiresAt' => $secret->getExpiresAt() ?: null,
            'expires_at' => $secret->getExpiresAt() ?: null,
            'lastRotatedAt' => $secret->getLastRotatedAt() ?: null,
            'metadata' => $secret->getMetadata(),
            'scopePid' => $secret->getScopePid(),
        ];
    }

    public function http(): VaultHttpClientInterface
    {
        return new VaultHttpClient(
            $this,
            $this->auditLogService,
        );
    }

    public function createAuthenticatedClient(
        string $secretIdentifier,
        SecretPlacement $placement = SecretPlacement::Bearer,
        array $options = [],
    ): ClientInterface {
        $innerClient = $options['client'] ?? new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        return new AuthenticatedPsr18Client(
            vaultService: $this,
            innerClient: $innerClient,
            secretIdentifier: $secretIdentifier,
            placement: $placement,
            headerName: $options['headerName'] ?? null,
            queryParam: $options['queryParam'] ?? null,
            usernameSecretIdentifier: $options['usernameSecret'] ?? null,
        );
    }

    /**
     * Clear the request-scoped cache.
     */
    public function clearCache(): void
    {
        // Securely wipe cached values
        foreach ($this->cache as $key => $value) {
            sodium_memzero($value);
            unset($this->cache[$key]);
        }
        $this->cache = [];
    }
}
