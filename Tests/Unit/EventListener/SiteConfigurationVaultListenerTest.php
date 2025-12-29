<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\EventListener;

use Netresearch\NrVault\Configuration\SiteConfigurationVaultProcessor;
use Netresearch\NrVault\EventListener\SiteConfigurationVaultListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

#[CoversClass(SiteConfigurationVaultListener::class)]
final class SiteConfigurationVaultListenerTest extends TestCase
{
    private SiteConfigurationVaultProcessor&MockObject $processor;

    private SiteConfigurationVaultListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = $this->createMock(SiteConfigurationVaultProcessor::class);
        $this->listener = new SiteConfigurationVaultListener($this->processor);
    }

    #[Test]
    public function skipsProcessingWhenNoVaultReferences(): void
    {
        $config = [
            'base' => 'https://example.com',
            'languages' => [],
        ];

        $event = $this->createMock(SiteConfigurationLoadedEvent::class);
        $event->method('getConfiguration')->willReturn($config);
        $event->expects($this->never())->method('setConfiguration');

        $this->processor->expects($this->never())->method('processConfiguration');

        ($this->listener)($event);
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

        $event = $this->createMock(SiteConfigurationLoadedEvent::class);
        $event->method('getConfiguration')->willReturn($originalConfig);
        $event->expects($this->once())->method('setConfiguration')->with($processedConfig);

        $this->processor
            ->method('processConfiguration')
            ->with($originalConfig)
            ->willReturn($processedConfig);

        ($this->listener)($event);
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

        $event = $this->createMock(SiteConfigurationLoadedEvent::class);
        $event->method('getConfiguration')->willReturn($originalConfig);
        $event->expects($this->once())->method('setConfiguration')->with($processedConfig);

        $this->processor
            ->method('processConfiguration')
            ->willReturn($processedConfig);

        ($this->listener)($event);
    }

    #[Test]
    public function handlesEmptyConfiguration(): void
    {
        $config = [];

        $event = $this->createMock(SiteConfigurationLoadedEvent::class);
        $event->method('getConfiguration')->willReturn($config);
        $event->expects($this->never())->method('setConfiguration');

        $this->processor->expects($this->never())->method('processConfiguration');

        ($this->listener)($event);
    }
}
