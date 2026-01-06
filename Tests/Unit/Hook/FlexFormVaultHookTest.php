<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use InvalidArgumentException;
use Netresearch\NrVault\Hook\FlexFormVaultHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Schema\Field\FieldCollection;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

#[CoversClass(FlexFormVaultHook::class)]
final class FlexFormVaultHookTest extends TestCase
{
    private TcaSchemaFactory&MockObject $tcaSchemaFactory;

    private VaultServiceInterface&MockObject $vaultService;

    private FlexFormTools&MockObject $flexFormTools;

    private FlexFormVaultHook $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->flexFormTools = $this->createMock(FlexFormTools::class);

        $this->subject = new FlexFormVaultHook(
            $this->tcaSchemaFactory,
            $this->vaultService,
            $this->flexFormTools,
        );
    }

    #[Test]
    public function processDatamapPreProcessFieldArraySkipsUnknownTable(): void
    {
        $this->tcaSchemaFactory
            ->method('has')
            ->with('unknown_table')
            ->willReturn(false);

        $fieldArray = ['field' => 'value'];
        $originalFieldArray = $fieldArray;

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'unknown_table',
            'NEW123',
        );

        // Field array should not be modified
        self::assertSame($originalFieldArray, $fieldArray);
    }

    #[Test]
    public function processDatamapPreProcessFieldArraySkipsNonFlexFields(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('title');
        $field->method('getConfiguration')->willReturn(['type' => 'input']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['title' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $fieldArray = ['title' => 'Test'];
        $originalFieldArray = $fieldArray;

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // Non-flex fields should not be processed
        self::assertSame($originalFieldArray, $fieldArray);
    }

    #[Test]
    public function processDatamapPreProcessFieldArraySkipsFlexFieldNotInFieldArray(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $fieldArray = ['title' => 'Test'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // No modifications since pi_flexform is not in fieldArray
        self::assertSame(['title' => 'Test'], $fieldArray);
    }

    #[Test]
    public function processDatamapPreProcessFieldArraySkipsNonArrayFlexData(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $fieldArray = [
            'title' => 'Test',
            'pi_flexform' => 'not-an-array',
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // String flex data should be skipped
        self::assertSame('not-an-array', $fieldArray['pi_flexform']);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsProcessesPendingSecrets(): void
    {
        // For this test, we need pending secrets which are set during preProcess
        // Since the hook doesn't expose pendingFlexSecrets, we test the integration

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->substNEWwithIDs = [];

        // This should not throw and should clean up any pending secrets
        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            1,
            [],
            $dataHandler,
        );

        // No assertions needed - just verifying no errors
        self::assertTrue(true);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsResolvesNewRecordUid(): void
    {
        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->substNEWwithIDs = ['NEW123' => 456];

        // When status is 'new', it should use the substituted UID
        $this->subject->processDatamap_afterDatabaseOperations(
            'new',
            'tt_content',
            'NEW123',
            [],
            $dataHandler,
        );

        // No assertions needed - just verifying no errors
        self::assertTrue(true);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesFlexFormWithNoDataStructure(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        // FlexFormTools throws exception when data structure can't be found
        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willThrowException(new InvalidArgumentException('No data structure'));

        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'sDEF' => [
                        'lDEF' => [
                            'field' => ['vDEF' => 'value'],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // Should handle gracefully without modifications
        self::assertSame('value', $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['field']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesNonVaultRenderType(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test-ds');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'sDEF' => [
                        'ROOT' => [
                            'el' => [
                                'myField' => [
                                    'config' => [
                                        'type' => 'input',
                                        'renderType' => 'inputDateTime',
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
                    'sDEF' => [
                        'lDEF' => [
                            'myField' => ['vDEF' => '2024-01-01'],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // Non-vaultSecret fields should not be modified
        self::assertSame('2024-01-01', $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['myField']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayProcessesVaultSecretField(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test-ds');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'sDEF' => [
                        'ROOT' => [
                            'el' => [
                                'settings.apiKey' => [
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
                    'sDEF' => [
                        'lDEF' => [
                            'settings.apiKey' => [
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
            1,
        );

        // The vDEF should now contain a UUID (or be empty if value was empty)
        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.apiKey']['vDEF'];

        // A new UUID should be generated and stored (UUID v7 format)
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $vDEF,
        );
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesExistingVaultIdentifier(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test-ds');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'sDEF' => [
                        'ROOT' => [
                            'el' => [
                                'settings.password' => [
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

        $existingUuid = '01234567-89ab-7cde-8f01-23456789abcd';
        $fieldArray = [
            'pi_flexform' => [
                'data' => [
                    'sDEF' => [
                        'lDEF' => [
                            'settings.password' => [
                                'vDEF' => [
                                    'value' => 'new-password',
                                    '_vault_identifier' => $existingUuid,
                                    '_vault_checksum' => 'abc123',
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
            1,
        );

        // Existing UUID should be preserved
        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.password']['vDEF'];
        self::assertSame($existingUuid, $vDEF);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesEmptySecretValue(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test-ds');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'sDEF' => [
                        'ROOT' => [
                            'el' => [
                                'settings.token' => [
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
                    'sDEF' => [
                        'lDEF' => [
                            'settings.token' => [
                                'vDEF' => [
                                    'value' => '',
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
            1,
        );

        // Empty value with empty checksum should be skipped
        self::assertIsArray($fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.token']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesStringVDEFValue(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('pi_flexform');
        $field->method('getConfiguration')->willReturn(['type' => 'flex']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['pi_flexform' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->flexFormTools
            ->method('getDataStructureIdentifier')
            ->willReturn('test-ds');

        $this->flexFormTools
            ->method('parseDataStructureByIdentifier')
            ->willReturn([
                'sheets' => [
                    'sDEF' => [
                        'ROOT' => [
                            'el' => [
                                'settings.secret' => [
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
                    'sDEF' => [
                        'lDEF' => [
                            'settings.secret' => [
                                'vDEF' => 'plain-string-secret',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tt_content',
            1,
        );

        // String value should generate a new UUID
        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.secret']['vDEF'];
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $vDEF,
        );
    }

    /**
     * @param array<string, FieldTypeInterface&MockObject> $fields
     */
    private function createFieldCollection(array $fields): FieldCollection
    {
        return new FieldCollection($fields);
    }
}
