<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace MyVendor\MyDeeplExtension\Domain\Repository;

use MyVendor\MyDeeplExtension\Domain\Dto\DeepLConfig;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ConfigRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function findDefault(): ?DeepLConfig
    {
        $row = $this->connectionPool
            ->getConnectionForTable('tx_mydeeplext_config')
            ->select(['*'], 'tx_mydeeplext_config', ['deleted' => 0])
            ->fetchAssociative();

        return $row ? DeepLConfig::fromDatabaseRow($row) : null;
    }
}
