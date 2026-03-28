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

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;

/**
 * Factory for creating VaultHttpClient instances via dependency injection.
 *
 * This factory eliminates the direct instantiation of VaultHttpClient inside VaultService,
 * making the dependency explicit and testable.
 */
final readonly class VaultHttpClientFactory implements VaultHttpClientFactoryInterface
{
    public function __construct(
        private AuditLogServiceInterface $auditLogService,
        private SecureHttpClientFactory $secureHttpClientFactory,
    ) {}

    /**
     * Create a new VaultHttpClient for the given vault service.
     */
    public function create(VaultServiceInterface $vaultService): VaultHttpClientInterface
    {
        return new VaultHttpClient(
            $vaultService,
            $this->auditLogService,
            $this->secureHttpClientFactory->create(),
        );
    }
}
