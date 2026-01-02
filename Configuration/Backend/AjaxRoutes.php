<?php

declare(strict_types=1);

use Netresearch\NrVault\Controller\AjaxController;

/**
 * AJAX routes for vault backend operations.
 *
 * These routes are accessible via TYPO3.settings.ajaxUrls['route_name']
 * in JavaScript.
 */
return [
    // Reveal a secret value (for FormEngine and list view)
    'vault_reveal' => [
        'path' => '/vault/reveal',
        'target' => AjaxController::class . '::revealAction',
    ],

    // Rotate a secret value (for list view modal)
    'vault_rotate' => [
        'path' => '/vault/rotate',
        'methods' => ['POST'],
        'target' => AjaxController::class . '::rotateAction',
    ],
];
