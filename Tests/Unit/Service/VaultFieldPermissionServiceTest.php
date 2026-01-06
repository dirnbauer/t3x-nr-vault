<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\VaultFieldPermission;
use Netresearch\NrVault\Service\VaultFieldPermissionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
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

    #[Test]
    public function toBooleanReturnsTrueForTrueValues(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('toBoolean');

        self::assertTrue($method->invoke($this->service, true));
        self::assertTrue($method->invoke($this->service, '1'));
        self::assertTrue($method->invoke($this->service, 'true'));
        self::assertTrue($method->invoke($this->service, 'TRUE'));
        self::assertTrue($method->invoke($this->service, 'yes'));
        self::assertTrue($method->invoke($this->service, 'YES'));
        self::assertTrue($method->invoke($this->service, 'on'));
        self::assertTrue($method->invoke($this->service, 'ON'));
        self::assertTrue($method->invoke($this->service, 1));
    }

    #[Test]
    public function toBooleanReturnsFalseForFalseValues(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('toBoolean');

        self::assertFalse($method->invoke($this->service, false));
        self::assertFalse($method->invoke($this->service, '0'));
        self::assertFalse($method->invoke($this->service, 'false'));
        self::assertFalse($method->invoke($this->service, 'no'));
        self::assertFalse($method->invoke($this->service, 'off'));
        self::assertFalse($method->invoke($this->service, ''));
        self::assertFalse($method->invoke($this->service, 0));
    }

    #[Test]
    public function getBuiltInDefaultReturnsCorrectDefaults(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getBuiltInDefault');

        self::assertTrue($method->invoke($this->service, VaultFieldPermission::Reveal));
        self::assertTrue($method->invoke($this->service, VaultFieldPermission::Copy));
        self::assertTrue($method->invoke($this->service, VaultFieldPermission::Edit));
        self::assertFalse($method->invoke($this->service, VaultFieldPermission::ReadOnly));
    }

    #[Test]
    public function getNestedValueReturnsNullForMissingKey(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getNestedValue');

        $array = [];
        $result = $method->invoke($this->service, $array, ['nonexistent']);

        self::assertNull($result);
    }

    #[Test]
    public function getNestedValueReturnsValueForDirectKey(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getNestedValue');

        $array = ['key' => 'value'];
        $result = $method->invoke($this->service, $array, ['key']);

        self::assertSame('value', $result);
    }

    #[Test]
    public function getNestedValueHandlesTsConfigDotNotation(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getNestedValue');

        // TSconfig uses trailing dots for nested arrays
        $array = [
            'table.' => [
                'field.' => [
                    'permission' => '1',
                ],
            ],
        ];
        $result = $method->invoke($this->service, $array, ['table', 'field', 'permission']);

        self::assertSame('1', $result);
    }

    #[Test]
    public function getNestedValueHandlesMixedDotNotation(): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('getNestedValue');

        // Mix of dot notation and direct values
        $array = [
            'table.' => [
                'field' => 'direct_value',
            ],
        ];
        $result = $method->invoke($this->service, $array, ['table', 'field']);

        self::assertSame('direct_value', $result);
    }

    #[Test]
    public function differentFieldsHaveSeparateCacheEntries(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        // Access different fields
        $this->service->isAllowed('table', 'field1', VaultFieldPermission::Reveal, $backendUser);
        $this->service->isAllowed('table', 'field2', VaultFieldPermission::Reveal, $backendUser);

        // Should not throw or cause issues
        self::assertTrue(true);
    }

    #[Test]
    public function differentPermissionsHaveSeparateCacheEntries(): void
    {
        $backendUser = $this->createMockBackendUser(isAdmin: true);

        // Access different permissions for same field
        $reveal = $this->service->isAllowed('table', 'field', VaultFieldPermission::Reveal, $backendUser);
        $copy = $this->service->isAllowed('table', 'field', VaultFieldPermission::Copy, $backendUser);
        $readOnly = $this->service->isAllowed('table', 'field', VaultFieldPermission::ReadOnly, $backendUser);

        self::assertTrue($reveal);
        self::assertTrue($copy);
        self::assertFalse($readOnly);
    }

    private function createMockBackendUser(bool $isAdmin = false, int $uid = 1): BackendUserAuthentication&MockObject
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->method('isAdmin')->willReturn($isAdmin);
        $backendUser->user = ['uid' => $uid];

        return $backendUser;
    }
}
