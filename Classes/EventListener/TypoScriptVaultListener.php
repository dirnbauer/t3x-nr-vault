<?php

declare(strict_types=1);

namespace Netresearch\NrVault\EventListener;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Frontend\ContentObject\Event\AfterStdWrapFunctionsExecutedEvent;

/**
 * PSR-14 event listener that resolves %vault(identifier)% placeholders in TypoScript content.
 *
 * This listener fires on AfterStdWrapFunctionsExecutedEvent, which is dispatched
 * after stdWrap functions have been applied to content. This allows vault placeholders
 * to be resolved in any TypoScript content that goes through stdWrap.
 *
 * TYPO3 v14 convention: Uses #[AsEventListener] attribute for registration.
 * No Services.yaml configuration needed (autoconfigure: true handles it).
 *
 * IMPORTANT: Only secrets marked as frontend_accessible=1 will be resolved.
 * Content with vault references should disable caching or use USER_INT.
 */
#[AsEventListener(identifier: 'nr-vault/typoscript-vault')]
final readonly class TypoScriptVaultListener
{
    private const VAULT_PATTERN = '/%vault\(([^)]+)\)%/';

    public function __construct(
        private VaultServiceInterface $vaultService,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(AfterStdWrapFunctionsExecutedEvent $event): void
    {
        $content = $event->getContent();

        // Quick check - skip if no vault references
        if (!is_string($content) || !str_contains($content, '%vault(')) {
            return;
        }

        $resolved = $this->resolveVaultReferences($content);
        $event->setContent($resolved);
    }

    /**
     * Resolve all vault references in the content.
     */
    private function resolveVaultReferences(string $content): string
    {
        return (string) preg_replace_callback(
            self::VAULT_PATTERN,
            fn (array $matches): string => $this->resolveIdentifier($matches[1]) ?? $matches[0],
            $content
        );
    }

    /**
     * Resolve a single vault identifier to its secret value.
     *
     * Returns null on failure, which causes the original placeholder to be preserved.
     */
    private function resolveIdentifier(string $identifier): ?string
    {
        try {
            return $this->vaultService->retrieve($identifier);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to resolve vault reference in TypoScript', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
