<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Event;

/**
 * Event dispatched when a secret is accessed (read).
 *
 * This event allows listeners to react to secret access,
 * e.g., for additional monitoring, rate limiting checks, or alerts.
 */
final readonly class SecretAccessedEvent
{
    public function __construct(
        private string $identifier,
        private int $actorUid,
        private string $context = '',
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getActorUid(): int
    {
        return $this->actorUid;
    }

    public function getContext(): string
    {
        return $this->context;
    }
}
