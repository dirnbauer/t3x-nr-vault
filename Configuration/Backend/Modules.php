<?php

declare(strict_types=1);

use Netresearch\NrVault\Controller\AuditController;
use Netresearch\NrVault\Controller\SecretsController;

/**
 * Backend module configuration for nr_vault.
 *
 * Parent module with submodules (following TYPO3 styleguide pattern).
 * - Parent shows submodule overview with cards
 * - Submodule selector appears in DocHeader
 *
 * Uses TYPO3 v14 short label format:
 * - 'nr_vault.modules.overview' maps to EXT:nr_vault/Resources/Private/Language/Modules/overview.xlf
 */
return [
    // Parent module - shows submodule overview
    'admin_vault' => [
        'parent' => 'admin',
        'position' => ['after' => 'admin_sites'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault',
        'labels' => 'nr_vault.modules.overview',
        'iconIdentifier' => 'module-vault',
        'appearance' => [
            'dependsOnSubmodules' => true,
        ],
        'showSubmoduleOverview' => true,
    ],

    // Secrets submodule
    'admin_vault_secrets' => [
        'parent' => 'admin_vault',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/secrets',
        'labels' => 'nr_vault.modules.secrets',
        'routes' => [
            '_default' => [
                'target' => SecretsController::class . '::listAction',
            ],
            'view' => [
                'target' => SecretsController::class . '::viewAction',
            ],
            'create' => [
                'target' => SecretsController::class . '::createAction',
            ],
            'store' => [
                'target' => SecretsController::class . '::storeAction',
                'methods' => ['POST'],
            ],
            'edit' => [
                'target' => SecretsController::class . '::editAction',
            ],
            'update' => [
                'target' => SecretsController::class . '::updateAction',
                'methods' => ['POST'],
            ],
            'reveal' => [
                'target' => SecretsController::class . '::revealAction',
            ],
            'toggle' => [
                'target' => SecretsController::class . '::toggleAction',
                'methods' => ['POST'],
            ],
            'delete' => [
                'target' => SecretsController::class . '::deleteAction',
                'methods' => ['POST'],
            ],
            'rotate' => [
                'target' => SecretsController::class . '::rotateAction',
            ],
        ],
    ],

    // Audit submodule
    'admin_vault_audit' => [
        'parent' => 'admin_vault',
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/admin/vault/audit',
        'labels' => 'nr_vault.modules.audit',
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
];
