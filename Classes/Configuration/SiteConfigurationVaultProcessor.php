<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Configuration;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Processor for resolving vault references in site configuration.
 *
 * Supports vault references in site configuration using the format:
 *
 *     someApiKey: '%vault(my_api_key)%'
 *     settings:
 *         secret: '%vault(site_payment_secret)%'
 *
 * The vault identifier is resolved at runtime when the configuration is accessed.
 *
 * Usage in site configuration (config/sites/<identifier>/config.yaml):
 *
 *     base: 'https://example.com/'
 *     languages: []
 *     settings:
 *         payment:
 *             apiKey: '%vault(payment_api_key)%'
 *             secret: '%vault(payment_secret)%'
 *
 * Then retrieve in code:
 *
 *     $site = $request->getAttribute('site');
 *     $processor = GeneralUtility::makeInstance(SiteConfigurationVaultProcessor::class);
 *     $config = $processor->processConfiguration($site->getConfiguration(), $site);
 *     $apiKey = $config['settings']['payment']['apiKey']; // Resolved secret value
 */
final class SiteConfigurationVaultProcessor implements SiteConfigurationVaultProcessorInterface
{
    private const VAULT_PATTERN = '/%vault\(([^)]+)\)%/';

    public function __construct(
        private readonly VaultServiceInterface $vaultService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Process site configuration and resolve vault references.
     *
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    public function processConfiguration(array $configuration, ?Site $site = null): array
    {
        return $this->resolveVaultReferences($configuration, $site);
    }

    /**
     * Process a single configuration value.
     *
     * Returns the resolved secret if the value is a vault reference,
     * or the original value otherwise.
     */
    public function processValue(mixed $value, ?Site $site = null): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        if (!$this->isVaultReference($value)) {
            return $value;
        }

        return $this->resolveVaultReference($value, $site);
    }

    /**
     * Check if a value is a vault reference.
     */
    public function isVaultReference(mixed $value): bool
    {
        return \is_string($value) && preg_match(self::VAULT_PATTERN, $value) === 1;
    }

    /**
     * Build a vault reference string for use in configuration.
     */
    public static function buildVaultReference(string $identifier): string
    {
        return \sprintf('%%vault(%s)%%', $identifier);
    }

    /**
     * Extract the vault identifier from a reference string.
     */
    public function extractIdentifier(string $reference): ?string
    {
        if (preg_match(self::VAULT_PATTERN, $reference, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $configuration
     *
     * @return array<string, mixed>
     */
    private function resolveVaultReferences(array $configuration, ?Site $site): array
    {
        $resolved = [];

        foreach ($configuration as $key => $value) {
            if (\is_array($value)) {
                $resolved[$key] = $this->resolveVaultReferences($value, $site);
            } elseif (\is_string($value) && $this->isVaultReference($value)) {
                $resolved[$key] = $this->resolveVaultReference($value, $site);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    private function resolveVaultReference(string $value, ?Site $site): mixed
    {
        $identifier = $this->extractIdentifier($value);

        if ($identifier === null) {
            return $value;
        }

        // Support site-prefixed identifiers: site:{siteIdentifier}:{secretId}
        if ($site !== null && !str_contains($identifier, ':')) {
            // Try site-specific identifier first
            $siteIdentifier = \sprintf('site:%s:%s', $site->getIdentifier(), $identifier);

            try {
                $secret = $this->vaultService->retrieve($siteIdentifier);
                if ($secret !== null) {
                    return $secret;
                }
            } catch (Throwable) {
                // Fall through to global identifier
            }
        }

        try {
            $secret = $this->vaultService->retrieve($identifier);

            return $secret ?? $value;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to resolve vault reference', [
                'identifier' => $identifier,
                'site' => $site?->getIdentifier(),
                'error' => $e->getMessage(),
            ]);

            return $value;
        }
    }
}
