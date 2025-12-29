<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use DateTimeImmutable;
use Exception;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module controller for secrets management.
 */
#[AsController]
final class SecretsController
{
    private const MODULE_NAME = 'system_vault_secrets';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly VaultServiceInterface $vaultService,
        private readonly UriBuilder $uriBuilder,
        private readonly FlashMessageService $flashMessageService,
        private readonly ConnectionPool $connectionPool,
        private readonly AuditLogServiceInterface $auditLogService,
    ) {}

    /**
     * List all secrets (default action).
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: self::MODULE_NAME,
            displayName: $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
                . ' - '
                . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.title'),
        );

        $this->addDocHeaderButtons($moduleTemplate);

        // Get filter parameters
        $queryParams = $request->getQueryParams();
        $filters = [
            'identifier' => trim((string) ($queryParams['identifier'] ?? '')),
            'status' => (string) ($queryParams['status'] ?? ''),
            'owner' => (int) ($queryParams['owner'] ?? 0),
        ];

        $secrets = $this->vaultService->list();
        $userCache = $this->getUsernameCache($secrets);

        // Get unique owners for filter dropdown
        $ownerOptions = $this->getOwnerOptions($secrets, $userCache);

        $formattedSecrets = [];
        foreach ($secrets as $secret) {
            // Apply filters
            if ($filters['identifier'] !== '' && stripos($secret['identifier'], $filters['identifier']) === false) {
                continue;
            }
            if ($filters['status'] === 'active' && !empty($secret['hidden'])) {
                continue;
            }
            if ($filters['status'] === 'disabled' && empty($secret['hidden'])) {
                continue;
            }
            if ($filters['owner'] > 0 && $secret['owner_uid'] !== $filters['owner']) {
                continue;
            }

            $ownerUid = $secret['owner_uid'];
            $formattedSecrets[] = [
                'identifier' => $secret['identifier'],
                'owner_uid' => $ownerUid,
                'owner_name' => $userCache[$ownerUid] ?? 'User #' . $ownerUid,
                'created' => date('Y-m-d H:i:s', $secret['crdate']),
                'updated' => date('Y-m-d H:i:s', $secret['tstamp']),
                'read_count' => $secret['read_count'],
                'last_read' => $secret['last_read_at'] ? date('Y-m-d H:i:s', $secret['last_read_at']) : '-',
                'description' => $secret['description'] ?? '',
                'hidden' => (bool) ($secret['hidden'] ?? false),
            ];
        }

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $moduleTemplate->assignMultiple([
            'secrets' => $formattedSecrets,
            'totalCount' => \count($formattedSecrets),
            'isAdmin' => $this->isAdmin(),
            'filters' => $filters,
            'ownerOptions' => $ownerOptions,
            'listUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            'viewUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.view'),
            'editUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.edit'),
            'toggleUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.toggle'),
            'deleteUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.delete'),
            'rotateUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.rotate'),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.title')
        );

        return $moduleTemplate->renderResponse('Secrets/List');
    }

    /**
     * Show create secret form.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $this->addBackButton($moduleTemplate);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $moduleTemplate->assignMultiple([
            'storeUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.store'),
            'backUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            'currentUserId' => $GLOBALS['BE_USER']->user['uid'] ?? 0,
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.create.title')
        );

        return $moduleTemplate->renderResponse('Secrets/Create');
    }

    /**
     * View secret details (metadata only, not the secret value).
     */
    public function viewAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = (string) ($queryParams['identifier'] ?? '');

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            $metadata = $this->vaultService->getMetadata($identifier);
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $this->addBackButton($moduleTemplate);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        // Resolve owner name
        $ownerName = $this->getUsernameByUid($metadata['owner_uid'] ?? 0);

        // Resolve group names
        $groupNames = [];
        if (!empty($metadata['groups'])) {
            $groupNames = $this->getGroupNames($metadata['groups']);
        }

        $moduleTemplate->assignMultiple([
            'identifier' => $identifier,
            'metadata' => $metadata,
            'ownerName' => $ownerName,
            'groupNames' => $groupNames,
            'editUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.edit', ['identifier' => $identifier]),
            'rotateUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.rotate', ['identifier' => $identifier]),
            'revealUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.reveal'),
            'deleteUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.delete'),
            'backUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $identifier
        );

        return $moduleTemplate->renderResponse('Secrets/View');
    }

    /**
     * Reveal secret value (AJAX endpoint).
     */
    public function revealAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = (string) ($queryParams['identifier'] ?? '');

        if ($identifier === '') {
            return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => false, 'error' => 'No identifier'], 400);
        }

        try {
            $secret = $this->vaultService->retrieve($identifier);

            return new \TYPO3\CMS\Core\Http\JsonResponse([
                'success' => true,
                'secret' => $secret,
            ]);
        } catch (SecretNotFoundException) {
            return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => false, 'error' => 'Secret not found'], 404);
        } catch (AccessDeniedException) {
            return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        } catch (Exception $e) {
            return new \TYPO3\CMS\Core\Http\JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show edit secret form.
     */
    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = (string) ($queryParams['identifier'] ?? '');

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            $metadata = $this->vaultService->getMetadata($identifier);
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $this->addBackButton($moduleTemplate);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        // Resolve owner name for display
        $ownerName = $this->getUsernameByUid($metadata['owner_uid'] ?? 0);

        // Get all backend users for selector
        $backendUsers = $this->getBackendUsers();
        $backendGroups = $this->getBackendGroups();

        $moduleTemplate->assignMultiple([
            'identifier' => $identifier,
            'metadata' => $metadata,
            'ownerName' => $ownerName,
            'backendUsers' => $backendUsers,
            'backendGroups' => $backendGroups,
            'updateUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.update', ['identifier' => $identifier]),
            'backUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.edit.title')
        );

        return $moduleTemplate->renderResponse('Secrets/Edit');
    }

    /**
     * Update secret metadata.
     */
    public function updateAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = (string) ($queryParams['identifier'] ?? '');
        $body = $request->getParsedBody();

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            $metadata = $this->vaultService->getMetadata($identifier);

            // Build update data
            $updateData = [];

            // Description
            $description = trim((string) ($body['description'] ?? ''));
            $updateData['description'] = $description;

            // Owner
            if (isset($body['owner']) && $body['owner'] !== '') {
                $updateData['owner_uid'] = (int) $body['owner'];
            }

            // Groups
            if (isset($body['groups'])) {
                if (\is_array($body['groups'])) {
                    $updateData['groups'] = array_map('intval', $body['groups']);
                } elseif ($body['groups'] !== '') {
                    $updateData['groups'] = array_map('intval', explode(',', (string) $body['groups']));
                } else {
                    $updateData['groups'] = [];
                }
            }

            // Context
            $updateData['context'] = trim((string) ($body['context'] ?? ''));

            // Expires at
            if (!empty($body['expiresAt'])) {
                try {
                    $updateData['expires_at'] = (new DateTimeImmutable($body['expiresAt']))->getTimestamp();
                } catch (Exception) {
                    // Invalid date, ignore
                }
            } else {
                $updateData['expires_at'] = null;
            }

            // Page ID
            if (isset($body['pid']) && $body['pid'] !== '') {
                $updateData['pid'] = (int) $body['pid'];
            }

            // Update metadata in database
            $this->updateSecretMetadata($identifier, $updateData);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.edit.success'),
                ContextualFeedbackSeverity::OK,
            );
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);
        } catch (AccessDeniedException) {
            $this->addFlashMessage('Access denied', ContextualFeedbackSeverity::ERROR);
        } catch (Exception $e) {
            $this->addFlashMessage('Error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.edit', ['identifier' => $identifier]),
            );
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
    }

    /**
     * Store a new secret.
     */
    public function storeAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();

        $identifier = trim((string) ($body['identifier'] ?? ''));
        $secret = (string) ($body['secret'] ?? '');
        $description = trim((string) ($body['description'] ?? ''));

        $options = [];

        if (!empty($description)) {
            $options['description'] = $description;
        }

        if (!empty($body['owner'])) {
            $options['owner'] = (int) $body['owner'];
        }

        if (!empty($body['groups'])) {
            $options['groups'] = array_map('intval', explode(',', (string) $body['groups']));
        }

        if (!empty($body['context'])) {
            $options['context'] = trim((string) $body['context']);
        }

        if (!empty($body['expiresAt'])) {
            try {
                $options['expiresAt'] = new DateTimeImmutable($body['expiresAt']);
            } catch (Exception) {
            }
        }

        if (!empty($body['pid'])) {
            $options['pid'] = (int) $body['pid'];
        }

        if (!empty($body['metadata'])) {
            try {
                $metadata = json_decode($body['metadata'], true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($metadata)) {
                    $options['metadata'] = $metadata;
                }
            } catch (Exception) {
            }
        }

        try {
            $this->vaultService->store($identifier, $secret, $options);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.create.success'),
                ContextualFeedbackSeverity::OK,
            );
        } catch (ValidationException $e) {
            $this->addFlashMessage('Validation error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.create'),
            );
        } catch (Exception $e) {
            $this->addFlashMessage('Error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.create'),
            );
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
    }

    /**
     * Show rotate secret form.
     */
    public function rotateAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $identifier = (string) ($queryParams['identifier'] ?? '');

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        if ($request->getMethod() === 'POST') {
            return $this->doRotate($request, $identifier);
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $this->addBackButton($moduleTemplate);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        try {
            $metadata = $this->vaultService->getMetadata($identifier);
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        $moduleTemplate->assignMultiple([
            'identifier' => $identifier,
            'metadata' => $metadata,
            'rotateUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.rotate', ['identifier' => $identifier]),
            'backUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.rotate.title')
        );

        return $moduleTemplate->renderResponse('Secrets/Rotate');
    }

    /**
     * Execute secret rotation.
     */
    private function doRotate(ServerRequestInterface $request, string $identifier): ResponseInterface
    {
        $body = $request->getParsedBody();
        $newSecret = (string) ($body['secret'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($newSecret === '') {
            $this->addFlashMessage('New secret value is required', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.rotate', ['identifier' => $identifier]),
            );
        }

        try {
            $this->vaultService->rotate($identifier, $newSecret, $reason);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.rotate.success'),
                ContextualFeedbackSeverity::OK,
            );
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);
        } catch (AccessDeniedException) {
            $this->addFlashMessage('Access denied', ContextualFeedbackSeverity::ERROR);
        } catch (Exception $e) {
            $this->addFlashMessage('Error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
    }

    /**
     * Toggle secret enabled/disabled state.
     */
    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $identifier = (string) ($body['identifier'] ?? '');

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            // Get current state
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $current = $queryBuilder
                ->select('hidden')
                ->from('tx_nrvault_secret')
                ->where(
                    $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($current === false) {
                throw new SecretNotFoundException('Secret not found: ' . $identifier);
            }

            // Toggle the hidden state
            $newState = $current['hidden'] ? 0 : 1;
            $action = $newState ? 'disable' : 'enable';
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $queryBuilder
                ->update('tx_nrvault_secret')
                ->set('hidden', $newState)
                ->set('tstamp', time())
                ->where(
                    $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                )
                ->executeStatement();

            // Log the enable/disable action to audit log
            $this->auditLogService->log(
                $identifier,
                'update',
                true,
                null,
                $action === 'disable' ? 'Secret disabled' : 'Secret enabled',
                null,
                null,
                ['action' => $action, 'hidden' => $newState],
            );

            $message = $newState
                ? $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.disabled.success')
                : $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.enabled.success');

            $this->addFlashMessage($message, ContextualFeedbackSeverity::OK);
        } catch (SecretNotFoundException) {
            $this->auditLogService->log($identifier, 'update', false, 'Secret not found');
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);
        } catch (Exception $e) {
            $this->auditLogService->log($identifier, 'update', false, $e->getMessage());
            $this->addFlashMessage('Error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
    }

    /**
     * Delete a secret.
     */
    public function deleteAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $identifier = (string) ($body['identifier'] ?? '');
        $reason = trim((string) ($body['reason'] ?? ''));

        if ($identifier === '') {
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            $this->vaultService->delete($identifier, $reason);

            $this->addFlashMessage(
                $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.delete.success'),
                ContextualFeedbackSeverity::OK,
            );
        } catch (SecretNotFoundException) {
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);
        } catch (AccessDeniedException) {
            $this->addFlashMessage('Access denied', ContextualFeedbackSeverity::ERROR);
        } catch (Exception $e) {
            $this->addFlashMessage('Error: ' . $e->getMessage(), ContextualFeedbackSeverity::ERROR);
        }

        return new RedirectResponse(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
    }

    private function addDocHeaderButtons(\TYPO3\CMS\Backend\Template\ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();

        // Create Secret button
        $createButton = $buttonBar->makeLinkButton()
            ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.create'))
            ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.create'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-add', IconSize::SMALL));
        $buttonBar->addButton($createButton, \TYPO3\CMS\Backend\Template\Components\ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    private function addBackButton(\TYPO3\CMS\Backend\Template\ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();

        $backButton = $buttonBar->makeLinkButton()
            ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME))
            ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.goBack'))
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton, \TYPO3\CMS\Backend\Template\Components\ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    private function addFlashMessage(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = new FlashMessage($message, '', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }

    private function isAdmin(): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser !== null && $backendUser->isAdmin();
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }

    /**
     * Build a cache of user IDs to usernames.
     *
     * @param array<array{owner_uid: int}> $secrets
     * @return array<int, string>
     */
    private function getUsernameCache(array $secrets): array
    {
        $userIds = array_unique(array_filter(array_column($secrets, 'owner_uid')));

        if ($userIds === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $result = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->in('uid', $userIds),
            )
            ->executeQuery();

        $cache = [];
        while ($row = $result->fetchAssociative()) {
            $displayName = $row['realName'] !== '' ? $row['realName'] : $row['username'];
            $cache[(int) $row['uid']] = $displayName;
        }

        return $cache;
    }

    /**
     * Get username by user ID.
     */
    private function getUsernameByUid(int $uid): string
    {
        if ($uid === 0) {
            return '-';
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $result = $queryBuilder
            ->select('username', 'realName')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('uid', $uid),
            )
            ->executeQuery()
            ->fetchAssociative();

        if ($result === false) {
            return 'User #' . $uid;
        }

        return $result['realName'] !== '' ? $result['realName'] : $result['username'];
    }

    /**
     * Get group names by group IDs.
     *
     * @param array<int> $groupIds
     * @return array<string>
     */
    private function getGroupNames(array $groupIds): array
    {
        if ($groupIds === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $result = $queryBuilder
            ->select('uid', 'title')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->in('uid', $groupIds),
            )
            ->executeQuery();

        $names = [];
        while ($row = $result->fetchAssociative()) {
            $names[] = $row['title'];
        }

        return $names;
    }

    /**
     * Get all backend users for selector.
     *
     * @return array<array{uid: int, username: string, realName: string}>
     */
    private function getBackendUsers(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_users');
        $result = $queryBuilder
            ->select('uid', 'username', 'realName')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('disable', 0),
            )
            ->orderBy('username')
            ->executeQuery();

        $users = [];
        while ($row = $result->fetchAssociative()) {
            $users[] = [
                'uid' => (int) $row['uid'],
                'username' => $row['username'],
                'realName' => $row['realName'],
            ];
        }

        return $users;
    }

    /**
     * Get all backend groups for selector.
     *
     * @return array<array{uid: int, title: string}>
     */
    private function getBackendGroups(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('be_groups');
        $result = $queryBuilder
            ->select('uid', 'title')
            ->from('be_groups')
            ->where(
                $queryBuilder->expr()->eq('deleted', 0),
                $queryBuilder->expr()->eq('hidden', 0),
            )
            ->orderBy('title')
            ->executeQuery();

        $groups = [];
        while ($row = $result->fetchAssociative()) {
            $groups[] = [
                'uid' => (int) $row['uid'],
                'title' => $row['title'],
            ];
        }

        return $groups;
    }

    /**
     * Get unique owner options for the filter dropdown.
     *
     * @param array<array{owner_uid: int}> $secrets
     * @param array<int, string> $userCache
     * @return array<array{uid: int, name: string}>
     */
    private function getOwnerOptions(array $secrets, array $userCache): array
    {
        $ownerIds = array_unique(array_filter(array_column($secrets, 'owner_uid')));
        $options = [];
        foreach ($ownerIds as $uid) {
            $options[] = [
                'uid' => $uid,
                'name' => $userCache[$uid] ?? 'User #' . $uid,
            ];
        }
        usort($options, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $options;
    }

    /**
     * Update secret metadata in the database.
     *
     * @param array<string, mixed> $data
     */
    private function updateSecretMetadata(string $identifier, array $data): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');

        // Build update fields
        $updateFields = [
            'tstamp' => time(),
        ];

        if (\array_key_exists('description', $data)) {
            $updateFields['description'] = $data['description'];
        }

        if (\array_key_exists('owner_uid', $data)) {
            $updateFields['owner_uid'] = $data['owner_uid'];
        }

        if (\array_key_exists('groups', $data)) {
            $updateFields['allowed_groups'] = json_encode($data['groups'], JSON_THROW_ON_ERROR);
        }

        if (\array_key_exists('context', $data)) {
            $updateFields['context'] = $data['context'];
        }

        if (\array_key_exists('expires_at', $data)) {
            $updateFields['expires_at'] = $data['expires_at'];
        }

        if (\array_key_exists('pid', $data)) {
            $updateFields['pid'] = $data['pid'];
        }

        $queryBuilder
            ->update('tx_nrvault_secret')
            ->where(
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
            );

        foreach ($updateFields as $field => $value) {
            $queryBuilder->set($field, $value);
        }

        $queryBuilder->executeStatement();
    }
}
