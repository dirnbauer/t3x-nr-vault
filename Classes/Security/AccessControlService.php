<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Security;

use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Domain\Model\Secret;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Access control service implementation.
 */
final readonly class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(
        private ExtensionConfigurationInterface $configuration,
    ) {}

    public function canRead(Secret $secret): bool
    {
        return $this->hasAccess($secret);
    }

    public function canWrite(Secret $secret): bool
    {
        return $this->hasAccess($secret);
    }

    public function canDelete(Secret $secret): bool
    {
        return $this->hasAccess($secret);
    }

    public function canCreate(): bool
    {
        $backendUser = $this->getBackendUser();

        // Backend user takes precedence
        if ($backendUser instanceof BackendUserAuthentication) {
            // Any authenticated backend user can create
            return true;
        }

        // CLI check (only when no backend user)
        if ($this->isRealCliContext()) {
            return $this->configuration->isCliAccessAllowed();
        }

        // No backend user and not CLI
        return false;
    }

    public function getCurrentActorUid(): int
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 0;
        }

        /** @phpstan-ignore property.internal */
        $userRecord = $backendUser->user;
        /** @var array<string, mixed> $userRecordTyped */
        $userRecordTyped = \is_array($userRecord) ? $userRecord : [];

        return \is_int($userRecordTyped['uid'] ?? null) ? $userRecordTyped['uid'] : 0;
    }

    public function getCurrentActorType(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication) {
            return 'backend';
        }

        if ($this->isRealCliContext()) {
            return 'cli';
        }

        /** @phpstan-ignore constant.notFound */
        if (\defined('TYPO3_cliMode') && \TYPO3_CLIMODE) {
            return 'cli';
        }

        return 'api';
    }

    public function getCurrentActorUsername(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser instanceof BackendUserAuthentication) {
            /** @phpstan-ignore property.internal */
            $userRecord = $backendUser->user;
            /** @var array<string, mixed> $userRecordTyped */
            $userRecordTyped = \is_array($userRecord) ? $userRecord : [];

            return \is_string($userRecordTyped['username'] ?? null) ? $userRecordTyped['username'] : 'Unknown';
        }

        // No backend user - check context
        if ($this->isRealCliContext()) {
            return 'CLI';
        }

        return 'Anonymous';
    }

    public function getCurrentUserGroups(): array
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return [];
        }

        /** @phpstan-ignore nullCoalesce.property */
        $groups = $backendUser->userGroupsUID ?? [];

        /** @var list<int> $result */
        $result = [];
        foreach ($groups as $groupId) {
            $result[] = \is_int($groupId) ? $groupId : (is_numeric($groupId) ? (int) $groupId : 0);
        }

        return $result;
    }

    /**
     * Detect if we're in an actual CLI context (not PHPUnit tests).
     */
    private function isRealCliContext(): bool
    {
        // PHPUnit sets this constant
        if (\defined('PHPUNIT_COMPOSER_INSTALL') || \defined('__PHPUNIT_PHAR__')) {
            return false;
        }

        return PHP_SAPI === 'cli';
    }

    /**
     * Check access to a secret.
     */
    private function hasAccess(Secret $secret): bool
    {
        $backendUser = $this->getBackendUser();

        // Backend user takes precedence
        if ($backendUser instanceof BackendUserAuthentication) {
            return $this->hasBackendUserAccess($backendUser, $secret);
        }

        // CLI access control (only when no backend user)
        if ($this->isRealCliContext()) {
            if (!$this->configuration->isCliAccessAllowed()) {
                return false;
            }

            // Check CLI access groups if configured
            $cliAccessGroups = $this->configuration->getCliAccessGroups();
            if ($cliAccessGroups !== []) {
                $secretGroups = $secret->getAllowedGroups();

                return array_intersect($secretGroups, $cliAccessGroups) !== [];
            }

            // CLI allowed and no group restrictions
            return true;
        }

        // Frontend access for secrets explicitly marked as frontend_accessible
        // This allows TypoScript and other frontend contexts to resolve vault placeholders
        // No backend user and not CLI
        return $secret->isFrontendAccessible();
    }

    /**
     * Check if backend user has access to a secret.
     */
    private function hasBackendUserAccess(BackendUserAuthentication $backendUser, Secret $secret): bool
    {
        // Admin access
        if ($backendUser->isAdmin()) {
            return true;
        }

        // System maintainer access
        if ($backendUser->isSystemMaintainer()) {
            return true;
        }

        // Owner access
        /** @phpstan-ignore property.internal */
        $userRecord = $backendUser->user;
        /** @var array<string, mixed> $userRecordTyped */
        $userRecordTyped = \is_array($userRecord) ? $userRecord : [];
        $currentUserUid = \is_int($userRecordTyped['uid'] ?? null) ? $userRecordTyped['uid'] : 0;
        if ($secret->getOwnerUid() === $currentUserUid) {
            return true;
        }

        // Group access
        $secretGroups = $secret->getAllowedGroups();
        if ($secretGroups !== []) {
            $userGroups = $this->getCurrentUserGroups();
            if (array_intersect($secretGroups, $userGroups) !== []) {
                return true;
            }
        }

        return false;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $beUser = $GLOBALS['BE_USER'] ?? null;

        return $beUser instanceof BackendUserAuthentication ? $beUser : null;
    }
}
