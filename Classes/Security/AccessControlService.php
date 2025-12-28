<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Security;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use Netresearch\NrVault\Domain\Model\Secret;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Access control service implementation.
 */
final class AccessControlService implements AccessControlServiceInterface
{
    public function __construct(
        private readonly ExtensionConfiguration $configuration,
    ) {
    }

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

        // CLI check
        if (PHP_SAPI === 'cli') {
            return $this->configuration->isCliAccessAllowed();
        }

        // Backend user required
        if ($backendUser === null) {
            return false;
        }

        // Admin can always create
        if ($backendUser->isAdmin()) {
            return true;
        }

        // Any authenticated backend user can create
        return true;
    }

    public function getCurrentActorUid(): int
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return 0;
        }

        return (int)($backendUser->user['uid'] ?? 0);
    }

    public function getCurrentActorType(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }

        if (defined('TYPO3_cliMode') && TYPO3_cliMode) {
            return 'cli';
        }

        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return 'api';
        }

        return 'backend';
    }

    public function getCurrentActorUsername(): string
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return PHP_SAPI === 'cli' ? 'CLI' : 'Anonymous';
        }

        return (string)($backendUser->user['username'] ?? 'Unknown');
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
     * Check access to a secret.
     */
    private function hasAccess(Secret $secret, string $operation): bool
    {
        $currentUserUid = $this->getCurrentActorUid();

        // CLI access control
        if (PHP_SAPI === 'cli' && $currentUserUid === 0) {
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

        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }

        // Admin access
        if ($backendUser->isAdmin()) {
            return true;
        }

        // System maintainer access
        if ($backendUser->isSystemMaintainer()) {
            return true;
        }

        // Owner access
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
