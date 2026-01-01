<?php

declare(strict_types=1);

use a9f\Fractor\Configuration\FractorConfiguration;
use a9f\Typo3Fractor\Set\Typo3LevelSetList;

return FractorConfiguration::configure()
    ->withPaths([
        __DIR__ . '/Resources',
        __DIR__ . '/Configuration',
    ])
    ->withSkip([
        __DIR__ . '/.Build',
    ])
    ->withSets([
        // Apply all TYPO3 v14 upgrades for non-PHP files
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ]);
