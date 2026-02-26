<?php

declare(strict_types=1);

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Netresearch\NrVault\Event;

use DateTimeImmutable;

/**
 * Event dispatched after master key rotation completes.
 */
final readonly class MasterKeyRotatedEvent
{
    public function __construct(
        private int $secretsReEncrypted,
        private int $actorUid,
        private DateTimeImmutable $rotatedAt,
    ) {}

    public function getSecretsReEncrypted(): int
    {
        return $this->secretsReEncrypted;
    }

    public function getActorUid(): int
    {
        return $this->actorUid;
    }

    public function getRotatedAt(): DateTimeImmutable
    {
        return $this->rotatedAt;
    }
}
