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
 * Parent module with internal hidden sibling routes.
 * - Parent is a normal visible module in the main navigation
 * - Internal route modules keep existing route identifiers without rendering
 *   in the main module navigation
 *
 * Uses 'tools' as parent for v13+v14 compatibility:
 * - v13: 'tools' exists natively as the admin tools group
 * - v14: 'tools' is an alias for the new 'admin' group
 *
 * Uses LLL:EXT: label format (compatible with TYPO3 v13+v14)
 *
 * Existing route identifiers stay unchanged for controller and template links.
 */
return [
    // Parent module - custom overview with usage information
    'admin_vault' => [
        'parent' => 'tools',
        'position' => ['after' => 'admin_sites'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/overview.xlf',
        'iconIdentifier' => 'module-vault',
        'routes' => [
            '_default' => [
                'target' => OverviewController::class . '::indexAction',
            ],
            'help' => [
                'target' => OverviewController::class . '::helpAction',
            ],
        ],
    ],

    // Hidden route module for direct links to the overview.
    'admin_vault_overview' => [
        'parent' => 'tools',
        'position' => ['after' => 'admin_vault'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/overview',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/overview_submodule.xlf',
        'iconIdentifier' => 'module-vault',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'routes' => [
            '_default' => [
                'target' => OverviewController::class . '::indexAction',
            ],
            'help' => [
                'target' => OverviewController::class . '::helpAction',
            ],
        ],
    ],

    // Secrets - hidden sibling route module
    'admin_vault_secrets' => [
        'parent' => 'tools',
        'position' => ['after' => 'admin_vault_overview'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/secrets',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/secrets.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
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

    // Audit - hidden sibling route module
    'admin_vault_audit' => [
        'parent' => 'tools',
        'position' => ['after' => 'admin_vault_secrets'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/audit',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/audit.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
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

    // Migration wizard - hidden sibling route module
    // Uses handleRequest pattern like TYPO3 core - dispatches based on ?action= query param
    'admin_vault_migration' => [
        'parent' => 'tools',
        'position' => ['after' => 'admin_vault_audit'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/migration',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/Modules/migration.xlf',
        'appearance' => [
            'renderInModuleMenu' => false,
        ],
        'routes' => [
            '_default' => [
                'target' => MigrationController::class . '::handleRequest',
            ],
        ],
    ],
];
