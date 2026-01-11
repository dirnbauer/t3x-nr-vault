<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Configuration;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration as Typo3ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

#[CoversClass(ExtensionConfiguration::class)]
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
