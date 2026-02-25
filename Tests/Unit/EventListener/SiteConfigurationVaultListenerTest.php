<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\EventListener;

use Netresearch\NrVault\Configuration\SiteConfigurationVaultProcessorInterface;
use Netresearch\NrVault\EventListener\SiteConfigurationVaultListener;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

#[CoversClass(SiteConfigurationVaultListener::class)]
#[AllowMockObjectsWithoutExpectations]
final class SiteConfigurationVaultListenerTest extends TestCase
{
    private SiteConfigurationVaultProcessorInterface&MockObject $processor;

    private SiteConfigurationVaultListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->createMock(SiteConfigurationVaultProcessorInterface::class);
        $this->listener = new SiteConfigurationVaultListener($this->processor);
    }

    #[Test]
    public function skipsProcessingWhenNoVaultReferences(): void
    {
        $config = [
            'base' => 'https://example.com',
            'languages' => [],
        ];

        // Create real event - SiteConfigurationLoadedEvent is final
        $event = new SiteConfigurationLoadedEvent('test-site', $config);

        $this->processor->expects($this->never())->method('processConfiguration');

        ($this->listener)($event);

        // Configuration should remain unchanged
        self::assertSame($config, $event->getConfiguration());
    }

    #[Test]
    public function processesConfigurationWithVaultReferences(): void
    {
        $originalConfig = [
            'apiKey' => '%vault(my_key)%',
        ];

        $processedConfig = [
            'apiKey' => 'resolved_secret',
        ];

        $event = new SiteConfigurationLoadedEvent('test-site', $originalConfig);

        $this->processor
            ->expects($this->once())
            ->method('processConfiguration')
            ->with($originalConfig)
            ->willReturn($processedConfig);

        ($this->listener)($event);

        self::assertSame($processedConfig, $event->getConfiguration());
    }

    #[Test]
    public function processesNestedVaultReferences(): void
    {
        $originalConfig = [
            'settings' => [
                'secret' => '%vault(nested_secret)%',
            ],
        ];

        $processedConfig = [
            'settings' => [
                'secret' => 'resolved_nested',
            ],
        ];

        $event = new SiteConfigurationLoadedEvent('test-site', $originalConfig);

        $this->processor
            ->method('processConfiguration')
            ->willReturn($processedConfig);

        ($this->listener)($event);

        self::assertSame($processedConfig, $event->getConfiguration());
    }

    #[Test]
    public function handlesEmptyConfiguration(): void
    {
        $config = [];

        $event = new SiteConfigurationLoadedEvent('test-site', $config);

        $this->processor->expects($this->never())->method('processConfiguration');

        ($this->listener)($event);

        self::assertSame($config, $event->getConfiguration());
    }
}
