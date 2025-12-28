<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Security\AccessControlServiceInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module controller for vault management.
 *
 * Provides UI for:
 * - Viewing secrets list
 * - Viewing audit logs
 * - Verifying hash chain integrity
 */
#[AsController]
final class VaultController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly VaultServiceInterface $vaultService,
        private readonly AuditLogServiceInterface $auditLogService,
        private readonly AccessControlServiceInterface $accessControlService,
    ) {
    }

    /**
     * Main entry point - secrets list.
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $action = $request->getQueryParams()['action'] ?? 'secrets';

        return match ($action) {
            'audit' => $this->auditAction($request),
            'verifyChain' => $this->verifyChainAction($request),
            'export' => $this->exportAction($request),
            default => $this->secretsAction($request),
        };
    }

    /**
     * Display secrets list.
     */
    private function secretsAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        // Add DocHeader buttons
        $this->addDocHeaderButtons($moduleTemplate, $request, 'secrets');

        // Get secrets list
        $secrets = $this->vaultService->list();

        // Filter based on access control
        $accessibleSecrets = [];
        foreach ($secrets as $secret) {
            // For list view, show all secrets the user can at least see metadata for
            $accessibleSecrets[] = [
                'identifier' => $secret['identifier'],
                'owner_uid' => $secret['owner_uid'],
                'created' => date('Y-m-d H:i:s', $secret['crdate']),
                'updated' => date('Y-m-d H:i:s', $secret['tstamp']),
                'read_count' => $secret['read_count'],
                'last_read' => $secret['last_read_at'] ? date('Y-m-d H:i:s', $secret['last_read_at']) : '-',
            ];
        }

        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-vault/vault-backend.js');
        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $moduleTemplate->assignMultiple([
            'secrets' => $accessibleSecrets,
            'totalCount' => count($accessibleSecrets),
            'currentAction' => 'secrets',
            'isAdmin' => $this->isAdmin(),
        ]);

        $moduleTemplate->setTitle($this->getLanguageService()->sL(
            'LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        ));

        return $moduleTemplate->renderResponse('Vault/Secrets');
    }

    /**
     * Display audit log.
     */
    private function auditAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);

        // Add DocHeader buttons
        $this->addDocHeaderButtons($moduleTemplate, $request, 'audit');

        $queryParams = $request->getQueryParams();

        // Build filters from query params
        $filters = [];
        if (!empty($queryParams['secretIdentifier'])) {
            $filters['secretIdentifier'] = $queryParams['secretIdentifier'];
        }
        if (!empty($queryParams['action'])) {
            $filters['action'] = $queryParams['action'];
        }
        if (!empty($queryParams['actorUid'])) {
            $filters['actorUid'] = (int)$queryParams['actorUid'];
        }
        if (isset($queryParams['success']) && $queryParams['success'] !== '') {
            $filters['success'] = (bool)$queryParams['success'];
        }
        if (!empty($queryParams['since'])) {
            try {
                $filters['since'] = new \DateTimeImmutable($queryParams['since']);
            } catch (\Exception) {
                // Invalid date, ignore
            }
        }
        if (!empty($queryParams['until'])) {
            try {
                $filters['until'] = new \DateTimeImmutable($queryParams['until']);
            } catch (\Exception) {
                // Invalid date, ignore
            }
        }

        // Pagination
        $page = max(1, (int)($queryParams['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        // Get audit entries
        $entries = $this->auditLogService->query($filters, $limit, $offset);
        $totalCount = $this->auditLogService->count($filters);
        $totalPages = (int)ceil($totalCount / $limit);

        // Format entries for display
        $formattedEntries = [];
        foreach ($entries as $entry) {
            $formattedEntries[] = [
                'uid' => $entry->uid,
                'timestamp' => date('Y-m-d H:i:s', $entry->crdate),
                'secretIdentifier' => $entry->secretIdentifier,
                'action' => $entry->action,
                'success' => $entry->success,
                'errorMessage' => $entry->errorMessage,
                'actorUsername' => $entry->actorUsername,
                'actorType' => $entry->actorType,
                'ipAddress' => $entry->ipAddress,
                'entryHash' => substr($entry->entryHash, 0, 8) . '...',
            ];
        }

        $this->pageRenderer->loadJavaScriptModule('@netresearch/nr-vault/vault-backend.js');
        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $moduleTemplate->assignMultiple([
            'entries' => $formattedEntries,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'filters' => $filters,
            'currentAction' => 'audit',
            'isAdmin' => $this->isAdmin(),
            'actions' => ['create', 'read', 'update', 'delete', 'rotate', 'access_denied', 'http_call'],
        ]);

        $moduleTemplate->setTitle($this->getLanguageService()->sL(
            'LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab'
        ) . ' - Audit Log');

        return $moduleTemplate->renderResponse('Vault/Audit');
    }

    /**
     * Verify hash chain integrity.
     */
    private function verifyChainAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only admins can verify the chain
        if (!$this->isAdmin()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        $result = $this->auditLogService->verifyHashChain();

        return new JsonResponse([
            'success' => true,
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'message' => $result['valid']
                ? 'Hash chain is valid - no tampering detected'
                : 'Hash chain verification failed - ' . count($result['errors']) . ' error(s) found',
        ]);
    }

    /**
     * Export audit logs.
     */
    private function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        // Only admins can export
        if (!$this->isAdmin()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'Access denied',
            ], 403);
        }

        $queryParams = $request->getQueryParams();
        $format = $queryParams['format'] ?? 'json';

        // Build filters
        $filters = [];
        if (!empty($queryParams['since'])) {
            try {
                $filters['since'] = new \DateTimeImmutable($queryParams['since']);
            } catch (\Exception) {
            }
        }
        if (!empty($queryParams['until'])) {
            try {
                $filters['until'] = new \DateTimeImmutable($queryParams['until']);
            } catch (\Exception) {
            }
        }

        $data = $this->auditLogService->export($filters);

        if ($format === 'csv') {
            return $this->exportAsCsv($data);
        }

        $response = new Response();
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="vault-audit-' . date('Y-m-d') . '.json"');
    }

    /**
     * Export data as CSV.
     */
    private function exportAsCsv(array $data): ResponseInterface
    {
        $response = new Response();
        $output = fopen('php://temp', 'r+');

        if (empty($data)) {
            fwrite($output, "No data\n");
        } else {
            // Headers
            fputcsv($output, array_keys($data[0]));

            // Rows
            foreach ($data as $row) {
                // Flatten context array
                if (isset($row['context']) && is_array($row['context'])) {
                    $row['context'] = json_encode($row['context']);
                }
                fputcsv($output, $row);
            }
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="vault-audit-' . date('Y-m-d') . '.csv"');
    }

    /**
     * Add DocHeader buttons to the module template.
     */
    private function addDocHeaderButtons(
        ModuleTemplate $moduleTemplate,
        ServerRequestInterface $request,
        string $currentAction
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();

        // Refresh button
        $refreshButton = $buttonBar->makeLinkButton()
            ->setHref((string)$request->getUri())
            ->setTitle($lang->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($this->iconFactory->getIcon('actions-refresh', Icon::SIZE_SMALL));
        $buttonBar->addButton($refreshButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        // Secrets button (if not current)
        if ($currentAction !== 'secrets') {
            $secretsButton = $buttonBar->makeLinkButton()
                ->setHref($this->buildModuleUri($request, ['action' => 'secrets']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:action.secrets'))
                ->setIcon($this->iconFactory->getIcon('actions-lock', Icon::SIZE_SMALL));
            $buttonBar->addButton($secretsButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }

        // Audit button (if not current)
        if ($currentAction !== 'audit') {
            $auditButton = $buttonBar->makeLinkButton()
                ->setHref($this->buildModuleUri($request, ['action' => 'audit']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:action.audit'))
                ->setIcon($this->iconFactory->getIcon('actions-document-history-open', Icon::SIZE_SMALL));
            $buttonBar->addButton($auditButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }

        // Admin-only buttons
        if ($this->isAdmin()) {
            // Verify chain button
            $verifyButton = $buttonBar->makeLinkButton()
                ->setHref($this->buildModuleUri($request, ['action' => 'verifyChain']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:action.verifyChain'))
                ->setIcon($this->iconFactory->getIcon('actions-check', Icon::SIZE_SMALL))
                ->setClasses('t3js-vault-verify-chain');
            $buttonBar->addButton($verifyButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

            // Export button (on audit page)
            if ($currentAction === 'audit') {
                $exportButton = $buttonBar->makeLinkButton()
                    ->setHref($this->buildModuleUri($request, ['action' => 'export', 'format' => 'json']))
                    ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:action.export'))
                    ->setIcon($this->iconFactory->getIcon('actions-download', Icon::SIZE_SMALL));
                $buttonBar->addButton($exportButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
            }
        }
    }

    /**
     * Build a URI for the current module with additional parameters.
     */
    private function buildModuleUri(ServerRequestInterface $request, array $additionalParams = []): string
    {
        $uri = $request->getUri();
        parse_str($uri->getQuery(), $params);
        $params = array_merge($params, $additionalParams);

        return (string)$uri->withQuery(http_build_query($params));
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
}
