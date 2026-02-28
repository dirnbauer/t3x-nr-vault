<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Netresearch\NrVault\Controller\AuditController;
use Netresearch\NrVault\Controller\MigrationController;
use Netresearch\NrVault\Controller\OverviewController;
use Netresearch\NrVault\Controller\SecretsController;

/**
 * Backend module configuration for nr_vault.
 *
 * Parent module with submodules (following TYPO3 styleguide pattern).
 * - Parent shows submodule overview with cards
 * - Submodule selector appears in DocHeader
 *
 * Uses LLL:EXT: label format (compatible with TYPO3 v13+v14)
 */
return [
    // Parent module - custom overview with usage information
    // dependsOnSubmodules: true enables the submodule dropdown in DocHeader
    // showSubmoduleOverview: true prevents redirect to last-used submodule
    'admin_vault' => [
        'parent' => 'admin',
        'position' => ['after' => 'admin_sites'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/overview.xlf',
        'iconIdentifier' => 'module-vault',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        // v14+: Show overview page for parent module
        'showSubmoduleOverview' => true,
        'routes' => [
            '_default' => [
                'target' => OverviewController::class . '::indexAction',
            ],
        ],
    ],

    // Secrets submodule
    'admin_vault_secrets' => [
        'parent' => 'admin_vault',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/secrets',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/secrets.xlf',
        'routes' => [
            '_default' => [
                'target' => SecretsController::class . '::listAction',
            ],
            'create' => [
                'target' => SecretsController::class . '::createAction',
            ],
            'edit' => [
                'target' => SecretsController::class . '::editAction',
            ],
            'toggle' => [
                'target' => SecretsController::class . '::toggleAction',
                'methods' => ['POST'],
            ],
            'delete' => [
                'target' => SecretsController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
        ],
    ],

    // Audit submodule
    'admin_vault_audit' => [
        'parent' => 'admin_vault',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/audit',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/audit.xlf',
        'routes' => [
            '_default' => [
                'target' => AuditController::class . '::listAction',
            ],
            'export' => [
                'target' => AuditController::class . '::exportAction',
            ],
            'verifyChain' => [
                'target' => AuditController::class . '::verifyChainAction',
            ],
        ],
    ],

    // Migration wizard submodule
    // Uses handleRequest pattern like TYPO3 core - dispatches based on ?action= query param
    'admin_vault_migration' => [
        'parent' => 'admin_vault',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/migration',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/migration.xlf',
        'routes' => [
            '_default' => [
                'target' => MigrationController::class . '::handleRequest',
            ],
        ],
    ],
];
