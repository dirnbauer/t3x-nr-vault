<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Event;

/**
 * Event dispatched when a secret is deleted.
 *
 * This event allows listeners to react to secret deletion,
 * e.g., to clean up dependent resources, notify systems, or update caches.
 */
final readonly class SecretDeletedEvent
{
    public function __construct(
        private string $identifier,
        private int $actorUid,
        private string $reason = '',
    ) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getActorUid(): int
    {
        return $this->actorUid;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
