<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Backend module controller for vault overview/dashboard.
 */
#[AsController]
final readonly class OverviewController
{
    private const string MODULE_NAME = 'admin_vault';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ConnectionPool $connectionPool,
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
            // Count total secrets (including hidden) - remove default restrictions
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $queryBuilder->getRestrictions()->removeAll();
            $totalResult = $queryBuilder
                ->count('uid')
                ->from('tx_nrvault_secret')
                ->where($queryBuilder->expr()->eq('deleted', 0))
                ->executeQuery()
                ->fetchOne();
            $totalSecrets = is_numeric($totalResult) ? (int) $totalResult : 0;

            // Count active secrets (not hidden) - remove default restrictions for explicit control
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $queryBuilder->getRestrictions()->removeAll();
            $activeResult = $queryBuilder
                ->count('uid')
                ->from('tx_nrvault_secret')
                ->where(
                    $queryBuilder->expr()->eq('deleted', 0),
                    $queryBuilder->expr()->eq('hidden', 0),
                )
                ->executeQuery()
                ->fetchOne();
            $activeSecrets = is_numeric($activeResult) ? (int) $activeResult : 0;

            return [
                'totalSecrets' => $totalSecrets,
                'activeSecrets' => $activeSecrets,
                'disabledSecrets' => $totalSecrets - $activeSecrets,
            ];
        } catch (Exception) {
            return [
                'totalSecrets' => 0,
                'activeSecrets' => 0,
                'disabledSecrets' => 0,
            ];
        }
    }

    private function getLanguageService(): LanguageService
    {
        /** @var LanguageService $languageService */
        $languageService = $GLOBALS['LANG'];

        return $languageService;
    }
}
