<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Adapter;

use Netresearch\NrVault\Domain\Dto\SecretFilters;
use Netresearch\NrVault\Domain\Model\Secret;

/**
 * Interface for vault storage adapters.
 */
interface VaultAdapterInterface
{
    /**
     * Get the adapter identifier.
     *
     * @return string e.g., "local", "hashicorp", "aws"
     */
    public function getIdentifier(): string;

    /**
     * Check if the adapter is available and configured.
     */
    public function isAvailable(): bool;

    /**
     * Store a secret.
     */
    public function store(Secret $secret): void;

    /**
     * Retrieve a secret by identifier.
     */
    public function retrieve(string $identifier): ?Secret;

    /**
     * Delete a secret.
     */
    public function delete(string $identifier): void;

    /**
     * Check if secret exists.
     */
    public function exists(string $identifier): bool;

    /**
     * List all secret identifiers.
     *
     * @return string[]
     */
    public function list(?SecretFilters $filters = null): array;

    /**
     * Get metadata for a secret without decrypting value.
     *
     * @return array<string, mixed>|null
     */
    public function getMetadata(string $identifier): ?array;

    /**
     * Update metadata without changing the secret value.
     *
     * @param array<string, mixed> $metadata
     */
    public function updateMetadata(string $identifier, array $metadata): void;
}
