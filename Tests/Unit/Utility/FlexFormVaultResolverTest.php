<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Utility;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Utility\FlexFormVaultResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class FlexFormVaultResolverTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function isVaultIdentifierReturnsTrueForValidUuid(): void
    {
        // FlexForm and TCA vault fields now use UUID v7 format
        self::assertTrue(FlexFormVaultResolver::isVaultIdentifier(
            '01937b6e-4b6c-7abc-8def-0123456789ab',
        ));
        self::assertTrue(FlexFormVaultResolver::isVaultIdentifier(
            '01937b6f-0000-7000-8000-000000000000',
        ));
    }

    #[Test]
    public function isVaultIdentifierReturnsFalseForInvalidIdentifier(): void
    {
        // Empty or non-string
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(''));
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(null));
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(123));

        // Old format (no longer valid)
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier('tx_ext__field__1'));
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(
            'tt_content__pi_flexform__settings__apiKey__123',
        ));

        // Wrong UUID format
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier('not-a-uuid'));
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(
            '01937b6e-4b6c-1abc-8def-0123456789ab', // UUID v1, not v7
        ));
        self::assertFalse(FlexFormVaultResolver::isVaultIdentifier(
            '01937b6e-4b6c-4abc-8def-0123456789ab', // UUID v4, not v7
        ));
    }

    #[Test]
    #[IgnoreDeprecations]
    public function isFlexFormVaultIdentifierIsDeprecatedAlias(): void
    {
        // The deprecated method should work the same as isVaultIdentifier
        self::assertTrue(FlexFormVaultResolver::isFlexFormVaultIdentifier(
            '01937b6e-4b6c-7abc-8def-0123456789ab',
        ));
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(''));
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier(
            'tt_content__pi_flexform__settings__apiKey__123', // Old format
        ));
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
    #[DataProvider('uuidIdentifierProvider')]
    public function isVaultIdentifierWithDataProvider(mixed $value, bool $expected): void
    {
        self::assertSame($expected, FlexFormVaultResolver::isVaultIdentifier($value));
    }

    public static function uuidIdentifierProvider(): array
    {
        return [
            'valid uuid v7 lowercase' => ['01937b6e-4b6c-7abc-8def-0123456789ab', true],
            'valid uuid v7 uppercase' => ['01937B6E-4B6C-7ABC-8DEF-0123456789AB', true],
            'valid uuid v7 mixed case' => ['01937b6e-4B6C-7abc-8DEF-0123456789ab', true],
            'empty string' => ['', false],
            'null' => [null, false],
            'integer' => [42, false],
            'array' => [['test'], false],
            'old TCA format (3 parts)' => ['tx_ext__field__1', false],
            'old FlexForm format (5 parts)' => ['tt_content__pi_flexform__settings__apiKey__123', false],
            'uuid v1' => ['01937b6e-4b6c-1abc-8def-0123456789ab', false],
            'uuid v4' => ['01937b6e-4b6c-4abc-8def-0123456789ab', false],
            'uuid with wrong variant' => ['01937b6e-4b6c-7abc-cdef-0123456789ab', false],
            'too short' => ['01937b6e-4b6c-7abc-8def', false],
        ];
    }

    private function registerVaultServiceMock(): void
    {
        // Register mock VaultService for resolveSettings tests
        $vaultService = $this->createMock(VaultServiceInterface::class);
        GeneralUtility::addInstance(VaultServiceInterface::class, $vaultService);
    }
}
