<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Event;

use Netresearch\NrVault\Domain\Model\Secret;

/**
 * Event dispatched when a new secret is created.
 *
 * This event allows listeners to react to secret creation,
 * e.g., for notifications, additional logging, or integration triggers.
 */
final readonly class SecretCreatedEvent
{
    public function __construct(
        private string $identifier,
        private Secret $secret,
        private int $actorUid,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getSecret(): Secret
    {
        return $this->secret;
    }

    public function getActorUid(): int
    {
        return $this->actorUid;
    }
}
