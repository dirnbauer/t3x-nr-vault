<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Security;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Security\AccessControlService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

#[CoversClass(AccessControlService::class)]
#[AllowMockObjectsWithoutExpectations]
final class AccessControlServiceTest extends TestCase
{
    private AccessControlService $subject;

    private ExtensionConfigurationInterface&MockObject $configuration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configuration = $this->createMock(ExtensionConfigurationInterface::class);
        $this->subject = new AccessControlService($this->configuration);

        // Reset GLOBALS for clean state
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
        parent::tearDown();
    }

    #[Test]
    public function canReadReturnsFalseWithoutBackendUser(): void
    {
        $secret = $this->createSecret(ownerUid: 1);

        self::assertFalse($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsTrueForAdmin(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: true);
        $secret = $this->createSecret(ownerUid: 999); // Different owner

        self::assertTrue($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsTrueForOwner(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false);
        $secret = $this->createSecret(ownerUid: 5);

        self::assertTrue($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsFalseForNonOwner(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false);
        $secret = $this->createSecret(ownerUid: 10);

        self::assertFalse($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsTrueForGroupMember(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false, groups: [1, 2, 3]);
        $secret = $this->createSecret(ownerUid: 999, allowedGroups: [2]);

        self::assertTrue($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsFalseForNonGroupMember(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false, groups: [1, 2, 3]);
        $secret = $this->createSecret(ownerUid: 999, allowedGroups: [10, 20]);

        self::assertFalse($this->subject->canRead($secret));
    }

    #[Test]
    public function canWriteReturnsTrueForAdmin(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: true);
        $secret = $this->createSecret(ownerUid: 999);

        self::assertTrue($this->subject->canWrite($secret));
    }

    #[Test]
    public function canWriteReturnsTrueForOwner(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false);
        $secret = $this->createSecret(ownerUid: 5);

        self::assertTrue($this->subject->canWrite($secret));
    }

    #[Test]
    public function canDeleteReturnsTrueForAdmin(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: true);
        $secret = $this->createSecret(ownerUid: 999);

        self::assertTrue($this->subject->canDelete($secret));
    }

    #[Test]
    public function canDeleteReturnsTrueForOwner(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false);
        $secret = $this->createSecret(ownerUid: 5);

        self::assertTrue($this->subject->canDelete($secret));
    }

    #[Test]
    public function canCreateReturnsTrueForAuthenticatedUser(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false);

        self::assertTrue($this->subject->canCreate());
    }

    #[Test]
    public function canCreateReturnsFalseWithoutBackendUser(): void
    {
        // No CLI mode, no backend user
        self::assertFalse($this->subject->canCreate());
    }

    #[Test]
    public function getCurrentActorUidReturnsZeroWithoutUser(): void
    {
        self::assertEquals(0, $this->subject->getCurrentActorUid());
    }

    #[Test]
    public function getCurrentActorUidReturnsUserUid(): void
    {
        $this->setBackendUser(uid: 42, isAdmin: false);

        self::assertEquals(42, $this->subject->getCurrentActorUid());
    }

    #[Test]
    public function getCurrentActorUsernameReturnsAnonymousWithoutUser(): void
    {
        self::assertEquals('Anonymous', $this->subject->getCurrentActorUsername());
    }

    #[Test]
    public function getCurrentActorUsernameReturnsUsername(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: false, username: 'testuser');

        self::assertEquals('testuser', $this->subject->getCurrentActorUsername());
    }

    #[Test]
    public function getCurrentActorTypeReturnsBackendForBackendUser(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: false);

        self::assertEquals('backend', $this->subject->getCurrentActorType());
    }

    #[Test]
    public function getCurrentActorTypeReturnsApiWithoutBackendUser(): void
    {
        // When not in CLI and no backend user
        self::assertEquals('api', $this->subject->getCurrentActorType());
    }

    #[Test]
    public function getCurrentUserGroupsReturnsEmptyWithoutUser(): void
    {
        self::assertEquals([], $this->subject->getCurrentUserGroups());
    }

    #[Test]
    public function getCurrentUserGroupsReturnsUserGroups(): void
    {
        $this->setBackendUser(uid: 1, isAdmin: false, groups: [1, 2, 3]);

        self::assertEquals([1, 2, 3], $this->subject->getCurrentUserGroups());
    }

    #[Test]
    public function systemMaintainerHasFullAccess(): void
    {
        $this->setBackendUser(uid: 5, isAdmin: false, isSystemMaintainer: true);
        $secret = $this->createSecret(ownerUid: 999);

        self::assertTrue($this->subject->canRead($secret));
        self::assertTrue($this->subject->canWrite($secret));
        self::assertTrue($this->subject->canDelete($secret));
    }

    #[Test]
    public function canReadReturnsTrueForFrontendAccessibleSecretWithoutBackendUser(): void
    {
        // No backend user, not CLI context – falls back to isFrontendAccessible()
        $secret = $this->createSecret(ownerUid: 0, frontendAccessible: true);

        self::assertTrue($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsFalseForNonFrontendAccessibleSecretWithoutBackendUser(): void
    {
        $secret = $this->createSecret(ownerUid: 0, frontendAccessible: false);

        self::assertFalse($this->subject->canRead($secret));
    }

    #[Test]
    public function canReadReturnsFalseForNonOwnerWithNoGroupsOnSecret(): void
    {
        // Non-owner; secret has no allowed groups -> access denied
        $this->setBackendUser(uid: 5, isAdmin: false, groups: [1, 2, 3]);
        $secret = $this->createSecret(ownerUid: 10, allowedGroups: []);

        self::assertFalse($this->subject->canRead($secret));
    }

    #[Test]
    public function getCurrentActorUsernameReturnsUnknownWhenUsernameIsNotString(): void
    {
        // User record exists but username key is missing / non-string
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1]; // no 'username' key
        $backendUser->userGroupsUID = [];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('isSystemMaintainer')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertEquals('Unknown', $this->subject->getCurrentActorUsername());
    }

    #[Test]
    public function getCurrentActorUidReturnsZeroWhenUserUidIsNotInt(): void
    {
        // User record exists but uid is a string
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 'not-an-int'];
        $backendUser->userGroupsUID = [];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('isSystemMaintainer')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        self::assertSame(0, $this->subject->getCurrentActorUid());
    }

    #[Test]
    public function getCurrentUserGroupsConvertsStringNumericGroupIds(): void
    {
        // userGroupsUID may contain string representations of integers
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'username' => 'tester'];
        $backendUser->userGroupsUID = ['5', '10', '15'];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('isSystemMaintainer')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $groups = $this->subject->getCurrentUserGroups();

        self::assertSame([5, 10, 15], $groups);
    }

    #[Test]
    public function getCurrentUserGroupsConvertsNonNumericStringGroupIdToZero(): void
    {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'username' => 'tester'];
        $backendUser->userGroupsUID = ['not-a-number'];
        $backendUser->method('isAdmin')->willReturn(false);
        $backendUser->method('isSystemMaintainer')->willReturn(false);
        $GLOBALS['BE_USER'] = $backendUser;

        $groups = $this->subject->getCurrentUserGroups();

        self::assertSame([0], $groups);
    }

    #[Test]
    public function hasAccessDelegatesToFrontendAccessibleWhenNoBackendUserAndNotCli(): void
    {
        // canWrite and canDelete also delegate to hasAccess, which ends at isFrontendAccessible()
        $secret = $this->createSecret(ownerUid: 0, frontendAccessible: true);

        self::assertTrue($this->subject->canWrite($secret));
        self::assertTrue($this->subject->canDelete($secret));
    }

    /**
     * Create a test Secret with specified properties.
     */
    private function createSecret(
        int $ownerUid = 0,
        array $allowedGroups = [],
        bool $frontendAccessible = false,
    ): Secret {
        $secret = new Secret();
        $secret->setOwnerUid($ownerUid);
        $secret->setAllowedGroups($allowedGroups);
        $secret->setFrontendAccessible($frontendAccessible);
        $secret->setIdentifier('test-secret');

        return $secret;
    }

    /**
     * Set up a mock backend user in GLOBALS.
     */
    private function setBackendUser(
        int $uid,
        bool $isAdmin,
        array $groups = [],
        string $username = 'testuser',
        bool $isSystemMaintainer = false,
    ): void {
        $backendUser = $this->createMock(BackendUserAuthentication::class);
        $backendUser->user = [
            'uid' => $uid,
            'username' => $username,
        ];
        $backendUser->userGroupsUID = $groups;

        $backendUser
            ->method('isAdmin')
            ->willReturn($isAdmin);

        $backendUser
            ->method('isSystemMaintainer')
            ->willReturn($isSystemMaintainer);

        $GLOBALS['BE_USER'] = $backendUser;
    }
}
