<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Hook;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Hook\DataHandlerHook;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(DataHandlerHook::class)]
final class DataHandlerHookTest extends UnitTestCase
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    protected bool $resetSingletonInstances = true;

    private DataHandlerHook $subject;

    private VaultServiceInterface&MockObject $vaultService;

    private AuditLogServiceInterface&MockObject $auditLogService;

    private DataHandler&MockObject $dataHandler;

    private ConnectionPool&MockObject $connectionPool;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->subject = new DataHandlerHook($this->connectionPool);

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
    public function preProcessFieldArrayGeneratesUuidForNewSecret(): void
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

        // Value should be replaced with a UUID
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
    }

    #[Test]
    public function preProcessFieldArrayKeepsExistingUuidForUpdate(): void
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

        // Should generate UUID for string value
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
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

        // Both vault fields should be replaced with UUIDs
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_key']);
        self::assertMatchesRegularExpression(self::UUID_PATTERN, $fieldArray['api_secret']);
        // UUIDs should be different for each field
        self::assertNotSame($fieldArray['api_key'], $fieldArray['api_secret']);
        // Non-vault field unchanged
        self::assertSame('Test Title', $fieldArray['title']);
    }
}
