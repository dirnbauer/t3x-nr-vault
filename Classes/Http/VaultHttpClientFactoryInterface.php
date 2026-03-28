<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http;

use Netresearch\NrVault\Service\VaultServiceInterface;

/**
 * Factory interface for creating VaultHttpClient instances.
 */
interface VaultHttpClientFactoryInterface
{
    /**
     * Create a new VaultHttpClient for the given vault service.
     */
    public function create(VaultServiceInterface $vaultService): VaultHttpClientInterface;
}
