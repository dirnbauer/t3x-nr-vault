<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use DateTimeImmutable;
use Exception;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Backend module controller for audit log management.
 */
#[AsController]
final class AuditController
{
    private const MODULE_NAME = 'admin_vault_audit';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly AuditLogServiceInterface $auditLogService,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /**
     * Display audit log.
     */
    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();
        $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            routeIdentifier: self::MODULE_NAME,
            displayName: $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
                . ' - '
                . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.title'),
        );

        $this->addDocHeaderButtons($moduleTemplate);

        $queryParams = $request->getQueryParams();

        $filters = $this->buildAuditFilters($queryParams);

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $entries = $this->auditLogService->query($filters, $limit, $offset);
        $totalCount = $this->auditLogService->count($filters);
        $totalPages = (int) ceil($totalCount / $limit);

        // Format entries and group by date
        $formattedEntries = [];
        $groupedEntries = [];
        foreach ($entries as $entry) {
            $date = date('Y-m-d', $entry->crdate);
            $formatted = [
                'uid' => $entry->uid,
                'timestamp' => date('Y-m-d H:i:s', $entry->crdate),
                'date' => $date,
                'time' => date('H:i:s', $entry->crdate),
                'secretIdentifier' => $entry->secretIdentifier,
                'action' => $entry->action,
                'actionBadgeClass' => $this->getActionBadgeClass($entry->action),
                'success' => $entry->success,
                'errorMessage' => $entry->errorMessage,
                'reason' => $entry->reason ?? '',
                'actorUsername' => $entry->actorUsername,
                'actorType' => $entry->actorType,
                'ipAddress' => $entry->ipAddress,
                'entryHash' => $entry->entryHash,
                'entryHashShort' => substr($entry->entryHash, 0, 8) . '...',
            ];
            $formattedEntries[] = $formatted;
            $groupedEntries[$date][] = $formatted;
        }

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $moduleTemplate->assignMultiple([
            'entries' => $formattedEntries,
            'groupedEntries' => $groupedEntries,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'filters' => $filters,
            'isAdmin' => $this->isAdmin(),
            'actions' => ['create', 'read', 'update', 'delete', 'rotate', 'access_denied', 'http_call'],
            'moduleUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            'exportJsonUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'json']),
            'exportCsvUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'csv']),
            'verifyChainUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.verifyChain'),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.title')
        );

        return $moduleTemplate->renderResponse('Audit/List');
    }

    /**
     * Verify hash chain integrity.
     */
    public function verifyChainAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return new RedirectResponse(
                (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
            );
        }

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $backButton = $buttonBar->makeLinkButton()
            ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME))
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.goBack'))
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton, \TYPO3\CMS\Backend\Template\Components\ButtonBar::BUTTON_POSITION_LEFT, 1);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $result = $this->auditLogService->verifyHashChain();

        $moduleTemplate->assignMultiple([
            'valid' => $result['valid'],
            'errors' => $result['errors'],
            'message' => $result['valid']
                ? $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.chain_valid')
                : $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.chain_invalid'),
            'backUri' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.verify_chain')
        );

        return $moduleTemplate->renderResponse('Audit/VerifyChain');
    }

    /**
     * Export audit logs.
     */
    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return new JsonResponse(['success' => false, 'error' => 'Access denied'], 403);
        }

        $queryParams = $request->getQueryParams();
        $format = $queryParams['format'] ?? 'json';

        $filters = $this->buildAuditFilters($queryParams);

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

    private function buildAuditFilters(array $queryParams): array
    {
        $filters = [];

        // Store raw values for form repopulation
        $filters['_form'] = [
            'secretIdentifier' => $queryParams['secretIdentifier'] ?? '',
            'action' => $queryParams['filterAction'] ?? '',
            'success' => $queryParams['success'] ?? '',
            'since' => $queryParams['since'] ?? '',
            'until' => $queryParams['until'] ?? '',
        ];

        // Query parameters for the service (only if set and not empty)
        if (!empty($queryParams['secretIdentifier'])) {
            $filters['secretIdentifier'] = $queryParams['secretIdentifier'];
        }

        if (!empty($queryParams['filterAction'])) {
            $filters['action'] = $queryParams['filterAction'];
        }

        if (!empty($queryParams['actorUid'])) {
            $filters['actorUid'] = (int) $queryParams['actorUid'];
        }

        if (isset($queryParams['success']) && $queryParams['success'] !== '') {
            $filters['success'] = (bool) (int) $queryParams['success'];
        }

        if (!empty($queryParams['since'])) {
            try {
                $filters['since'] = new DateTimeImmutable($queryParams['since']);
            } catch (Exception) {
            }
        }

        if (!empty($queryParams['until'])) {
            try {
                $filters['until'] = new DateTimeImmutable($queryParams['until']);
            } catch (Exception) {
            }
        }

        return $filters;
    }

    private function exportAsCsv(array $data): ResponseInterface
    {
        $response = new Response();
        $output = fopen('php://temp', 'r+');

        if (empty($data)) {
            fwrite($output, "No data\n");
        } else {
            fputcsv($output, array_keys($data[0]));

            foreach ($data as $row) {
                if (isset($row['context']) && \is_array($row['context'])) {
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

    private function addDocHeaderButtons(ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $lang = $this->getLanguageService();

        if ($this->isAdmin()) {
            // Verify Chain button
            $verifyButton = $buttonBar->makeLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.verifyChain'))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.verify_chain'))
                ->setIcon($this->iconFactory->getIcon('actions-check', IconSize::SMALL));
            $buttonBar->addButton($verifyButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

            // Export JSON button
            $exportJsonButton = $buttonBar->makeLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'json']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.export') . ' JSON')
                ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
            $buttonBar->addButton($exportJsonButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

            // Export CSV button
            $exportCsvButton = $buttonBar->makeLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'csv']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.export') . ' CSV')
                ->setIcon($this->iconFactory->getIcon('actions-document-export-csv', IconSize::SMALL));
            $buttonBar->addButton($exportCsvButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
        }
    }

    private function getActionBadgeClass(string $action): string
    {
        return match ($action) {
            'create' => 'success',
            'read' => 'info',
            'update' => 'warning',
            'delete' => 'danger',
            'rotate' => 'primary',
            'access_denied' => 'danger',
            default => 'secondary',
        };
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
