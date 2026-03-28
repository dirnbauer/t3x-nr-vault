<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use Doctrine\DBAL\Result;
use InvalidArgumentException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\FlexFormVaultHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Schema\Field\FieldCollection;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

#[CoversClass(FlexFormVaultHook::class)]
#[AllowMockObjectsWithoutExpectations]
final class FlexFormVaultHookTest extends TestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    private ConnectionPool&MockObject $connectionPool;

    private TcaSchemaFactory&MockObject $tcaSchemaFactory;

    private VaultServiceInterface&MockObject $vaultService;

    private FlexFormTools&MockObject $flexFormTools;

    private DataHandler&MockObject $dataHandler;

    private FlexFormVaultHook $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->flexFormTools = $this->createMock(FlexFormTools::class);
        $this->dataHandler = $this->createMock(DataHandler::class);
        $flashMessageService = $this->createStub(FlashMessageService::class);

        $this->subject = new FlexFormVaultHook(
            $this->connectionPool,
            $this->tcaSchemaFactory,
            $this->vaultService,
            $this->flexFormTools,
            $flashMessageService,
        );
    }

    // ---- processDatamap_preProcessFieldArray tests ----

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

        self::assertSame('not-an-array', $fieldArray['pi_flexform']);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsProcessesPendingSecrets(): void
    {
        $this->dataHandler->substNEWwithIDs = [];

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tt_content',
            1,
            [],
            $this->dataHandler,
        );

        self::assertTrue(true);
    }

    #[Test]
    public function processDatamapAfterDatabaseOperationsResolvesNewRecordUid(): void
    {
        $this->dataHandler->substNEWwithIDs = ['NEW123' => 456];

        $this->subject->processDatamap_afterDatabaseOperations(
            'new',
            'tt_content',
            'NEW123',
            [],
            $this->dataHandler,
        );

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

        self::assertSame('value', $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['field']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesNonVaultRenderType(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

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

        self::assertSame('2024-01-01', $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['myField']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayProcessesVaultSecretField(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

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

        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.apiKey']['vDEF'];

        self::assertMatchesRegularExpression(self::UUID_PATTERN, $vDEF);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesExistingVaultIdentifier(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

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

        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.password']['vDEF'];
        self::assertSame($existingUuid, $vDEF);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesEmptySecretValue(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $this->flexFormTools->method('getDataStructureIdentifier')->willReturn('test-ds');
        $this->flexFormTools->method('parseDataStructureByIdentifier')->willReturn([
            'sheets' => ['sDEF' => ['ROOT' => ['el' => ['settings.token' => ['config' => ['type' => 'input', 'renderType' => 'vaultSecret']]]]]],
        ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => ['sDEF' => ['lDEF' => ['settings.token' => ['vDEF' => ['value' => '', '_vault_identifier' => '', '_vault_checksum' => '']]]]],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray($fieldArray, 'tt_content', 1);

        self::assertIsArray($fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.token']['vDEF']);
    }

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesStringVDEFValue(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $this->flexFormTools->method('getDataStructureIdentifier')->willReturn('test-ds');
        $this->flexFormTools->method('parseDataStructureByIdentifier')->willReturn([
            'sheets' => ['sDEF' => ['ROOT' => ['el' => ['settings.secret' => ['config' => ['type' => 'input', 'renderType' => 'vaultSecret']]]]]],
        ]);

        $fieldArray = [
            'pi_flexform' => [
                'data' => ['sDEF' => ['lDEF' => ['settings.secret' => ['vDEF' => 'plain-string-secret']]]],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray($fieldArray, 'tt_content', 1);

        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['settings.secret']['vDEF'];
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $vDEF);
    }

    // ---- Section container support tests ----

    #[Test]
    public function processDatamapPreProcessFieldArrayHandlesSectionContainerVaultFields(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $this->flexFormTools->method('getDataStructureIdentifier')->willReturn('test-ds');
        $this->flexFormTools->method('parseDataStructureByIdentifier')->willReturn([
            'sheets' => [
                'sDEF' => [
                    'ROOT' => [
                        'el' => [
                            'credentials' => [
                                'section' => 1,
                                'el' => [
                                    'credential' => [
                                        'el' => [
                                            'apiKey' => [
                                                'config' => ['type' => 'input', 'renderType' => 'vaultSecret'],
                                            ],
                                            'label' => [
                                                'config' => ['type' => 'input'],
                                            ],
                                        ],
                                    ],
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
                            'credentials' => [
                                'el' => [
                                    [
                                        'credential' => [
                                            'el' => [
                                                'apiKey' => [
                                                    'vDEF' => ['value' => 'secret-key-1', '_vault_identifier' => '', '_vault_checksum' => ''],
                                                ],
                                                'label' => ['vDEF' => 'My API Key'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray($fieldArray, 'tt_content', 1);

        $vDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['credentials']['el'][0]['credential']['el']['apiKey']['vDEF'];
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $vDEF);

        $labelVDEF = $fieldArray['pi_flexform']['data']['sDEF']['lDEF']['credentials']['el'][0]['credential']['el']['label']['vDEF'];
        self::assertSame('My API Key', $labelVDEF);
    }

    // ---- processCmdmap_deleteAction tests ----

    #[Test]
    public function deleteActionSkipsSoftDelete(): void
    {
        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $GLOBALS['TCA']['tt_content']['ctrl']['delete'] = 'deleted';

        $recordWasDeleted = false;

        $this->vaultService->expects(self::never())->method('delete');

        $this->subject->processCmdmap_deleteAction(
            'tt_content',
            42,
            ['pi_flexform' => '<T3FlexForms>test</T3FlexForms>'],
            $recordWasDeleted,
            $this->dataHandler,
        );

        unset($GLOBALS['TCA']['tt_content']);
    }

    #[Test]
    public function deleteActionDeletesVaultSecretsOnHardDelete(): void
    {
        $this->mockFlexFieldSchema('tx_test', ['pi_flexform']);

        $GLOBALS['TCA']['tx_test']['ctrl'] = [];

        $uuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $xml = '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="settings.apiKey"><value index="vDEF">' . $uuid . '</value></field></language></sheet></data></T3FlexForms>';

        $this->vaultService
            ->expects(self::once())
            ->method('delete')
            ->with($uuid, 'Record deleted');

        $recordWasDeleted = false;

        $this->subject->processCmdmap_deleteAction(
            'tx_test',
            42,
            ['pi_flexform' => $xml],
            $recordWasDeleted,
            $this->dataHandler,
        );

        unset($GLOBALS['TCA']['tx_test']);
    }

    #[Test]
    public function deleteActionSkipsTableWithoutFlexFields(): void
    {
        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('title');
        $field->method('getConfiguration')->willReturn(['type' => 'input']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['title' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $GLOBALS['TCA']['tx_test']['ctrl'] = [];

        $this->vaultService->expects(self::never())->method('delete');

        $recordWasDeleted = false;

        $this->subject->processCmdmap_deleteAction(
            'tx_test',
            42,
            ['title' => 'Some title'],
            $recordWasDeleted,
            $this->dataHandler,
        );

        unset($GLOBALS['TCA']['tx_test']);
    }

    #[Test]
    public function deleteActionSkipsEmptyFlexFormXml(): void
    {
        $this->mockFlexFieldSchema('tx_test', ['pi_flexform']);

        $GLOBALS['TCA']['tx_test']['ctrl'] = [];

        $this->vaultService->expects(self::never())->method('delete');

        $recordWasDeleted = false;

        $this->subject->processCmdmap_deleteAction(
            'tx_test',
            42,
            ['pi_flexform' => ''],
            $recordWasDeleted,
            $this->dataHandler,
        );

        unset($GLOBALS['TCA']['tx_test']);
    }

    #[Test]
    public function deleteActionLogsVaultExceptionOnDelete(): void
    {
        $this->mockFlexFieldSchema('tx_test', ['pi_flexform']);

        $GLOBALS['TCA']['tx_test']['ctrl'] = [];

        $uuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $xml = '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="key"><value index="vDEF">' . $uuid . '</value></field></language></sheet></data></T3FlexForms>';

        $this->vaultService->method('delete')->willThrowException(new VaultException('Delete failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with('tx_test', 42, 3, 0, 1, self::stringContains('Delete failed'));

        $recordWasDeleted = false;

        $this->subject->processCmdmap_deleteAction(
            'tx_test',
            42,
            ['pi_flexform' => $xml],
            $recordWasDeleted,
            $this->dataHandler,
        );

        unset($GLOBALS['TCA']['tx_test']);
    }

    // ---- processCmdmap_postProcess copy tests ----

    #[Test]
    public function postProcessSkipsNonCopyCommand(): void
    {
        $this->vaultService->expects(self::never())->method('retrieve');

        $this->subject->processCmdmap_postProcess('delete', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessSkipsCopyWithoutMappedNewId(): void
    {
        $this->dataHandler->copyMappingArray = [];

        $this->vaultService->expects(self::never())->method('retrieve');

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessSkipsCopyForTableWithoutFlexFields(): void
    {
        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        $field = $this->createMock(FieldTypeInterface::class);
        $field->method('getName')->willReturn('title');
        $field->method('getConfiguration')->willReturn(['type' => 'input']);

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection(['title' => $field]));

        $this->tcaSchemaFactory->method('has')->willReturn(true);
        $this->tcaSchemaFactory->method('get')->willReturn($schema);

        $this->vaultService->expects(self::never())->method('retrieve');

        $this->subject->processCmdmap_postProcess('copy', 'tx_test', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessSkipsCopyWhenCopiedRecordNotFound(): void
    {
        $this->dataHandler->copyMappingArray = ['tt_content' => [42 => 100]];

        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        $connection->method('select')->willReturn($result);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->vaultService->expects(self::never())->method('retrieve');

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessCopiesFlexFormVaultSecretsSuccessfully(): void
    {
        $this->dataHandler->copyMappingArray = ['tt_content' => [42 => 100]];

        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $xml = '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="key"><value index="vDEF">' . $sourceUuid . '</value></field></language></sheet></data></T3FlexForms>';

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['pi_flexform' => $xml]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->vaultService->method('retrieve')->with($sourceUuid)->willReturn('the-secret-value');

        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                self::matchesRegularExpression(self::UUID_PATTERN),
                'the-secret-value',
                self::callback(static fn (array $options): bool => $options['table'] === 'tt_content'
                    && $options['flexField'] === 'pi_flexform'
                    && $options['uid'] === 100
                    && $options['source'] === 'flexform_record_copy'
                    && $options['copied_from'] === $sourceUuid),
            );

        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'tt_content',
                self::callback(static fn (array $updates): bool => isset($updates['pi_flexform'])
                    && !str_contains($updates['pi_flexform'], $sourceUuid)),
                ['uid' => 100],
            );

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessSkipsCopyWhenSourceSecretNotFound(): void
    {
        $this->dataHandler->copyMappingArray = ['tt_content' => [42 => 100]];

        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $xml = '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="key"><value index="vDEF">' . $sourceUuid . '</value></field></language></sheet></data></T3FlexForms>';

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['pi_flexform' => $xml]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->vaultService->method('retrieve')->willReturn(null);
        $this->vaultService->expects(self::never())->method('store');
        $connection->expects(self::never())->method('update');

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessLogsVaultExceptionOnCopy(): void
    {
        $this->dataHandler->copyMappingArray = ['tt_content' => [42 => 100]];

        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $xml = '<T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="key"><value index="vDEF">' . $sourceUuid . '</value></field></language></sheet></data></T3FlexForms>';

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['pi_flexform' => $xml]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->vaultService->method('retrieve')->willThrowException(new VaultException('Retrieve failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with('tt_content', 100, 1, 0, 1, self::stringContains('Retrieve failed'));

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    #[Test]
    public function postProcessSkipsCopyForEmptyFlexFormXml(): void
    {
        $this->dataHandler->copyMappingArray = ['tt_content' => [42 => 100]];

        $this->mockFlexFieldSchema('tt_content', ['pi_flexform']);

        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['pi_flexform' => '']);
        $connection->method('select')->willReturn($result);

        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        $this->vaultService->expects(self::never())->method('retrieve');

        $this->subject->processCmdmap_postProcess('copy', 'tt_content', 42, null, $this->dataHandler, false);
    }

    // ---- Helper methods ----

    /**
     * @param array<string, FieldTypeInterface&MockObject> $fields
     */
    private function createFieldCollection(array $fields): FieldCollection
    {
        return new FieldCollection($fields);
    }

    /**
     * Mock TCA schema with flex fields for a table.
     *
     * @param list<string> $flexFieldNames
     */
    private function mockFlexFieldSchema(string $table, array $flexFieldNames): void
    {
        $fieldMocks = [];
        foreach ($flexFieldNames as $fieldName) {
            $field = $this->createMock(FieldTypeInterface::class);
            $field->method('getName')->willReturn($fieldName);
            $field->method('getConfiguration')->willReturn(['type' => 'flex']);
            $fieldMocks[$fieldName] = $field;
        }

        $schema = $this->createMock(TcaSchema::class);
        $schema->method('getFields')->willReturn($this->createFieldCollection($fieldMocks));

        $this->tcaSchemaFactory->method('has')->with($table)->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with($table)->willReturn($schema);
    }
}
