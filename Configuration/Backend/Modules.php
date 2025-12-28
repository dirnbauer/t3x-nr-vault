<?php

declare(strict_types=1);

use Netresearch\NrVault\Controller\VaultController;

/**
 * Backend module configuration for nr_vault.
 */
return [
    'system_vault' => [
        'parent' => 'system',
        'position' => ['after' => 'system_log'],
        'access' => 'admin',
        'workspaces' => 'live',
        'path' => '/module/system/vault',
        'labels' => 'LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf',
        'iconIdentifier' => 'module-vault',
        'routes' => [
            '_default' => [
                'target' => VaultController::class . '::handleRequest',
            ],
        ],
    ],
];
