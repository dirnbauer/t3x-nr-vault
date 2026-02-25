<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace MyVendor\MyDeeplExtension\Domain\Dto;

final readonly class DeepLConfig
{
    public function __construct(
        public int $uid,
        public string $name,
        public string $apiKey,  // Vault UUID
        public string $apiUrl,
    ) {}

    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            uid: (int) $row['uid'],
            name: (string) $row['name'],
            apiKey: (string) $row['api_key'],
            apiUrl: (string) $row['api_url'],
        );
    }
}
