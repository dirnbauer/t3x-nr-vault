<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Domain\Dto;

/**
 * Data Transfer Object for secret filtering criteria.
 *
 * Replaces array{owner?: int, prefix?: string, context?: string, scopePid?: int}
 * for type-safe filtering in repository and adapter layers.
 */
readonly class SecretFilters
{
    public function __construct(
        public ?int $owner = null,
        public ?string $prefix = null,
        public ?string $context = null,
        public ?int $scopePid = null,
    ) {}

    /**
     * Create from array (for backwards compatibility).
     *
     * @param array{owner?: int, prefix?: string, context?: string, scopePid?: int} $filters
     */
    public static function fromArray(array $filters): self
    {
        return new self(
            owner: $filters['owner'] ?? null,
            prefix: $filters['prefix'] ?? null,
            context: $filters['context'] ?? null,
            scopePid: $filters['scopePid'] ?? null,
        );
    }

    /**
     * Check if any filter is set.
     */
    public function hasFilters(): bool
    {
        return $this->owner !== null
            || $this->prefix !== null
            || $this->context !== null
            || $this->scopePid !== null;
    }

    /**
     * Convert to array for legacy APIs.
     *
     * @return array{owner?: int, prefix?: string, context?: string, scopePid?: int}
     */
    public function toArray(): array
    {
        $result = [];
        if ($this->owner !== null) {
            $result['owner'] = $this->owner;
        }
        if ($this->prefix !== null) {
            $result['prefix'] = $this->prefix;
        }
        if ($this->context !== null) {
            $result['context'] = $this->context;
        }
        if ($this->scopePid !== null) {
            $result['scopePid'] = $this->scopePid;
        }

        return $result;
    }
}
