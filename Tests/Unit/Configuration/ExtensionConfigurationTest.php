<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Configuration;

use Netresearch\NrVault\Configuration\Dto\AwsSecretsConfig;
use Netresearch\NrVault\Configuration\Dto\VaultServerConfig;
use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Throwable;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;

#[CoversClass(ExtensionConfiguration::class)]
#[AllowMockObjectsWithoutExpectations]
final class ExtensionConfigurationTest extends TestCase
{
    private Typo3ExtensionConfiguration&MockObject $typo3Config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->typo3Config = $this->createMock(Typo3ExtensionConfiguration::class);
    }

    #[Test]
    public function getStorageAdapterReturnsConfiguredValue(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['storageAdapter' => 'hashicorp']);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame('hashicorp', $config->getStorageAdapter());
    }

    #[Test]
    public function getStorageAdapterReturnsDefaultWhenNotConfigured(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame(ExtensionConfiguration::DEFAULT_STORAGE_ADAPTER, $config->getStorageAdapter());
    }

    #[Test]
    public function getMasterKeyProviderReturnsConfiguredValue(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['masterKeyProvider' => 'env']);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame('env', $config->getMasterKeyProvider());
    }

    #[Test]
    public function getMasterKeyProviderReturnsDefaultWhenNotConfigured(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame(ExtensionConfiguration::DEFAULT_MASTER_KEY_PROVIDER, $config->getMasterKeyProvider());
    }

    #[Test]
    public function getMasterKeySourceReturnsConfiguredValue(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['masterKeySource' => '/path/to/key']);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame('/path/to/key', $config->getMasterKeySource());
    }

    #[Test]
    public function getAuditLogRetentionReturnsConfiguredValue(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['auditLogRetention' => 90]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame(90, $config->getAuditLogRetention());
    }

    #[Test]
    public function getAuditLogRetentionReturnsDefaultWhenNotConfigured(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame(ExtensionConfiguration::DEFAULT_AUDIT_LOG_RETENTION, $config->getAuditLogRetention());
    }

    #[Test]
    public function isCliAccessAllowedReturnsTrueWhenEnabled(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['allowCliAccess' => true]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertTrue($config->isCliAccessAllowed());
    }

    #[Test]
    public function isCliAccessAllowedReturnsFalseByDefault(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertFalse($config->isCliAccessAllowed());
    }

    #[Test]
    public function getCliAccessGroupsParsesCommaString(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['cliAccessGroups' => '1,2,3']);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame([1, 2, 3], $config->getCliAccessGroups());
    }

    #[Test]
    public function getCliAccessGroupsHandlesArray(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['cliAccessGroups' => [1, 2, 3]]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertSame([1, 2, 3], $config->getCliAccessGroups());
    }

    #[Test]
    public function isCacheEnabledReturnsTrueByDefault(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertTrue($config->isCacheEnabled());
    }

    #[Test]
    public function isCacheEnabledReturnsFalseWhenDisabled(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['cacheEnabled' => false]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertFalse($config->isCacheEnabled());
    }

    #[Test]
    public function preferXChaCha20ReturnsFalseByDefault(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertFalse($config->preferXChaCha20());
    }

    #[Test]
    public function preferXChaCha20ReturnsTrueWhenEnabled(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['preferXChaCha20' => true]);

        $config = new ExtensionConfiguration($this->typo3Config);

        self::assertTrue($config->preferXChaCha20());
    }

    #[Test]
    public function getHashiCorpConfigReturnsEmptyConfigByDefault(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);
        $hashiCorpConfig = $config->getHashiCorpConfig();

        self::assertInstanceOf(VaultServerConfig::class, $hashiCorpConfig);
        self::assertSame('', $hashiCorpConfig->address);
        self::assertSame('', $hashiCorpConfig->path);
        self::assertSame('', $hashiCorpConfig->authMethod);
        self::assertSame('', $hashiCorpConfig->token);
    }

    #[Test]
    public function getHashiCorpConfigReturnsConfiguredValues(): void
    {
        $hashicorpConfig = [
            'address' => 'https://vault.example.com',
            'path' => 'secret/data',
            'authMethod' => 'token',
        ];

        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['hashicorp' => $hashicorpConfig]);

        $config = new ExtensionConfiguration($this->typo3Config);
        $result = $config->getHashiCorpConfig();

        self::assertInstanceOf(VaultServerConfig::class, $result);
        self::assertSame('https://vault.example.com', $result->address);
        self::assertSame('secret/data', $result->path);
        self::assertSame('token', $result->authMethod);
    }

    #[Test]
    public function getAwsConfigReturnsEmptyConfigByDefault(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);
        $awsConfig = $config->getAwsConfig();

        self::assertInstanceOf(AwsSecretsConfig::class, $awsConfig);
        self::assertSame('', $awsConfig->region);
        self::assertSame('', $awsConfig->secretPrefix);
    }

    #[Test]
    public function getAwsConfigReturnsConfiguredValues(): void
    {
        $awsConfig = [
            'region' => 'eu-west-1',
            'secretPrefix' => 'myapp/',
        ];

        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(['aws' => $awsConfig]);

        $config = new ExtensionConfiguration($this->typo3Config);
        $result = $config->getAwsConfig();

        self::assertInstanceOf(AwsSecretsConfig::class, $result);
        self::assertSame('eu-west-1', $result->region);
        self::assertSame('myapp/', $result->secretPrefix);
    }

    #[Test]
    public function getAutoKeyPathReturnsPath(): void
    {
        // This test requires TYPO3 Environment to be initialized (functional test context)
        // Skip in unit test environment where Environment::getVarPath() will fail
        try {
            \TYPO3\CMS\Core\Core\Environment::getVarPath();
        } catch (Throwable) {
            self::markTestSkipped('Requires TYPO3 Environment initialization');
        }

        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn([]);

        $config = new ExtensionConfiguration($this->typo3Config);

        $path = $config->getAutoKeyPath();

        self::assertStringContainsString('secrets/vault-master.key', $path);
    }

    #[Test]
    public function handlesNullConfiguration(): void
    {
        $this->typo3Config->method('get')
            ->with('nr_vault')
            ->willReturn(null);

        $config = new ExtensionConfiguration($this->typo3Config);

        // Should use defaults without crashing
        self::assertSame(ExtensionConfiguration::DEFAULT_STORAGE_ADAPTER, $config->getStorageAdapter());
        self::assertSame(ExtensionConfiguration::DEFAULT_MASTER_KEY_PROVIDER, $config->getMasterKeyProvider());
    }
}
