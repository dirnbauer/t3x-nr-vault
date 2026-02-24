<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

use JsonSerializable;

/**
 * Interface for audit log context data.
 *
 * Context objects provide structured, type-safe metadata for audit entries.
 * Implementations must be serializable to JSON for database storage.
 */
interface AuditContextInterface extends JsonSerializable
{
    /**
     * Convert context to array for storage/export.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array;
}
