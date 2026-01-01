<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Utility;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Utility\VaultFieldResolver;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class VaultFieldResolverTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Register mock VaultService for tests that call resolveFields
        $vaultServiceMock = $this->createMock(VaultServiceInterface::class);
        $vaultServiceMock->method('retrieve')->willReturn(null);
        GeneralUtility::addInstance(VaultServiceInterface::class, $vaultServiceMock);
    }

    #[Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function isVaultIdentifierReturnsTrueForValidIdentifier(): void
    {
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('tx_myext_settings__api_key__42'));
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('pages__secret__1'));
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('tx_ext__field__99999'));
    }

    #[Test]
    public function isVaultIdentifierReturnsFalseForInvalidIdentifier(): void
    {
        // Empty or non-string
        self::assertFalse(VaultFieldResolver::isVaultIdentifier(''));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier(null));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier(123));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier([]));

        // Wrong format
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('invalid'));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('not__a__valid__id'));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('tx_myext__api_key')); // Missing uid
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('__field__42')); // Missing table
    }

    #[Test]
    public function buildIdentifierCreatesCorrectFormat(): void
    {
        $identifier = VaultFieldResolver::buildIdentifier('tx_myext_settings', 'api_key', 42);

        self::assertSame('tx_myext_settings__api_key__42', $identifier);
    }

    #[Test]
    public function parseIdentifierReturnsCorrectComponents(): void
    {
        $result = VaultFieldResolver::parseIdentifier('tx_myext_settings__api_key__42');

        self::assertIsArray($result);
        self::assertSame('tx_myext_settings', $result['table']);
        self::assertSame('api_key', $result['field']);
        self::assertSame(42, $result['uid']);
    }

    #[Test]
    public function parseIdentifierReturnsNullForInvalidIdentifier(): void
    {
        self::assertNull(VaultFieldResolver::parseIdentifier('invalid'));
        self::assertNull(VaultFieldResolver::parseIdentifier(''));
        self::assertNull(VaultFieldResolver::parseIdentifier('too__many__parts__here'));
    }

    #[Test]
    public function getVaultFieldsForTableReturnsEmptyForUnknownTable(): void
    {
        // No TCA loaded for this table
        $fields = VaultFieldResolver::getVaultFieldsForTable('tx_nonexistent_table');

        self::assertSame([], $fields);
    }

    #[Test]
    public function hasVaultFieldsReturnsFalseForTableWithoutVaultFields(): void
    {
        self::assertFalse(VaultFieldResolver::hasVaultFields('tx_nonexistent_table'));
    }

    #[Test]
    public function resolveFieldsPreservesNonVaultFields(): void
    {
        $data = [
            'title' => 'Test Title',
            'description' => 'Some description',
            'count' => 42,
        ];

        // None of these are vault identifiers, so they should be unchanged
        $result = VaultFieldResolver::resolveFields($data, ['title', 'description', 'count']);

        self::assertSame($data, $result);
    }

    #[Test]
    public function resolveFieldsSkipsMissingFields(): void
    {
        $data = [
            'title' => 'Test',
        ];

        // Field doesn't exist, should not throw
        $result = VaultFieldResolver::resolveFields($data, ['api_key']);

        self::assertSame($data, $result);
    }

    #[Test]
    #[DataProvider('identifierProvider')]
    public function isVaultIdentifierWithDataProvider(mixed $value, bool $expected): void
    {
        self::assertSame($expected, VaultFieldResolver::isVaultIdentifier($value));
    }

    public static function identifierProvider(): array
    {
        return [
            'valid identifier' => ['tx_ext__field__1', true],
            'valid with underscores in parts' => ['tx_my_ext__api_key__123', true],
            'empty string' => ['', false],
            'null' => [null, false],
            'integer' => [42, false],
            'array' => [['test'], false],
            'single part' => ['single', false],
            'two parts' => ['two__parts', false],
            'non-numeric uid' => ['table__field__abc', false],
            'extra separator' => ['too__many__sep__arators', false],
        ];
    }
}
