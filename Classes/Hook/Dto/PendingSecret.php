<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Hook\Dto;

/**
 * Data Transfer Object for pending secret storage in DataHandler hooks.
 *
 * Replaces array{value: string, identifier: string, originalChecksum: string, isNew: bool}
 * for type-safe pending secret handling during record save operations.
 */
readonly class PendingSecret
{
    public function __construct(
        public string $value,
        public string $identifier,
        public string $originalChecksum,
        public bool $isNew,
    ) {}

    /**
     * Create a new pending secret (not yet stored).
     */
    public static function createNew(string $value, string $identifier): self
    {
        return new self(
            value: $value,
            identifier: $identifier,
            originalChecksum: '',
            isNew: true,
        );
    }

    /**
     * Create an updated pending secret (already exists).
     */
    public static function createUpdate(string $value, string $identifier, string $originalChecksum): self
    {
        return new self(
            value: $value,
            identifier: $identifier,
            originalChecksum: $originalChecksum,
            isNew: false,
        );
    }

    /**
     * Check if the value has changed from the original.
     */
    public function hasChanged(string $currentChecksum): bool
    {
        return $this->originalChecksum !== $currentChecksum;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array{value: string, identifier: string, originalChecksum: string, isNew: bool}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'identifier' => $this->identifier,
            'originalChecksum' => $this->originalChecksum,
            'isNew' => $this->isNew,
        ];
    }
}
