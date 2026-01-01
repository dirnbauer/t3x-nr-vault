<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Crypto;

use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EnvironmentMasterKeyProvider;
use Netresearch\NrVault\Crypto\FileMasterKeyProvider;
use Netresearch\NrVault\Crypto\MasterKeyProviderFactory;
use Netresearch\NrVault\Crypto\Typo3MasterKeyProvider;
use Netresearch\NrVault\Exception\ConfigurationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(MasterKeyProviderFactory::class)]
final class MasterKeyProviderFactoryTest extends TestCase
{
    private MasterKeyProviderFactory $subject;

    private ExtensionConfigurationInterface&MockObject $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createMock(ExtensionConfigurationInterface::class);
        $this->subject = new MasterKeyProviderFactory($this->configuration);
    }

    #[Test]
    public function createReturnsFileMasterKeyProviderForFileType(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('file');

        $result = $this->subject->create();

        self::assertInstanceOf(FileMasterKeyProvider::class, $result);
    }

    #[Test]
    public function createReturnsEnvironmentMasterKeyProviderForEnvType(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('env');

        $result = $this->subject->create();

        self::assertInstanceOf(EnvironmentMasterKeyProvider::class, $result);
    }

    #[Test]
    public function createReturnsTypo3MasterKeyProviderForTypo3Type(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('typo3');

        $result = $this->subject->create();

        self::assertInstanceOf(Typo3MasterKeyProvider::class, $result);
    }

    #[Test]
    public function createThrowsExceptionForInvalidProvider(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('invalid');

        $this->expectException(ConfigurationException::class);

        $this->subject->create();
    }

    #[Test]
    public function getAvailableProviderReturnsProviderInstance(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('typo3');

        // getAvailableProvider always returns a provider instance
        // The specific type depends on availability, but it's always a MasterKeyProviderInterface
        $result = $this->subject->getAvailableProvider();

        self::assertInstanceOf(MasterKeyProviderInterface::class, $result);
    }
}
