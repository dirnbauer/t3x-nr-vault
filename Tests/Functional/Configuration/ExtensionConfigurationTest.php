<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Configuration;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ExtensionConfiguration::class)]
#[AllowMockObjectsWithoutExpectations]
final class ExtensionConfigurationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    #[Test]
    public function getAutoKeyPathReturnsPathBasedOnVarPath(): void
    {
        $typo3Config = $this->createMock(Typo3ExtensionConfiguration::class);
        $typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($typo3Config);

        $path = $config->getAutoKeyPath();

        self::assertStringContainsString('secrets/vault-master.key', $path);
        self::assertStringStartsWith(Environment::getVarPath(), $path);
    }
}
