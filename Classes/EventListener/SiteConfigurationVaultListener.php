<?php

declare(strict_types=1);

namespace Netresearch\NrVault\EventListener;

use Netresearch\NrVault\Configuration\SiteConfigurationVaultProcessorInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

/**
 * Event listener that resolves vault references in site configuration.
 *
 * Automatically processes %vault(identifier)% references when site
 * configuration is loaded, replacing them with actual secret values.
 *
 * This listener fires on SiteConfigurationLoadedEvent, which is dispatched
 * when TYPO3 loads site configuration from YAML files.
 */
#[AsEventListener(identifier: 'nr-vault/site-configuration-vault')]
final readonly class SiteConfigurationVaultListener
{
    public function __construct(
        private SiteConfigurationVaultProcessorInterface $processor,
    ) {}

    public function __invoke(SiteConfigurationLoadedEvent $event): void
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $event->getConfiguration();

        // Only process if there might be vault references
        if (!$this->containsVaultReferences($configuration)) {
            return;
        }

        // Process the configuration to resolve vault references
        // Note: We don't have access to Site object at this point,
        // but we can use the site identifier from the configuration
        $processedConfiguration = $this->processor->processConfiguration($configuration);

        $event->setConfiguration($processedConfiguration);
    }

    /**
     * Quick check if configuration might contain vault references.
     *
     * @param array<string, mixed> $configuration
     */
    private function containsVaultReferences(array $configuration): bool
    {
        $json = json_encode($configuration);

        return $json !== false && str_contains($json, '%vault(');
    }
}
