<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
    ])
    ->withSkip([
        // Skip vendor and build directories
        __DIR__ . '/.Build',
    ])
    ->withSets([
        // Apply all TYPO3 v14 upgrades
        Typo3LevelSetList::UP_TO_TYPO3_14,
    ])
    ->withImportNames(removeUnusedImports: true);
