<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\VaultFieldPermission;
use Netresearch\NrVault\Service\VaultFieldPermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(VaultFieldPermissionService::class)]
final class VaultFieldPermissionServiceTest extends TestCase
{
    private VaultFieldPermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VaultFieldPermissionService();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['BE_USER']);
    }

    #[Test]
    public function returnsFalseWhenNoBackendUser(): void
    {
        $result = $this->service->isAllowed('tx_table', 'field', VaultFieldPermission::Reveal);

        self::assertFalse($result);
    }

    #[Test]
    public function adminHasFullAccessExceptReadOnly(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        self::assertTrue($this->service->isAllowed('any_table', 'any_field', VaultFieldPermission::Reveal, $backendUser));
        self::assertTrue($this->service->isAllowed('any_table', 'any_field', VaultFieldPermission::Copy, $backendUser));
        self::assertTrue($this->service->isAllowed('any_table', 'any_field', VaultFieldPermission::Edit, $backendUser));
        self::assertFalse($this->service->isAllowed('any_table', 'any_field', VaultFieldPermission::ReadOnly, $backendUser));
    }

    #[Test]
    public function getPermissionsReturnsAllPermissions(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        $permissions = $this->service->getPermissions('table', 'field', $backendUser);

        self::assertArrayHasKey('reveal', $permissions);
        self::assertArrayHasKey('copy', $permissions);
        self::assertArrayHasKey('edit', $permissions);
        self::assertArrayHasKey('readOnly', $permissions);
        self::assertTrue($permissions['reveal']);
        self::assertFalse($permissions['readOnly']);
    }

    #[Test]
    public function isReadOnlyDelegatesCorrectly(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        // Admins are never read-only
        self::assertFalse($this->service->isReadOnly('table', 'field', $backendUser));
    }

    #[Test]
    public function clearCacheResetsCache(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        // First call - caches result
        $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal, $backendUser);

        // Clear cache
        $this->service->clearCache();

        // Should work without errors (no assertion needed, just verify no crash)
        $result = $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal, $backendUser);
        self::assertTrue($result);
    }

    #[Test]
    public function usesGlobalBackendUserWhenNotProvided(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);
        $GLOBALS['BE_USER'] = $backendUser;

        $result = $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal);

        self::assertTrue($result);
    }

    #[Test]
    public function cachesPermissionResults(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        // Call twice - second should use cache
        $result1 = $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal, $backendUser);
        $result2 = $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal, $backendUser);

        self::assertSame($result1, $result2);
    }

    private function createMockBackendUser(bool $isAdmin = false, int $uid = 1): BackendUserAuthentication&MockObject
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn($isAdmin);
        $backendUser->user = ['uid' => $uid];

        return $backendUser;
    }
}
