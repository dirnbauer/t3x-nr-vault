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
        Typo3LevelSetList::UP_TO_TYPO3_13,
        Typo3SetList::CODE_QUALITY,
        Typo3SetList::GENERAL,
    ])
    ->withSkip([
        // Skip vendor and build directories
        __DIR__ . '/.Build',
        // Scheduler tasks use unserialize() - constructor injection breaks them
        __DIR__ . '/Classes/Task/OrphanCleanupTask.php',
        // Factory is called via GeneralUtility::makeInstance without constructor args
        __DIR__ . '/Classes/Http/SecureHttpClientFactory.php',
    ])
    ->withImportNames(removeUnusedImports: true);
