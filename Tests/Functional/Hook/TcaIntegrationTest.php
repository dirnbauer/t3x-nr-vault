<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Hook;

use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Utility\VaultFieldResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for TCA vault field integration with DataHandler.
 */
#[CoversClass(VaultFieldResolver::class)]
final class TcaIntegrationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private ?string $masterKeyPath = null;

    private bool $setupCompleted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompleted = true;

        // Create a temporary master key for testing
        $this->masterKeyPath = $this->instancePath . '/master.key';
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0o600);

        // Configure extension to use file-based master key
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
            'masterKeySource' => $this->masterKeyPath,
            'autoKeyPath' => $this->masterKeyPath,
            'enableCache' => false,
        ];

        // Register test table with vault field
        $this->registerTestTable();

        // Create backend user for DataHandler
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        // Clean up master key
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        // Clean up test table TCA
        unset($GLOBALS['TCA']['tx_nrvault_test']);

        if ($this->setupCompleted) {
            parent::tearDown();
        }
    }

    #[Test]
    public function dataHandlerStoresVaultSecretOnNewRecord(): void
    {
        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'Test Record',
                    'api_key' => 'my-secret-api-key',
                ],
            ],
        ], []);

        $dataHandler->process_datamap();

        // Get the new UID
        $newUid = $dataHandler->substNEWwithIDs['NEW1'];
        self::assertNotNull($newUid);

        // Verify the vault secret was stored
        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
        $identifier = 'tx_nrvault_test__api_key__' . $newUid;

        self::assertTrue($vaultService->exists($identifier));

        $storedValue = $vaultService->retrieve($identifier);
        self::assertSame('my-secret-api-key', $storedValue);
    }

    #[Test]
    public function dataHandlerRotatesVaultSecretOnUpdate(): void
    {
        // First create a record
        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'Test Record',
                    'api_key' => 'original-secret',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $uid = $dataHandler->substNEWwithIDs['NEW1'];
        $identifier = 'tx_nrvault_test__api_key__' . $uid;

        // Verify original
        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
        self::assertSame('original-secret', $vaultService->retrieve($identifier));
        $versionBefore = $vaultService->getMetadata($identifier)['version'];

        // Update the record
        $dataHandler2 = $this->getDataHandler();
        $dataHandler2->start([
            'tx_nrvault_test' => [
                $uid => [
                    'api_key' => [
                        'value' => 'updated-secret',
                        '_vault_identifier' => $identifier,
                        '_vault_checksum' => 'some-checksum',
                    ],
                ],
            ],
        ], []);
        $dataHandler2->process_datamap();

        // Verify updated value and version increment
        self::assertSame('updated-secret', $vaultService->retrieve($identifier));
        $versionAfter = $vaultService->getMetadata($identifier)['version'];
        self::assertGreaterThan($versionBefore, $versionAfter);
    }

    #[Test]
    public function dataHandlerDeletesVaultSecretOnRecordDelete(): void
    {
        // Create a record first
        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'To Delete',
                    'api_key' => 'delete-me-secret',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $uid = $dataHandler->substNEWwithIDs['NEW1'];
        $identifier = 'tx_nrvault_test__api_key__' . $uid;

        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
        self::assertTrue($vaultService->exists($identifier));

        // Delete the record
        $dataHandler2 = $this->getDataHandler();
        $dataHandler2->start([], [
            'tx_nrvault_test' => [
                $uid => [
                    'delete' => 1,
                ],
            ],
        ]);
        $dataHandler2->process_cmdmap();

        // Verify vault secret was also deleted
        self::assertFalse($vaultService->exists($identifier));
    }

    #[Test]
    public function dataHandlerCopiesVaultSecretOnRecordCopy(): void
    {
        // Create source record
        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'Source Record',
                    'api_key' => 'copy-me-secret',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $sourceUid = $dataHandler->substNEWwithIDs['NEW1'];
        $sourceIdentifier = 'tx_nrvault_test__api_key__' . $sourceUid;

        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);
        self::assertTrue($vaultService->exists($sourceIdentifier));

        // Copy the record
        $dataHandler2 = $this->getDataHandler();
        $dataHandler2->start([], [
            'tx_nrvault_test' => [
                $sourceUid => [
                    'copy' => 0,
                ],
            ],
        ]);
        $dataHandler2->process_cmdmap();

        // Get the copied record UID
        $copyUid = $dataHandler2->copyMappingArray['tx_nrvault_test'][$sourceUid] ?? null;
        self::assertNotNull($copyUid);

        $copyIdentifier = 'tx_nrvault_test__api_key__' . $copyUid;

        // Verify both secrets exist
        self::assertTrue($vaultService->exists($sourceIdentifier));
        self::assertTrue($vaultService->exists($copyIdentifier));

        // Verify they have the same value
        self::assertSame('copy-me-secret', $vaultService->retrieve($sourceIdentifier));
        self::assertSame('copy-me-secret', $vaultService->retrieve($copyIdentifier));
    }

    #[Test]
    public function vaultFieldResolverResolvesStoredSecrets(): void
    {
        // Create a record with vault field
        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'Resolver Test',
                    'api_key' => 'resolver-secret',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $uid = $dataHandler->substNEWwithIDs['NEW1'];
        $identifier = 'tx_nrvault_test__api_key__' . $uid;

        // Simulate data retrieval (like from a plugin)
        $data = [
            'title' => 'Resolver Test',
            'api_key' => $identifier,
        ];

        // Resolve vault fields
        $resolved = VaultFieldResolver::resolveFields($data, ['api_key']);

        self::assertSame('resolver-secret', $resolved['api_key']);
        self::assertSame('Resolver Test', $resolved['title']);
    }

    #[Test]
    public function multipleVaultFieldsAreHandledCorrectly(): void
    {
        // Add second vault field to test table
        $GLOBALS['TCA']['tx_nrvault_test']['columns']['api_secret'] = [
            'label' => 'API Secret',
            'config' => [
                'type' => 'input',
                'renderType' => 'vaultSecret',
            ],
        ];

        $dataHandler = $this->getDataHandler();

        $dataHandler->start([
            'tx_nrvault_test' => [
                'NEW1' => [
                    'pid' => 0,
                    'title' => 'Multi Field Test',
                    'api_key' => 'my-key',
                    'api_secret' => 'my-secret',
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        $uid = $dataHandler->substNEWwithIDs['NEW1'];

        $vaultService = GeneralUtility::makeInstance(VaultServiceInterface::class);

        // Both secrets should be stored
        self::assertSame('my-key', $vaultService->retrieve('tx_nrvault_test__api_key__' . $uid));
        self::assertSame('my-secret', $vaultService->retrieve('tx_nrvault_test__api_secret__' . $uid));
    }

    private function registerTestTable(): void
    {
        // Register TCA for test table
        $GLOBALS['TCA']['tx_nrvault_test'] = [
            'ctrl' => [
                'title' => 'Test Table',
                'label' => 'title',
                'delete' => 'deleted',
                'crdate' => 'crdate',
                'tstamp' => 'tstamp',
            ],
            'columns' => [
                'title' => [
                    'label' => 'Title',
                    'config' => [
                        'type' => 'input',
                    ],
                ],
                'api_key' => [
                    'label' => 'API Key',
                    'config' => [
                        'type' => 'input',
                        'renderType' => 'vaultSecret',
                    ],
                ],
            ],
            'types' => [
                '0' => ['showitem' => 'title,api_key'],
            ],
        ];

        // Create the test table
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionByName(ConnectionPool::DEFAULT_CONNECTION_NAME);

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS tx_nrvault_test (
                uid INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                pid INT(11) UNSIGNED DEFAULT 0 NOT NULL,
                deleted TINYINT(1) UNSIGNED DEFAULT 0 NOT NULL,
                crdate INT(11) UNSIGNED DEFAULT 0 NOT NULL,
                tstamp INT(11) UNSIGNED DEFAULT 0 NOT NULL,
                title VARCHAR(255) DEFAULT \'\' NOT NULL,
                api_key VARCHAR(255) DEFAULT \'\' NOT NULL,
                api_secret VARCHAR(255) DEFAULT \'\' NOT NULL,
                PRIMARY KEY (uid)
            )
        ');
    }

    private function getDataHandler(): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->bypassAccessCheckForRecords = true;

        return $dataHandler;
    }
}
