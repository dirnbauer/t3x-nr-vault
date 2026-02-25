<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Imaging\IconSize;

/**
 * Unit tests for SecretsController.
 *
 * These tests verify the controller's code correctness without requiring
 * a full TYPO3 bootstrap. Controller itself is tested via functional tests.
 */
#[CoversNothing]
final class VaultControllerTest extends TestCase
{
    #[Test]
    public function iconSizeEnumIsUsedCorrectly(): void
    {
        // Verify IconSize::SMALL exists and is the correct type
        self::assertInstanceOf(IconSize::class, IconSize::SMALL);
    }

    #[Test]
    public function languageLabelsExistForAllButtons(): void
    {
        $xliffPath = __DIR__ . '/../../../Resources/Private/Language/locallang_mod.xlf';
        self::assertFileExists($xliffPath);

        $xliffContent = file_get_contents($xliffPath);
        self::assertNotFalse($xliffContent);

        // Required button labels
        $requiredLabels = [
            'action.secrets',
            'action.audit',
            'action.verifyChain',
            'action.export',
        ];

        foreach ($requiredLabels as $label) {
            self::assertStringContainsString(
                'id="' . $label . '"',
                $xliffContent,
                "Missing required language label: $label",
            );
        }
    }

    #[Test]
    public function controllerDoesNotUseDeprecatedIconConstants(): void
    {
        $controllerPath = __DIR__ . '/../../../Classes/Controller/SecretsController.php';
        self::assertFileExists($controllerPath);

        $controllerContent = file_get_contents($controllerPath);
        self::assertNotFalse($controllerContent);

        // Should NOT use deprecated Icon::SIZE_* constants
        self::assertStringNotContainsString(
            'Icon::SIZE_SMALL',
            $controllerContent,
            'Controller should not use deprecated Icon::SIZE_SMALL',
        );

        self::assertStringNotContainsString(
            'Icon::SIZE_DEFAULT',
            $controllerContent,
            'Controller should not use deprecated Icon::SIZE_DEFAULT',
        );

        // Should use IconSize enum
        self::assertStringContainsString(
            'IconSize::SMALL',
            $controllerContent,
            'Controller should use IconSize::SMALL enum',
        );
    }

    #[Test]
    public function controllerUsesIconSizeImport(): void
    {
        $controllerPath = __DIR__ . '/../../../Classes/Controller/SecretsController.php';
        self::assertFileExists($controllerPath);

        $controllerContent = file_get_contents($controllerPath);
        self::assertNotFalse($controllerContent);

        // Should import IconSize
        self::assertStringContainsString(
            'use TYPO3\CMS\Core\Imaging\IconSize;',
            $controllerContent,
            'Controller should import IconSize class',
        );
    }
}
