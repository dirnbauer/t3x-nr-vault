<?php

declare(strict_types=1);

/**
 * JavaScript ES6 module configuration for TYPO3 v14.
 *
 * @see https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/ApiOverview/Backend/JavaScript/ES6/Index.html
 */
return [
    'dependencies' => [
        'backend',
    ],
    'imports' => [
        '@netresearch/nr-vault/' => 'EXT:nr_vault/Resources/Public/JavaScript/',
    ],
];
