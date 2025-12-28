<?php

declare(strict_types=1);

/*
 * This file is part of the "nr_vault" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
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
