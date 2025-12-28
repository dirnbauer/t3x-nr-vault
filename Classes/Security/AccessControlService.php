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
final class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(
        private readonly ExtensionConfigurationInterface $configuration,
    ) {}

    public function canRead(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'read');
    }

    public function canWrite(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'write');
    }

    public function canDelete(Secret $secret): bool
    {
        return $this->hasAccess($secret, 'delete');
    }

    public function canCreate(): bool
    {
        $backendUser = $this->getBackendUser();

        // Backend user takes precedence
        if ($backendUser !== null) {
            // Admin can always create
            if ($backendUser->isAdmin()) {
                return true;
            }

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
        if ($backendUser === null) {
            return 0;
        }

        return (int) ($backendUser->user['uid'] ?? 0);
    }

    public function getCurrentActorType(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser !== null) {
            return 'backend';
        }

        if ($this->isRealCliContext()) {
            return 'cli';
        }

        if (\defined('TYPO3_cliMode') && TYPO3_cliMode) {
            return 'cli';
        }

        return 'api';
    }

    public function getCurrentActorUsername(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser !== null) {
            return (string) ($backendUser->user['username'] ?? 'Unknown');
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
        if ($backendUser === null) {
            return [];
        }

        $groups = $backendUser->userGroupsUID ?? [];

        return array_map('intval', $groups);
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
    private function hasAccess(Secret $secret, string $operation): bool
    {
        $backendUser = $this->getBackendUser();

        // Backend user takes precedence
        if ($backendUser !== null) {
            return $this->hasBackendUserAccess($backendUser, $secret);
        }

        // CLI access control (only when no backend user)
        if ($this->isRealCliContext()) {
            if (!$this->configuration->isCliAccessAllowed()) {
                return false;
            }

            // Check CLI access groups if configured
            $cliAccessGroups = $this->configuration->getCliAccessGroups();
            if (!empty($cliAccessGroups)) {
                $secretGroups = $secret->getAllowedGroups();

                return !empty(array_intersect($secretGroups, $cliAccessGroups));
            }

            // CLI allowed and no group restrictions
            return true;
        }

        // No backend user and not CLI
        return false;
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
        $currentUserUid = (int) ($backendUser->user['uid'] ?? 0);
        if ($secret->getOwnerUid() === $currentUserUid) {
            return true;
        }

        // Group access
        $secretGroups = $secret->getAllowedGroups();
        if (!empty($secretGroups)) {
            $userGroups = $this->getCurrentUserGroups();
            if (!empty(array_intersect($secretGroups, $userGroups))) {
                return true;
            }
        }

        return false;
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
