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
    public function isVaultIdentifierReturnsTrueForValidUuid(): void
    {
        // Valid UUID v7 identifiers
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('01937b6e-4b6c-7abc-8def-0123456789ab'));
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('01937b6f-0000-7000-8000-000000000000'));
        self::assertTrue(VaultFieldResolver::isVaultIdentifier('01937b6f-ffff-7fff-bfff-ffffffffffff'));
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
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('not-a-valid-uuid'));
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('tx_myext__api_key__42')); // Old format
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('01937b6e-4b6c-1abc-8def-0123456789ab')); // UUID v1 (not v7)
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('01937b6e-4b6c-4abc-8def-0123456789ab')); // UUID v4 (not v7)
        self::assertFalse(VaultFieldResolver::isVaultIdentifier('01937b6e-4b6c-7abc-cdef-0123456789ab')); // Wrong variant
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
            'valid uuid v7 lowercase' => ['01937b6e-4b6c-7abc-8def-0123456789ab', true],
            'valid uuid v7 uppercase' => ['01937B6E-4B6C-7ABC-8DEF-0123456789AB', true],
            'valid uuid v7 mixed case' => ['01937b6e-4B6C-7abc-8DEF-0123456789ab', true],
            'empty string' => ['', false],
            'null' => [null, false],
            'integer' => [42, false],
            'array' => [['test'], false],
            'old format table__field__uid' => ['tx_ext__field__1', false],
            'uuid v1' => ['01937b6e-4b6c-1abc-8def-0123456789ab', false],
            'uuid v4' => ['01937b6e-4b6c-4abc-8def-0123456789ab', false],
            'uuid with wrong variant' => ['01937b6e-4b6c-7abc-cdef-0123456789ab', false],
            'too short' => ['01937b6e-4b6c-7abc-8def', false],
            'too long' => ['01937b6e-4b6c-7abc-8def-0123456789ab1', false],
            'missing hyphens' => ['01937b6e4b6c7abc8def0123456789ab', false],
        ];
    }

    #[Test]
    public function getVaultFieldsForTableReturnsVaultSecretFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
                'title' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
                'api_key' => [
                    'config' => [
                        'type' => 'input',
                        'renderType' => 'vaultSecret',
                    ],
                ],
                'api_secret' => [
                    'config' => [
                        'type' => 'input',
                        'renderType' => 'vaultSecret',
                    ],
                ],
            ],
        ];

        $fields = VaultFieldResolver::getVaultFieldsForTable('tx_test');

        self::assertSame(['api_key', 'api_secret'], $fields);
    }

    #[Test]
    public function hasVaultFieldsReturnsTrueForTableWithVaultFields(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
                'api_key' => [
                    'config' => [
                        'type' => 'input',
                        'renderType' => 'vaultSecret',
                    ],
                ],
            ],
        ];

        self::assertTrue(VaultFieldResolver::hasVaultFields('tx_test'));
    }

    #[Test]
    public function resolveSingleReturnsNullForNonUuid(): void
    {
        $result = VaultFieldResolver::resolve('not-a-uuid');

        self::assertNull($result);
    }

    #[Test]
    public function resolveSingleReturnsNullForEmptyString(): void
    {
        $result = VaultFieldResolver::resolve('');

        self::assertNull($result);
    }
}
