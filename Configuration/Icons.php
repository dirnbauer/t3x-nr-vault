<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

/**
 * Icon registry configuration.
 */
return [
    'module-vault' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_vault/Resources/Public/Icons/module-vault.svg',
    ],
    'vault-secret' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:nr_vault/Resources/Public/Icons/vault-secret.svg',
    ],
];
