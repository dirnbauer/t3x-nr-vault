<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use Exception;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\FlexFormVaultHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Unit tests for FlexFormVaultHook.
 *
 * Note: Tests requiring FlexFormTools mocking are skipped in TYPO3 v14+
 * because FlexFormTools is a readonly class that cannot be mocked.
 * These tests should be migrated to functional tests.
 */
#[CoversClass(FlexFormVaultHook::class)]
final class FlexFormVaultHookTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private FlexFormVaultHook $subject;

    private VaultServiceInterface&MockObject $vaultService;

    private FlexFormTools&MockObject $flexFormTools;

    private DataHandler&MockObject $dataHandler;

    private bool $canMockFlexFormTools = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new FlexFormVaultHook();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->dataHandler = $this->createMock(DataHandler::class);

        // Check if FlexFormTools is readonly (TYPO3 v14+)
        $reflection = new ReflectionClass(FlexFormTools::class);
        $this->canMockFlexFormTools = !$reflection->isReadOnly();

        if ($this->canMockFlexFormTools) {
            $this->flexFormTools = $this->createMock(FlexFormTools::class);
            GeneralUtility::addInstance(FlexFormTools::class, $this->flexFormTools);
        }

        GeneralUtility::addInstance(VaultServiceInterface::class, $this->vaultService);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function preProcessFieldArrayIgnoresNonFlexFields(): void
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
            $this->dataHandler,
        );

        self::assertSame(['title' => 'Test Title'], $fieldArray);
    }

    #[Test]
    public function preProcessFieldArrayIgnoresFlexFieldWithNonArrayValue(): void
    {
        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $fieldArray = ['pi_flexform' => '<xml>string</xml>'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
            $this->dataHandler,
        );

        self::assertSame(['pi_flexform' => '<xml>string</xml>'], $fieldArray);
    }

    #[Test]
    public function preProcessFieldArrayIgnoresFlexFieldWithoutDataStructure(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willThrowException(new Exception('No data structure'));

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => ['vDEF' => 'test'],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
            $this->dataHandler,
        );

        // Should remain unchanged
        self::assertSame('test', $fieldArray['pi_flexform']['data']['settings']['lDEF']['apiKey']['vDEF']);
    }

    #[Test]
    public function preProcessFieldArrayExtractsVaultFieldFromFlexForm(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => [
                                    'value' => 'my-secret-api-key',
                                    '_vault_identifier' => '',
                                    '_vault_checksum' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            123,
            $this->dataHandler,
        );

        // Value should be replaced with placeholder
        self::assertSame(
            '__VAULT__',
            $fieldArray['pi_flexform']['data']['settings']['lDEF']['apiKey']['vDEF'],
        );
    }

    #[Test]
    public function preProcessFieldArrayHandlesStringValueInFlexForm(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => 'direct-string-secret',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            123,
            $this->dataHandler,
        );

        self::assertSame(
            '__VAULT__',
            $fieldArray['pi_flexform']['data']['settings']['lDEF']['apiKey']['vDEF'],
        );
    }

    #[Test]
    public function preProcessFieldArraySkipsNonVaultFlexFormFields(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'title' => [
                                    'config' => [
                                        'type' => 'input',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'title' => [
                                'vDEF' => 'My Title',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            123,
            $this->dataHandler,
        );

        // Non-vault fields should remain unchanged
        self::assertSame(
            'My Title',
            $fieldArray['pi_flexform']['data']['settings']['lDEF']['title']['vDEF'],
        );
    }

    #[Test]
    public function afterDatabaseOperationsStoresNewFlexFormSecret(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        // Setup pending secrets
        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => [
                                    'value' => 'new-flexform-secret',
                                    '_vault_identifier' => '',
                                    '_vault_checksum' => '',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            'NEW456',
            $this->dataHandler,
        );

        $this->dataHandler->substNEWwithIDs = ['NEW456' => 789];

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                'tt_content__pi_flexform__settings__apiKey__789',
                'new-flexform-secret',
                self::callback(static fn (array $options): bool => $options['table'] === 'tt_content'
                    && $options['flexField'] === 'pi_flexform'
                    && $options['sheet'] === 'settings'
                    && $options['fieldPath'] === 'apiKey'
                    && $options['uid'] === 789
                    && $options['source'] === 'flexform_field'),
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'new',
            'tt_content',
            'NEW456',
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsRotatesExistingFlexFormSecret(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => [
                                    'value' => 'updated-secret',
                                    '_vault_identifier' => 'tt_content__pi_flexform__settings__apiKey__789',
                                    '_vault_checksum' => 'existing-checksum',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            789,
            $this->dataHandler,
        );

        $this->vaultService
            ->expects(self::once())
            ->method('rotate')
            ->with(
                'tt_content__pi_flexform__settings__apiKey__789',
                'updated-secret',
                'FlexForm field updated',
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            789,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsDeletesFlexFormSecretWhenCleared(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => [
                                    'value' => '',
                                    '_vault_identifier' => 'tt_content__pi_flexform__settings__apiKey__789',
                                    '_vault_checksum' => 'existing-checksum',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            789,
            $this->dataHandler,
        );

        $this->vaultService
            ->expects(self::once())
            ->method('delete')
            ->with(
                'tt_content__pi_flexform__settings__apiKey__789',
                'FlexForm field cleared',
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            789,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsLogsVaultExceptionForFlexForm(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => 'test-secret',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            789,
            $this->dataHandler,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('FlexForm storage failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with(
                'tt_content',
                789,
                2,
                0,
                1,
                self::stringContains('apiKey'),
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            789,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function flexFormIdentifierSanitizesFieldPath(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'settings' => [
                        'ROOT' => [
                            'el' => [
                                'nested.field/name' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'settings' => [
                        'lDEF' => [
                            'nested.field/name' => [
                                'vDEF' => 'secret-with-special-path',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            123,
            $this->dataHandler,
        );

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                // dots and slashes should be converted to underscores
                'tt_content__pi_flexform__settings__nested_field_name__123',
                'secret-with-special-path',
                self::anything(),
            );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            123,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function multipleFlexFormSheetsAreProcessed(): void
    {
        $this->skipIfCannotMockFlexFormTools();

        $GLOBALS['TCA']['tt_content'] = [
            'columns' => [
                'pi_flexform' => [
                    'config' => [
                        'type' => 'flex',
                    ],
                ],
            ],
        ];

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test_identifier');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'general' => [
                        'ROOT' => [
                            'el' => [
                                'apiKey' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'advanced' => [
                        'ROOT' => [
                            'el' => [
                                'secretToken' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'vaultSecret',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'general' => [
                        'lDEF' => [
                            'apiKey' => [
                                'vDEF' => 'api-secret',
                            ],
                        ],
                    ],
                    'advanced' => [
                        'lDEF' => [
                            'secretToken' => [
                                'vDEF' => 'token-secret',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            123,
            $this->dataHandler,
        );

        // Both should be replaced
        self::assertSame(
            '__VAULT__',
            $fieldArray['pi_flexform']['data']['general']['lDEF']['apiKey']['vDEF'],
        );
        self::assertSame(
            '__VAULT__',
            $fieldArray['pi_flexform']['data']['advanced']['lDEF']['secretToken']['vDEF'],
        );

        $this->vaultService
            ->expects(self::exactly(2))
            ->method('store');

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            123,
            $fieldArray,
            $this->dataHandler,
        );
    }

    /**
     * Skip test if FlexFormTools is readonly (TYPO3 v14+) and cannot be mocked.
     */
    private function skipIfCannotMockFlexFormTools(): void
    {
        if (!$this->canMockFlexFormTools) {
            self::markTestSkipped('FlexFormTools is readonly in TYPO3 v14+ and cannot be mocked. Test requires migration to functional tests.');
        }
    }
}
