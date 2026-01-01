<?php

declare(strict_types=1);

use Netresearch\NrVault\Form\Element\VaultSecretElement;
use Netresearch\NrVault\Hook\DataHandlerHook;
use Netresearch\NrVault\Hook\FlexFormVaultHook;
use Netresearch\NrVault\Hook\SecretTcaHook;

defined('TYPO3') || die();

(static function (): void {
    // Register vaultSecret form element type
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1735400000] = [
        'nodeName' => 'vaultSecret',
        'priority' => 40,
        'class' => VaultSecretElement::class,
    ];

    // DataHandler hooks for TCA vaultSecret field operations
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = DataHandlerHook::class;

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
        = DataHandlerHook::class;

    // FlexForm vault field handling
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = FlexFormVaultHook::class;

    // Hook for tx_nrvault_secret TCA operations (identifier immutability, audit logging)
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][]
        = SecretTcaHook::class;

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
        = SecretTcaHook::class;
})();
