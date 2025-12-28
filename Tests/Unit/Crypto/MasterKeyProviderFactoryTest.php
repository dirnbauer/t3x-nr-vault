<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Crypto\EnvironmentMasterKeyProvider;
use Netresearch\NrVault\Crypto\FileMasterKeyProvider;
use Netresearch\NrVault\Crypto\MasterKeyProviderFactory;
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
    public function createThrowsExceptionForInvalidProvider(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('invalid');

        $this->expectException(ConfigurationException::class);

        $this->subject->create();
    }

    #[Test]
    public function getAvailableProviderReturnsConfiguredProviderWhenAvailable(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('env');

        // Set environment variable
        $originalValue = getenv('NR_VAULT_MASTER_KEY');
        putenv('NR_VAULT_MASTER_KEY=' . base64_encode(random_bytes(32)));

        $this->configuration
            ->method('getMasterKeyEnvVar')
            ->willReturn('NR_VAULT_MASTER_KEY');

        $result = $this->subject->getAvailableProvider();

        self::assertInstanceOf(EnvironmentMasterKeyProvider::class, $result);

        // Restore
        if ($originalValue !== false) {
            putenv('NR_VAULT_MASTER_KEY=' . $originalValue);
        } else {
            putenv('NR_VAULT_MASTER_KEY');
        }
    }

    #[Test]
    public function getAvailableProviderFallsBackToEnvProvider(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('file');

        $this->configuration
            ->method('getMasterKeyPath')
            ->willReturn('/nonexistent/path/to/key');

        $this->configuration
            ->method('getMasterKeyEnvVar')
            ->willReturn('NR_VAULT_MASTER_KEY');

        // Set environment variable
        $originalValue = getenv('NR_VAULT_MASTER_KEY');
        putenv('NR_VAULT_MASTER_KEY=' . base64_encode(random_bytes(32)));

        $result = $this->subject->getAvailableProvider();

        self::assertInstanceOf(EnvironmentMasterKeyProvider::class, $result);

        // Restore
        if ($originalValue !== false) {
            putenv('NR_VAULT_MASTER_KEY=' . $originalValue);
        } else {
            putenv('NR_VAULT_MASTER_KEY');
        }
    }

    #[Test]
    public function getAvailableProviderFallsBackToFileProvider(): void
    {
        $this->configuration
            ->method('getMasterKeyProvider')
            ->willReturn('env');

        $this->configuration
            ->method('getMasterKeyEnvVar')
            ->willReturn('NR_VAULT_NONEXISTENT_VAR');

        $this->configuration
            ->method('getMasterKeyPath')
            ->willReturn('/nonexistent/path');

        // Ensure env var is not set
        putenv('NR_VAULT_NONEXISTENT_VAR');

        $result = $this->subject->getAvailableProvider();

        // Falls back to file provider (even if not available, for initialization)
        self::assertInstanceOf(FileMasterKeyProvider::class, $result);
    }
}
