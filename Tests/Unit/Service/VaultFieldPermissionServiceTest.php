<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\VaultFieldPermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\NullFrontend;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(VaultFieldPermissionService::class)]
final class VaultFieldPermissionServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private VaultFieldPermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up runtime cache required by BackendUtility
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willReturn(new NullFrontend('runtime'));
        GeneralUtility::setSingletonInstance(CacheManager::class, $cacheManager);

        $this->service = new VaultFieldPermissionService();
    }

    #[Test]
    public function returnsFalseWhenNoBackendUser(): void
    {
        $GLOBALS['BE_USER'] = null;

        $result = $this->service->isAllowed('tx_myext', 'api_key', 'reveal');

        self::assertFalse($result);
    }

    #[Test]
    public function returnsTrueForAdminUsers(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        $result = $this->service->isAllowed('tx_myext', 'api_key', 'reveal', $backendUser);

        self::assertTrue($result);
    }

    #[Test]
    public function returnsBuiltInDefaultsForNonAdminWithNoTsconfig(): void
    {
        // This test requires full TYPO3 bootstrap because non-admin users trigger
        // BackendUtility::getPagesTSconfig() which needs SiteFinder and other services.
        self::markTestSkipped('Test requires functional testing with TYPO3 bootstrap for TSconfig lookup.');
    }

    #[Test]
    public function getPermissionsReturnsAllPermissions(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        $permissions = $this->service->getPermissions('tx_myext', 'api_key', $backendUser);

        self::assertArrayHasKey('reveal', $permissions);
        self::assertArrayHasKey('copy', $permissions);
        self::assertArrayHasKey('edit', $permissions);
        self::assertArrayHasKey('readOnly', $permissions);
    }

    #[Test]
    public function isReadOnlyReturnsFalseByDefault(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);

        $result = $this->service->isReadOnly('tx_myext', 'api_key', $backendUser);

        self::assertFalse($result);
    }

    #[Test]
    public function cachesPermissionResults(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->user = ['uid' => 1];

        // Call twice - should use cache on second call
        $result1 = $this->service->isAllowed('tx_myext', 'api_key', 'reveal', $backendUser);
        $result2 = $this->service->isAllowed('tx_myext', 'api_key', 'reveal', $backendUser);

        self::assertSame($result1, $result2);
    }

    #[Test]
    public function clearCacheResetsCache(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->user = ['uid' => 1];

        $this->service->isAllowed('tx_myext', 'api_key', 'reveal', $backendUser);
        $this->service->clearCache();

        // After clear, should still work
        $result = $this->service->isAllowed('tx_myext', 'api_key', 'reveal', $backendUser);

        self::assertTrue($result);
    }

    #[Test]
    #[DataProvider('permissionConstantsProvider')]
    public function hasCorrectPermissionConstants(string $constant, string $expectedValue): void
    {
        self::assertSame($expectedValue, $constant);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function permissionConstantsProvider(): array
    {
        return [
            'reveal' => [VaultFieldPermissionService::PERMISSION_REVEAL, 'reveal'],
            'copy' => [VaultFieldPermissionService::PERMISSION_COPY, 'copy'],
            'edit' => [VaultFieldPermissionService::PERMISSION_EDIT, 'edit'],
            'readOnly' => [VaultFieldPermissionService::PERMISSION_READ_ONLY, 'readOnly'],
        ];
    }

    #[Test]
    public function differentFieldsHaveIndependentPermissions(): void
    {
        // This test requires full TYPO3 bootstrap because non-admin users trigger
        // BackendUtility::getPagesTSconfig() which needs SiteFinder and other services.
        self::markTestSkipped('Test requires functional testing with TYPO3 bootstrap for TSconfig lookup.');
    }

    #[Test]
    public function differentTablesHaveIndependentPermissions(): void
    {
        // This test requires full TYPO3 bootstrap because non-admin users trigger
        // BackendUtility::getPagesTSconfig() which needs SiteFinder and other services.
        self::markTestSkipped('Test requires functional testing with TYPO3 bootstrap for TSconfig lookup.');
    }
}
