<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use Doctrine\DBAL\Result;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\DataHandlerHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use RuntimeException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Schema\Field\FieldCollection;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(DataHandlerHook::class)]
#[AllowMockObjectsWithoutExpectations]
final class DataHandlerHookTest extends UnitTestCase
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    protected bool $resetSingletonInstances = true;

    private DataHandlerHook $subject;

    private VaultServiceInterface&MockObject $vaultService;

    private AuditLogServiceInterface&MockObject $auditLogService;

    private DataHandler&MockObject $dataHandler;

    private ConnectionPool&MockObject $connectionPool;

    private TcaSchemaFactory&MockObject $tcaSchemaFactory;

    private FlashMessageService&MockObject $flashMessageService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->auditLogService = $this->createMock(AuditLogServiceInterface::class);
        $this->flashMessageService = $this->createMock(FlashMessageService::class);
        $this->dataHandler = $this->createMock(DataHandler::class);

        $this->subject = new DataHandlerHook(
            $this->connectionPool,
            $this->tcaSchemaFactory,
            $this->vaultService,
            $this->flashMessageService,
        );

        GeneralUtility::addInstance(AuditLogServiceInterface::class, $this->auditLogService);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function preProcessFieldArrayIgnoresFieldsWithoutVaultRenderType(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'title' => ['type' => 'input'],
        ]);

        $fieldArray = ['title' => 'Test Title'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        self::assertSame(['title' => 'Test Title'], $fieldArray);
    }

    #[Test]
    public function preProcessFieldArrayGeneratesUuidForNewSecret(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

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

        // Value should be replaced with a UUID
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayKeepsExistingUuidForUpdate(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $fieldArray = [
            'api_key' => [
                'value' => 'updated-secret',
                '_vault_identifier' => $existingUuid,
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        // Should keep existing UUID
        self::assertSame($existingUuid, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayHandlesStringValue(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $fieldArray = ['api_key' => 'direct-string-value'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        // Should generate UUID for string value
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayRemovesEmptyValueWithNoChecksum(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

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
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

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
                self::matchesRegularExpression(self::UUID_PATTERN),
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
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $fieldArray = [
            'api_key' => [
                'value' => 'updated-secret',
                '_vault_identifier' => $existingUuid,
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
                $existingUuid,
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
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $fieldArray = [
            'api_key' => [
                'value' => '',
                '_vault_identifier' => $existingUuid,
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
                $existingUuid,
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
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock connection for rollback
        $connection = $this->createMock(Connection::class);
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Mock flash message queue
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

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
    public function afterDatabaseOperationsRollsBackFieldOnNewSecretFailure(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock connection for rollback - new secret should clear field (empty string)
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with('tx_test', ['api_key' => ''], ['uid' => 42]);
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Mock flash message queue
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

        $fieldArray = ['api_key' => 'new-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsRollsBackFieldPreservingIdentifierOnUpdateFailure(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        // Mock connection for rollback - update failure should keep existing identifier
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('update')
            ->with('tx_test', ['api_key' => $existingUuid], ['uid' => 42]);
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Mock flash message queue
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

        $fieldArray = [
            'api_key' => [
                'value' => 'updated-secret',
                '_vault_identifier' => $existingUuid,
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new VaultException('Rotate failed'));

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsAddsFlashMessageOnVaultFailure(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock connection for rollback
        $connection = $this->createMock(Connection::class);
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Flash message queue should receive the error message
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $flashMessageQueue
            ->expects(self::once())
            ->method('addMessage');
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

        $fieldArray = ['api_key' => 'new-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsRollbackSkippedWhenUidIsZero(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Connection should NOT be called for rollback when uid is 0
        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

        // Mock flash message queue
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

        $fieldArray = ['api_key' => 'new-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            'NEW123',
        );

        // Don't set substNEWwithIDs - uid will remain non-numeric string -> cast to 0
        $this->dataHandler->substNEWwithIDs = [];

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        $this->subject->processDatamap_afterDatabaseOperations(
            'new',
            'tx_test',
            'NEW123',
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function cmdmapPreProcessIgnoresNonDeleteCommands(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

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
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

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
    public function multipleVaultFieldsAreProcessed(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
            'api_secret' => ['type' => 'input', 'renderType' => 'vaultSecret'],
            'title' => ['type' => 'input'],
        ]);

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

        // Both vault fields should be replaced with UUIDs
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_secret']);
        // UUIDs should be different for each field
        self::assertNotSame($fieldArray['api_key'], $fieldArray['api_secret']);
        // Non-vault field unchanged
        self::assertSame('Test Title', $fieldArray['title']);
    }

    #[Test]
    public function preProcessFieldArrayIgnoresTablesWithoutSchema(): void
    {
        $this->tcaSchemaFactory->method('has')->with('unknown_table')->willReturn(false);

        $fieldArray = ['field' => 'value'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'unknown_table',
            1,
        );

        self::assertSame(['field' => 'value'], $fieldArray);
    }

    #[Test]
    public function preProcessFieldArrayHandlesArrayWithValueIndexZero(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Format with index 0 instead of 'value' key
        $fieldArray = [
            'api_key' => [
                0 => 'my-secret-key',
                '_vault_identifier' => '',
                '_vault_checksum' => '',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayHandlesIntegerValue(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $fieldArray = ['api_key' => 12345];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArraySetsEmptyStringWhenClearing(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $fieldArray = [
            'api_key' => [
                'value' => '',
                '_vault_identifier' => $existingUuid,
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        // Should be empty string when clearing
        self::assertSame('', $fieldArray['api_key']);
    }

    #[Test]
    public function cmdmapPreProcessIgnoresTablesWithoutVaultFields(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'title' => ['type' => 'input'],
        ]);

        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

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
    public function cmdmapPostProcessIgnoresTablesWithoutVaultFields(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'title' => ['type' => 'input'],
        ]);

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        $this->connectionPool->expects(self::never())->method('getConnectionForTable');

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
    public function generateUuidReturnsValidUuidV7Format(): void
    {
        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('generateUuid');

        $uuid1 = $method->invoke($this->subject);
        $uuid2 = $method->invoke($this->subject);

        // Both should be valid UUID v7 format
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $uuid1);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $uuid2);

        // Should be different
        self::assertNotSame($uuid1, $uuid2);
    }

    #[Test]
    public function generateUuidIsTimeOrdered(): void
    {
        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('generateUuid');

        $uuids = [];
        for ($i = 0; $i < 5; $i++) {
            $uuids[] = $method->invoke($this->subject);
            usleep(1000); // 1ms delay
        }

        // UUIDs should be in ascending order (time-ordered)
        $sorted = $uuids;
        sort($sorted);
        self::assertSame($sorted, $uuids, 'UUIDs should be time-ordered');
    }

    #[Test]
    public function preProcessFieldArrayHandlesNonStringNonIntValue(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Non-string, non-int value should be treated as empty
        $fieldArray = [
            'api_key' => [
                'value' => ['nested' => 'array'],
                '_vault_identifier' => '',
                '_vault_checksum' => '',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        // Empty value with no checksum should be removed
        self::assertArrayNotHasKey('api_key', $fieldArray);
    }

    #[Test]
    public function afterDatabaseOperationsHandlesStatusUpdate(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Setup pending secrets via preProcess with existing UUID
        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';
        $fieldArray = [
            'api_key' => [
                'value' => 'updated-secret',
                '_vault_identifier' => $existingUuid,
                '_vault_checksum' => 'existing-checksum',
            ],
        ];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        // Rotate should be called (not store)
        $this->vaultService
            ->expects(self::once())
            ->method('rotate')
            ->with($existingUuid, 'updated-secret', 'TCA field updated');

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function afterDatabaseOperationsCleansPendingSecrets(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $fieldArray = ['api_key' => 'test-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );

        // Calling again should not trigger vault operations (pending cleaned up)
        $this->vaultService
            ->expects(self::never())
            ->method('store');

        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );
    }

    #[Test]
    public function getVaultFieldNamesReturnsEmptyForNonExistentTable(): void
    {
        $this->tcaSchemaFactory->method('has')->with('nonexistent')->willReturn(false);

        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('getVaultFieldNames');

        $result = $method->invoke($this->subject, 'nonexistent');

        self::assertSame([], $result);
    }

    #[Test]
    public function getVaultFieldNamesReturnsOnlyVaultSecretFields(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
            'title' => ['type' => 'input'],
            'password' => ['type' => 'password', 'renderType' => 'passwordGenerator'],
            'secret' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $reflection = new ReflectionClass($this->subject);
        $method = $reflection->getMethod('getVaultFieldNames');

        $result = $method->invoke($this->subject, 'tx_test');

        self::assertCount(2, $result);
        self::assertContains('api_key', $result);
        self::assertContains('secret', $result);
        self::assertNotContains('title', $result);
        self::assertNotContains('password', $result);
    }

    #[Test]
    public function cmdmapPreProcessDeletesVaultSecretSuccessfully(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => $existingUuid]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

        $this->vaultService
            ->expects(self::once())
            ->method('delete')
            ->with($existingUuid, 'Record deleted');

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
    public function cmdmapPreProcessLogsVaultExceptionOnDelete(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $existingUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => $existingUuid]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

        $this->vaultService
            ->method('delete')
            ->willThrowException(new VaultException('Delete failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with(
                'tx_test',
                42,
                3,
                0,
                1,
                self::stringContains('api_key'),
            );

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
    public function cmdmapPreProcessSkipsDeleteWhenRecordNotFound(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock database connection - record not found
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

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
    public function cmdmapPreProcessSkipsDeleteWhenVaultIdentifierEmpty(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock database connection - vault field is empty
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => '']);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

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
    public function cmdmapPostProcessCopiesVaultSecretSuccessfully(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        // Mock copy mapping
        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => $sourceUuid]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

        // Retrieve source secret
        $this->vaultService
            ->method('retrieve')
            ->with($sourceUuid)
            ->willReturn('the-secret-value');

        // Store new secret with new UUID
        $this->vaultService
            ->expects(self::once())
            ->method('store')
            ->with(
                self::matchesRegularExpression(self::UUID_PATTERN),
                'the-secret-value',
                self::callback(static fn (array $options): bool => $options['table'] === 'tx_test'
                    && $options['field'] === 'api_key'
                    && $options['uid'] === 100
                    && $options['source'] === 'record_copy'),
            );

        // Update the copied record
        $connection
            ->expects(self::once())
            ->method('update')
            ->with(
                'tx_test',
                self::callback(static fn (array $updates): bool => isset($updates['api_key'])
                    && preg_match(self::UUID_PATTERN, $updates['api_key']) === 1),
                ['uid' => 100],
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
    public function cmdmapPostProcessSkipsCopyWhenSourceRecordNotFound(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection - source record not found
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(false);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($connection);

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
    public function cmdmapPostProcessSkipsCopyWhenSourceValueNull(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => $sourceUuid]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($connection);

        // Retrieve returns null (secret not found in vault)
        $this->vaultService
            ->method('retrieve')
            ->with($sourceUuid)
            ->willReturn(null);

        $this->vaultService
            ->expects(self::never())
            ->method('store');

        // No update should happen
        $connection
            ->expects(self::never())
            ->method('update');

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
    public function cmdmapPreProcessSkipsNonStringVaultIdentifier(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock database connection - vault field is an integer (non-string)
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => 12345]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->with('tx_test')
            ->willReturn($connection);

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
    public function cmdmapPostProcessSkipsNonStringSourceIdentifier(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection - vault field is an integer (non-string)
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => 12345]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($connection);

        $this->vaultService
            ->expects(self::never())
            ->method('retrieve');

        // No update should happen
        $connection
            ->expects(self::never())
            ->method('update');

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
    public function cmdmapPostProcessSkipsEmptySourceIdentifier(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection - vault field is empty string
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => '']);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($connection);

        $this->vaultService
            ->expects(self::never())
            ->method('retrieve');

        $connection
            ->expects(self::never())
            ->method('update');

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
    public function rollBackFieldHandlesExceptionGracefully(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock connection that throws on rollback update
        $connection = $this->createMock(Connection::class);
        $connection
            ->method('update')
            ->willThrowException(new RuntimeException('DB connection lost'));
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Mock flash message queue
        $flashMessageQueue = $this->createMock(FlashMessageQueue::class);
        $this->flashMessageService->method('getMessageQueueByIdentifier')->willReturn($flashMessageQueue);

        $fieldArray = ['api_key' => 'new-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        // Should not throw - rollback failure is silently caught
        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );

        // If we get here, the exception was properly caught
        self::assertTrue(true);
    }

    #[Test]
    public function addFlashMessageHandlesExceptionGracefully(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        // Mock connection for rollback
        $connection = $this->createMock(Connection::class);
        $this->connectionPool->method('getConnectionForTable')->willReturn($connection);

        // Flash message service throws exception
        $this->flashMessageService
            ->method('getMessageQueueByIdentifier')
            ->willThrowException(new RuntimeException('Flash service unavailable'));

        $fieldArray = ['api_key' => 'new-secret'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            42,
        );

        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        // Should not throw - flash message failure is silently caught
        $this->subject->processDatamap_afterDatabaseOperations(
            'update',
            'tx_test',
            42,
            $fieldArray,
            $this->dataHandler,
        );

        // If we get here, the exception was properly caught
        self::assertTrue(true);
    }

    #[Test]
    public function preProcessFieldArraySkipsVaultFieldNotInFieldArray(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
            'title' => ['type' => 'input'],
        ]);

        // Only 'title' is in fieldArray, 'api_key' vault field is not
        $fieldArray = ['title' => 'Test Title'];

        $this->subject->processDatamap_preProcessFieldArray(
            $fieldArray,
            'tx_test',
            1,
        );

        // Field array should remain unchanged - vault field was not present
        self::assertSame(['title' => 'Test Title'], $fieldArray);
        self::assertArrayNotHasKey('api_key', $fieldArray);
    }

    #[Test]
    public function cmdmapPostProcessLogsVaultExceptionOnCopy(): void
    {
        $this->mockTcaSchemaForTable('tx_test', [
            'api_key' => ['type' => 'input', 'renderType' => 'vaultSecret'],
        ]);

        $sourceUuid = '01937b6e-4b6c-7abc-8def-0123456789ab';

        $this->dataHandler->copyMappingArray = ['tx_test' => [42 => 100]];

        // Mock database connection
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['api_key' => $sourceUuid]);
        $connection->method('select')->willReturn($result);

        $this->connectionPool
            ->method('getConnectionForTable')
            ->willReturn($connection);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(new VaultException('Retrieve failed'));

        $this->dataHandler
            ->expects(self::once())
            ->method('log')
            ->with(
                'tx_test',
                100,
                1,
                0,
                1,
                self::stringContains('api_key'),
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

    /**
     * Create a mock TcaSchema for a table with given fields.
     *
     * @param array<string, array{type: string, renderType?: string}> $fields
     */
    private function mockTcaSchemaForTable(string $table, array $fields): void
    {
        $schema = $this->createMock(TcaSchema::class);
        $fieldMocks = [];

        foreach ($fields as $fieldName => $config) {
            $field = $this->createMock(FieldTypeInterface::class);
            $field->method('getName')->willReturn($fieldName);
            $field->method('getConfiguration')->willReturn($config);
            $fieldMocks[$fieldName] = $field;
        }

        $fieldCollection = new FieldCollection($fieldMocks);
        $schema->method('getFields')->willReturn($fieldCollection);

        $this->tcaSchemaFactory->method('has')->with($table)->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with($table)->willReturn($schema);
    }
}
