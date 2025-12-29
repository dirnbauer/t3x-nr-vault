<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Utility;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Utility\FlexFormVaultResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FlexFormVaultResolverTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function isFlexFormVaultIdentifierReturnsTrueForValidIdentifier(): void
    {
        // FlexForm format: table__flexfield__sheet__fieldpath__uid
        self::assertTrue(FlexFormVaultResolver::isFlexFormVaultIdentifier(
            'tt_content__pi_flexform__settings__apiKey__123',
        ));
        self::assertTrue(FlexFormVaultResolver::isFlexFormVaultIdentifier(
            'tx_ext__flex__sheet1__field_name__42',
        ));
    }

    #[Test]
    public function isFlexFormVaultIdentifierReturnsFalseForInvalidIdentifier(): void
    {
        // Empty or non-string
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(''));
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(null));
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(123));

        // Wrong format - standard TCA (3 parts, not 5)
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier('tx_ext__field__1'));

        // Too few parts
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier('table__flex__sheet'));

        // Too many parts
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(
            'too__many__parts__in__this__identifier',
        ));
    }

    #[Test]
    public function buildIdentifierCreatesCorrectFormat(): void
    {
        $identifier = FlexFormVaultResolver::buildIdentifier(
            'tt_content',
            'pi_flexform',
            'settings',
            'apiKey',
            123,
        );

        self::assertSame('tt_content__pi_flexform__settings__apiKey__123', $identifier);
    }

    #[Test]
    public function buildIdentifierSanitizesFieldPath(): void
    {
        // Field paths with dots/slashes should be converted to underscores
        $identifier = FlexFormVaultResolver::buildIdentifier(
            'tt_content',
            'pi_flexform',
            'settings',
            'nested.field/name',
            123,
        );

        self::assertSame('tt_content__pi_flexform__settings__nested_field_name__123', $identifier);
    }

    #[Test]
    public function parseIdentifierReturnsCorrectComponents(): void
    {
        $result = FlexFormVaultResolver::parseIdentifier(
            'tt_content__pi_flexform__settings__apiKey__123',
        );

        self::assertIsArray($result);
        self::assertSame('tt_content', $result['table']);
        self::assertSame('pi_flexform', $result['flexField']);
        self::assertSame('settings', $result['sheet']);
        self::assertSame('apiKey', $result['fieldPath']);
        self::assertSame(123, $result['uid']);
    }

    #[Test]
    public function parseIdentifierReturnsNullForInvalidIdentifier(): void
    {
        self::assertNull(FlexFormVaultResolver::parseIdentifier('invalid'));
        self::assertNull(FlexFormVaultResolver::parseIdentifier(''));
        // Standard TCA format (3 parts) is not a FlexForm identifier
        self::assertNull(FlexFormVaultResolver::parseIdentifier('tx_ext__field__1'));
    }

    #[Test]
    public function resolveSettingsPreservesNonVaultFields(): void
    {
        $this->registerVaultServiceMock();
        $settings = [
            'title' => 'Test Title',
            'limit' => 10,
            'showTitle' => true,
        ];

        $result = FlexFormVaultResolver::resolveSettings($settings, ['title', 'limit']);

        self::assertSame($settings, $result);
    }

    #[Test]
    public function resolveSettingsSkipsMissingFields(): void
    {
        $this->registerVaultServiceMock();
        $settings = [
            'title' => 'Test',
        ];

        $result = FlexFormVaultResolver::resolveSettings($settings, ['apiKey']);

        self::assertSame($settings, $result);
    }

    #[Test]
    #[DataProvider('flexFormIdentifierProvider')]
    public function isFlexFormVaultIdentifierWithDataProvider(mixed $value, bool $expected): void
    {
        self::assertSame($expected, FlexFormVaultResolver::isFlexFormVaultIdentifier($value));
    }

    public static function flexFormIdentifierProvider(): array
    {
        return [
            'valid 5-part identifier' => [
                'tt_content__pi_flexform__settings__apiKey__123',
                true,
            ],
            'valid with underscores in parts' => [
                'tx_my_ext__my_flex__my_sheet__my_field__999',
                true,
            ],
            'empty string' => ['', false],
            'null' => [null, false],
            'integer' => [42, false],
            'array' => [['test'], false],
            'standard TCA identifier (3 parts)' => ['tx_ext__field__1', false],
            'four parts (missing one)' => ['a__b__c__d', false],
            'six parts (extra)' => ['a__b__c__d__e__f', false],
            'non-numeric uid' => ['a__b__c__d__abc', false],
        ];
    }

    private function registerVaultServiceMock(): void
    {
        // Register mock VaultService for resolveSettings tests
        $vaultService = $this->createMock(VaultServiceInterface::class);
        GeneralUtility::addInstance(VaultServiceInterface::class, $vaultService);
    }
}
