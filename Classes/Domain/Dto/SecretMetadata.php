<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Domain\Dto;

/**
 * Data Transfer Object for secret metadata.
 *
 * Replaces array returns from VaultServiceInterface::list()
 * for type-safe secret metadata handling.
 */
readonly class SecretMetadata
{
    /**
     * @param array<string, mixed> $metadata Custom metadata from the secret
     */
    public function __construct(
        public string $identifier,
        public int $ownerUid,
        public int $createdAt,
        public int $updatedAt,
        public int $readCount,
        public ?int $lastReadAt,
        public string $description,
        public int $version,
        public array $metadata = [],
    ) {}

    /**
     * Create from database row array.
     *
     * @param array{identifier: string, owner_uid?: int, crdate?: int, tstamp?: int, read_count?: int, last_read_at?: int|null, description?: string, version?: int, metadata?: array<string, mixed>} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            identifier: $row['identifier'],
            ownerUid: $row['owner_uid'] ?? 0,
            createdAt: $row['crdate'] ?? 0,
            updatedAt: $row['tstamp'] ?? 0,
            readCount: $row['read_count'] ?? 0,
            lastReadAt: $row['last_read_at'] ?? null,
            description: $row['description'] ?? '',
            version: $row['version'] ?? 1,
            metadata: $row['metadata'] ?? [],
        );
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{identifier: string, owner_uid: int, crdate: int, tstamp: int, read_count: int, last_read_at: int|null, description: string, version: int, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'owner_uid' => $this->ownerUid,
            'crdate' => $this->createdAt,
            'tstamp' => $this->updatedAt,
            'read_count' => $this->readCount,
            'last_read_at' => $this->lastReadAt,
            'description' => $this->description,
            'version' => $this->version,
            'metadata' => $this->metadata,
        ];
    }
}
