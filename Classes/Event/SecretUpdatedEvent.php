<?php

declare(strict_types=1);

/*
 * This file is part of the "nr_vault" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Netresearch\NrVault\Event;

/**
 * Event dispatched when a secret value is updated (not rotated).
 */
final readonly class SecretUpdatedEvent
{
    public function __construct(
        private string $identifier,
        private int $newVersion,
        private int $actorUid,
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getNewVersion(): int
    {
        return $this->newVersion;
    }

    public function getActorUid(): int
    {
        return $this->actorUid;
    }
}
