<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Exception;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Audit\GenericContext;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Backend module controller for secrets management.
 */
#[AsController]
final readonly class SecretsController
{
    private const string MODULE_NAME = 'admin_vault_secrets';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
        private VaultServiceInterface $vaultService,
        private UriBuilder $uriBuilder,
        private FlashMessageService $flashMessageService,
        private ConnectionPool $connectionPool,
        private AuditLogServiceInterface $auditLogService,
        private ComponentFactory $componentFactory,
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

        // Get filter parameters from POST body (filter form uses POST to avoid iframe issues)
        $body = $request->getParsedBody() ?? [];
        $filters = [
            'identifier' => trim((string) ($body['identifier'] ?? '')),
            'status' => (string) ($body['status'] ?? ''),
            'owner' => (int) ($body['owner'] ?? 0),
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
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.title'),
        );

        return $moduleTemplate->renderResponse('Secrets/List');
    }

    /**
     * Redirect to FormEngine for creating a new secret.
     */
    public function createAction(): ResponseInterface
    {
        // Redirect to FormEngine for native TYPO3 editing experience
        $editUrl = $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                'tx_nrvault_secret' => [
                    0 => 'new',
                ],
            ],
            'returnUrl' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        return new RedirectResponse((string) $editUrl);
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

        $uid = $metadata['uid'] ?? 0;
        if ($uid === 0) {
            $this->addFlashMessage('Secret UID not found', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        // Redirect to FormEngine for native TYPO3 editing experience
        $editUrl = $this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                'tx_nrvault_secret' => [
                    $uid => 'edit',
                ],
            ],
            'returnUrl' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        return new RedirectResponse((string) $editUrl);
    }

    /**
     * Toggle secret enabled/disabled state.
     *
     * Supports both AJAX (returns JSON) and regular form submissions (redirects).
     */
    public function toggleAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $identifier = (string) ($body['identifier'] ?? '');
        $isAjax = $this->isAjaxRequest($request);

        if ($identifier === '') {
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'No secret identifier provided'], 400);
            }
            $this->addFlashMessage('No secret identifier provided', ContextualFeedbackSeverity::ERROR);

            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        try {
            // Get current state - remove restrictions to find hidden records
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $queryBuilder->getRestrictions()->removeAll();
            $current = $queryBuilder
                ->select('hidden')
                ->from('tx_nrvault_secret')
                ->where(
                    $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                    $queryBuilder->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchAssociative();

            if ($current === false) {
                throw new SecretNotFoundException('Secret not found: ' . $identifier, 7409034110);
            }

            // Toggle the hidden state
            $newState = $current['hidden'] ? 0 : 1;
            $action = $newState !== 0 ? 'disable' : 'enable';
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrvault_secret');
            $queryBuilder->getRestrictions()->removeAll();
            $queryBuilder
                ->update('tx_nrvault_secret')
                ->set('hidden', $newState)
                ->set('tstamp', time())
                ->where(
                    $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                    $queryBuilder->expr()->eq('deleted', 0),
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
                new GenericContext(['action' => $action, 'hidden' => $newState]),
            );

            $message = $newState !== 0
                ? $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.disabled.success')
                : $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.enabled.success');

            if ($isAjax) {
                return new JsonResponse([
                    'success' => true,
                    'hidden' => (bool) $newState,
                    'message' => $message,
                ]);
            }

            $this->addFlashMessage($message, ContextualFeedbackSeverity::OK);
        } catch (SecretNotFoundException) {
            $this->auditLogService->log($identifier, 'update', false, 'Secret not found');
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => 'Secret not found: ' . $identifier], 404);
            }
            $this->addFlashMessage('Secret not found: ' . $identifier, ContextualFeedbackSeverity::ERROR);
        } catch (Exception $e) {
            $this->auditLogService->log($identifier, 'update', false, $e->getMessage());
            if ($isAjax) {
                return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
            }
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

    /**
     * Check if the request is an AJAX request.
     */
    private function isAjaxRequest(ServerRequestInterface $request): bool
    {
        $acceptHeader = $request->getHeaderLine('Accept');

        return str_contains($acceptHeader, 'application/json')
            || $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }

    private function addDocHeaderButtons(ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();

        // Create Secret button
        $createButton = $this->componentFactory->createLinkButton()
            ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.create'))
            ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:secrets.create'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-add', IconSize::SMALL));
        $buttonBar->addButton($createButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        // Note: Reload button is automatically added by TYPO3's DocHeaderComponent
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
     *
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
     * Get unique owner options for the filter dropdown.
     *
     * @param array<array{owner_uid: int}> $secrets
     * @param array<int, string> $userCache
     *
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
        usort($options, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $options;
    }
}
