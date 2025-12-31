<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Backend module controller for vault overview/dashboard.
 *
 * Shows summary statistics and quick links to submodules.
 */
#[AsController]
final class OverviewController
{
    private const MODULE_NAME = 'admin_vault';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly VaultServiceInterface $vaultService,
    ) {}

    /**
     * Display vault overview with submodule cards and usage information.
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: self::MODULE_NAME,
            displayName: $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'),
        );

        // Get statistics for the overview
        $stats = $this->getVaultStatistics();

        $moduleTemplate->assignMultiple([
            'stats' => $stats,
            'submodules' => [
                [
                    'route' => 'admin_vault_secrets',
                    'icon' => 'content-elements-login',
                    'title' => 'Secrets',
                    'description' => 'Manage encrypted secrets stored in the vault.',
                ],
                [
                    'route' => 'admin_vault_audit',
                    'icon' => 'actions-document-history-open',
                    'title' => 'Audit Log',
                    'description' => 'View access logs and verify audit chain integrity.',
                ],
                [
                    'route' => 'admin_vault_migration',
                    'icon' => 'actions-database-import',
                    'title' => 'Migration Wizard',
                    'description' => 'Detect and migrate plaintext secrets to the vault.',
                ],
            ],
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    /**
     * Get vault statistics for the overview.
     *
     * @return array<string, int>
     */
    private function getVaultStatistics(): array
    {
        try {
            $secrets = $this->vaultService->list();

            $totalSecrets = \count($secrets);
            $activeSecrets = \count(array_filter($secrets, static fn($s) => !($s['hidden'] ?? false)));
            $disabledSecrets = $totalSecrets - $activeSecrets;

            return [
                'totalSecrets' => $totalSecrets,
                'activeSecrets' => $activeSecrets,
                'disabledSecrets' => $disabledSecrets,
            ];
        } catch (\Exception) {
            return [
                'totalSecrets' => 0,
                'activeSecrets' => 0,
                'disabledSecrets' => 0,
            ];
        }
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
