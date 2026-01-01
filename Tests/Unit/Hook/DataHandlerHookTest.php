<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\DataHandlerHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(DataHandlerHook::class)]
final class DataHandlerHookTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private DataHandlerHook $subject;

    private VaultServiceInterface&MockObject $vaultService;

    private AuditLogServiceInterface&MockObject $auditLogService;

    private DataHandler&MockObject $dataHandler;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new DataHandlerHook();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->auditLogService = $this->createMock(AuditLogServiceInterface::class);
        $this->dataHandler = $this->createMock(DataHandler::class);

        GeneralUtility::addInstance(VaultServiceInterface::class, $this->vaultService);
        GeneralUtility::addInstance(AuditLogServiceInterface::class, $this->auditLogService);
    }

    #[Override]
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function preProcessFieldArrayIgnoresFieldsWithoutVaultRenderType(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
                'title' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
            ],
        ];

        $fieldArray = ['title' => 'Test Title'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        self::assertSame(['title' => 'Test Title'], $fieldArray);
    }

    #[Test]
    public function preProcessFieldArrayExtractsVaultFieldValue(): void
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

        $fieldArray = [
            'api_key' => [
                'value' => 'my-secret-key',
                '_vault_identifier' => '',
                '_vault_checksum' => '',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        // Value should be replaced with placeholder
        self::assertSame('__VAULT__', $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayHandlesStringValue(): void
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

        $fieldArray = ['api_key' => 'direct-string-value'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        self::assertSame('__VAULT__', $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayRemovesEmptyValueWithNoChecksum(): void
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

        $fieldArray = [
            'api_key' => [
                'value' => '',
                '_vault_identifier' => '',
                '_vault_checksum' => '',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        // Empty values with no checksum should be removed entirely
        self::assertArrayNotHasKey('api_key', $fieldArray);
    }

    #[Test]
    public function afterDatabaseOperationsStoresNewSecret(): void
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

        // Setup pending secrets via preProcess
        $fieldArray = [
            'api_key' => [
                'value' => 'new-secret',
                '_vault_identifier' => '',
                '_vault_checksum' => '',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            'NEW123',
        );

        // Mock substitution of NEW id
        $this->dataHandler->substNEWwithIDs = ['NEW123' => 42];

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                'tx_test__api_key__42',
                'new-secret',
                self::callback(static fn (array $options): bool => $options['table'] === 'tx_test'
                    && $options['field'] === 'api_key'
                    && $options['uid'] === 42
                    && $options['source'] === 'tca_field'),
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'new',
            'tx_test',
            'NEW123',
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsRotatesExistingSecret(): void
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

        $fieldArray = [
            'api_key' => [
                'value' => 'updated-secret',
                '_vault_identifier' => 'tx_test__api_key__42',
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->expects(self::once())
            ->method('rotate')
            ->with(
                'tx_test__api_key__42',
                'updated-secret',
                'TCA field updated',
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsDeletesSecretWhenCleared(): void
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

        $fieldArray = [
            'api_key' => [
                'value' => '',
                '_vault_identifier' => 'tx_test__api_key__42',
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->expects(self::once())
            ->method('delete')
            ->with(
                'tx_test__api_key__42',
                'TCA field cleared',
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsLogsVaultException(): void
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

        $fieldArray = ['api_key' => 'test-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with(
                'tx_test',
                42,
                2,
                0,
                1,
                self::stringContains('api_key'),
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function cmdmapPreProcessDeletesVaultSecretsOnRecordDelete(): void
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

        $this->vaultService
            ->method('getMetadata')
            ->with('tx_test__api_key__42')
            ->willReturn(['identifier' => 'tx_test__api_key__42']);

        $this->vaultService
            ->expects(self::once())
            ->method('delete')
            ->with('tx_test__api_key__42', 'Record deleted');

        $this->subject->processCmdmap_preProcess(
            'delete',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPreProcessIgnoresNonDeleteCommands(): void
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

        $this->vaultService
            ->expects(self::never())
            ->method('delete');

        $this->subject->processCmdmap_preProcess(
            'copy',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPreProcessSkipsNonExistentSecrets(): void
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

        $this->vaultService
            ->method('getMetadata')
            ->willThrowException(new SecretNotFoundException('Secret not found'));

        $this->vaultService
            ->expects(self::never())
            ->method('delete');

        $this->subject->processCmdmap_preProcess(
            'delete',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPostProcessCopiesVaultSecretsOnRecordCopy(): void
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

        $this->dataHandler->copyMappingArray = [
            'tx_test' => [42 => 100],
        ];

        $this->vaultService
            ->method('retrieve')
            ->with('tx_test__api_key__42')
            ->willReturn('copied-secret-value');

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                'tx_test__api_key__100',
                'copied-secret-value',
                self::callback(static fn (array $options): bool => $options['table'] === 'tx_test'
                    && $options['field'] === 'api_key'
                    && $options['uid'] === 100
                    && $options['source'] === 'record_copy'
                    && $options['copied_from'] === 'tx_test__api_key__42'),
            );

        $this->subject->processCmdmap_postProcess(
            'copy',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPostProcessIgnoresNonCopyCommands(): void
    {
        $this->vaultService
            ->expects(self::never())
            ->method('retrieve');

        $this->subject->processCmdmap_postProcess(
            'delete',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPostProcessSkipsWhenNoNewIdFound(): void
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

        $this->dataHandler->copyMappingArray = [];

        $this->vaultService
            ->expects(self::never())
            ->method('retrieve');

        $this->subject->processCmdmap_postProcess(
            'copy',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    public function cmdmapPostProcessSkipsWhenSourceSecretIsNull(): void
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

        $this->dataHandler->copyMappingArray = [
            'tx_test' => [42 => 100],
        ];

        $this->vaultService
            ->method('retrieve')
            ->willReturn(null);

        $this->vaultService
            ->expects(self::never())
            ->method('store');

        $this->subject->processCmdmap_postProcess(
            'copy',
            'tx_test',
            42,
            null,
            $this->dataHandler,
            false,
        );
    }

    #[Test]
    #[DataProvider('identifierFormatProvider')]
    public function buildVaultIdentifierCreatesCorrectFormat(
        string $table,
        string $field,
        int $uid,
        string $expected,
    ): void {
        $GLOBALS['TCA'][$table] = [
            'columns' => [
                $field => [
                    'config' => [
                        'type' => 'input',
                        'renderType' => 'vaultSecret',
                    ],
                ],
            ],
        ];

        // We test this indirectly through cmdmapPostProcess
        $this->dataHandler->copyMappingArray = [
            $table => [$uid => 999],
        ];

        $this->vaultService
            ->method('retrieve')
            ->with($expected)
            ->willReturn('secret');

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                \sprintf('%s__%s__999', $table, $field),
                'secret',
                self::anything(),
            );

        $this->subject->processCmdmap_postProcess(
            'copy',
            $table,
            $uid,
            null,
            $this->dataHandler,
            false,
        );
    }

    public static function identifierFormatProvider(): array
    {
        return [
            'simple table and field' => [
                'tx_test',
                'api_key',
                42,
                'tx_test__api_key__42',
            ],
            'table with underscores' => [
                'tx_my_extension_settings',
                'secret_token',
                1,
                'tx_my_extension_settings__secret_token__1',
            ],
            'large uid' => [
                'pages',
                'secret',
                999999,
                'pages__secret__999999',
            ],
        ];
    }

    #[Test]
    public function multipleVaultFieldsAreProcessed(): void
    {
        $GLOBALS['TCA']['tx_test'] = [
            'columns' => [
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
                'title' => [
                    'config' => [
                        'type' => 'input',
                    ],
                ],
            ],
        ];

        $fieldArray = [
            'api_key' => 'key-value',
            'api_secret' => 'secret-value',
            'title' => 'Test Title',
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        // Both vault fields should be replaced
        self::assertSame('__VAULT__', $fieldArray['api_key']);
        self::assertSame('__VAULT__', $fieldArray['api_secret']);
        // Non-vault field unchanged
        self::assertSame('Test Title', $fieldArray['title']);
    }
}
