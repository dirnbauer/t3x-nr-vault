<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Adapter;

use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Domain\Repository\SecretRepository;

/**
 * Local database adapter with envelope encryption.
 */
final class LocalEncryptionAdapter implements VaultAdapterInterface
{
    public function __construct(
        private readonly SecretRepository $secretRepository,
        private readonly EncryptionServiceInterface $encryptionService,
    ) {
    }

    public function getIdentifier(): string
    {
        return 'local';
    }

    public function isAvailable(): bool
    {
        // Local adapter is always available
        return true;
    }

    public function store(Secret $secret): void
    {
        $this->secretRepository->save($secret);
    }

    public function retrieve(string $identifier): ?Secret
    {
        return $this->secretRepository->findByIdentifier($identifier);
    }

    public function delete(string $identifier): void
    {
        $secret = $this->secretRepository->findByIdentifier($identifier);
        if ($secret !== null) {
            $this->secretRepository->delete($secret);
        }
    }

    public function exists(string $identifier): bool
    {
        return $this->secretRepository->exists($identifier);
    }

    public function list(array $filters = []): array
    {
        return $this->secretRepository->findIdentifiers($filters);
    }

    public function getMetadata(string $identifier): ?array
    {
        $secret = $this->secretRepository->findByIdentifier($identifier);
        if ($secret === null) {
            return null;
        }

        return [
            'identifier' => $secret->getIdentifier(),
            'description' => $secret->getDescription(),
            'owner' => $secret->getOwnerUid(),
            'groups' => $secret->getAllowedGroups(),
            'context' => $secret->getContext(),
            'version' => $secret->getVersion(),
            'createdAt' => $secret->getCrdate(),
            'updatedAt' => $secret->getTstamp(),
            'expiresAt' => $secret->getExpiresAt() ?: null,
            'lastRotatedAt' => $secret->getLastRotatedAt() ?: null,
            'metadata' => $secret->getMetadata(),
            'adapter' => $secret->getAdapter(),
        ];
    }

    public function updateMetadata(string $identifier, array $metadata): void
    {
        $secret = $this->secretRepository->findByIdentifier($identifier);
        if ($secret === null) {
            return;
        }

        $existing = $secret->getMetadata();
        $secret->setMetadata(array_merge($existing, $metadata));
        $this->secretRepository->save($secret);
    }
}
