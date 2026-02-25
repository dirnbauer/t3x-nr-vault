<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Exception\ConfigurationException;

/**
 * Interface for master key provider factory.
 */
interface MasterKeyProviderFactoryInterface
{
    /**
     * Create a master key provider based on configuration.
     *
     * @throws ConfigurationException If provider is invalid
     */
    public function create(): MasterKeyProviderInterface;

    /**
     * Get the configured provider, falling back to auto-detection.
     */
    public function getAvailableProvider(): MasterKeyProviderInterface;
}
