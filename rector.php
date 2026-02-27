<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\ValueObject\PhpVersion;
use Ssch\TYPO3Rector\Set\Typo3LevelSetList;
use Ssch\TYPO3Rector\Set\Typo3SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/Classes',
        __DIR__ . '/Tests',
        __DIR__ . '/Configuration',
    ])
    ->withRootFiles()
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withPhpSets(php82: true)
    ->withSets([
        // PHP code quality
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,

        // TYPO3 specific
        Typo3LevelSetList::UP_TO_TYPO3_14,
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,
    ])
    ->withSkip([
        // Skip vendor and build directories
        __DIR__ . '/.Build',
    ])
    ->withImportNames(removeUnusedImports: true);
