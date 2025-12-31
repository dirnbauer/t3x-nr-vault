<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Configuration;

/**
 * Interface for site configuration vault processor.
 *
 * Allows mocking in unit tests while keeping implementation final.
 */
interface SiteConfigurationVaultProcessorInterface
{
    /**
     * Process configuration array and resolve vault references.
     *
     * @param array<string, mixed> $configuration The site configuration
     *
     * @return array<string, mixed> Configuration with vault references resolved
     */
    public function processConfiguration(array $configuration): array;
}
