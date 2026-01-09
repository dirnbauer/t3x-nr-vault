<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use DateTimeImmutable;
use Exception;
use Netresearch\NrVault\Audit\AuditLogEntry;
use Netresearch\NrVault\Audit\AuditLogFilter;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
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
final readonly class AuditController
{
    private const string MODULE_NAME = 'admin_vault_audit';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private IconFactory $iconFactory,
        private PageRenderer $pageRenderer,
        private AuditLogServiceInterface $auditLogService,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
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

        // Get filter parameters from POST body (filter form uses POST to avoid iframe issues)
        $body = $request->getParsedBody();
        $bodyArray = \is_array($body) ? $body : [];
        $queryParams = $request->getQueryParams();

        // Merge POST body with query params (POST takes precedence for filters)
        $filterParams = array_merge($queryParams, $bodyArray);
        $filterData = $this->buildAuditFilters($filterParams);
        $filter = $filterData['filter'];
        $formData = $filterData['form'];

        $page = max(1, (int) ($filterParams['page'] ?? 1));
        $limit = 50;
        $offset = ($page - 1) * $limit;

        $entries = $this->auditLogService->query($filter, $limit, $offset);
        $totalCount = $this->auditLogService->count($filter);
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

        // Build pagination URLs with filter parameters preserved
        $baseFilterParams = array_filter([
            'secretIdentifier' => $formData['secretIdentifier'],
            'filterAction' => $formData['action'],
            'success' => $formData['success'],
            'since' => $formData['since'],
            'until' => $formData['until'],
        ], static fn (string $v): bool => $v !== '');

        $pagination = [
            'first' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge($baseFilterParams, ['page' => 1])),
            'prev' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge($baseFilterParams, ['page' => max(1, $page - 1)])),
            'current' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge($baseFilterParams, ['page' => $page])),
            'next' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge($baseFilterParams, ['page' => min($totalPages, $page + 1)])),
            'last' => (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME, array_merge($baseFilterParams, ['page' => $totalPages])),
        ];

        $moduleTemplate->assignMultiple([
            'entries' => $formattedEntries,
            'groupedEntries' => $groupedEntries,
            'totalCount' => $totalCount,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'filters' => ['_form' => $formData],
            'pagination' => $pagination,
            'isAdmin' => $this->isAdmin(),
            'actions' => ['create', 'read', 'update', 'delete', 'rotate', 'access_denied', 'http_call'],
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.title'),
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
        $backButton = $this->componentFactory->createBackButton(
            (string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME),
        );
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $this->pageRenderer->addCssFile('EXT:nr_vault/Resources/Public/Css/backend.css');

        $result = $this->auditLogService->verifyHashChain();

        $moduleTemplate->assignMultiple([
            'valid' => $result->valid,
            'errors' => $result->errors,
            'message' => $result->isValid()
                ? $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.chain_valid')
                : $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.chain_invalid'),
        ]);

        $moduleTemplate->setTitle(
            $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:mlang_tabs_tab')
            . ' - '
            . $this->getLanguageService()->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.verify_chain'),
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

        $filterData = $this->buildAuditFilters($queryParams);

        $entries = $this->auditLogService->export($filterData['filter']);

        if ($format === 'csv') {
            return $this->exportAsCsv($entries);
        }

        // JSON: AuditLogEntry implements JsonSerializable, encode directly
        $response = new Response();
        $response->getBody()->write(json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'attachment; filename="vault-audit-' . date('Y-m-d') . '.json"');
    }

    /**
     * Build audit log filter and form data from request parameters.
     *
     * @param array<string, mixed> $queryParams
     *
     * @return array{filter: ?AuditLogFilter, form: array<string, string>}
     */
    private function buildAuditFilters(array $queryParams): array
    {
        // Form values for repopulation (always strings for form fields)
        $formData = [
            'secretIdentifier' => (string) ($queryParams['secretIdentifier'] ?? ''),
            'action' => (string) ($queryParams['filterAction'] ?? ''),
            'success' => (string) ($queryParams['success'] ?? ''),
            'since' => (string) ($queryParams['since'] ?? ''),
            'until' => (string) ($queryParams['until'] ?? ''),
        ];

        // Parse dates
        $since = null;
        if (!empty($queryParams['since'])) {
            try {
                $since = new DateTimeImmutable($queryParams['since']);
            } catch (Exception) {
            }
        }

        $until = null;
        if (!empty($queryParams['until'])) {
            try {
                $until = new DateTimeImmutable($queryParams['until']);
            } catch (Exception) {
            }
        }

        // Parse success filter
        $success = null;
        if (isset($queryParams['success']) && $queryParams['success'] !== '') {
            $success = (bool) (int) $queryParams['success'];
        }

        $filter = new AuditLogFilter(
            secretIdentifier: empty($queryParams['secretIdentifier']) ? null : (string) $queryParams['secretIdentifier'],
            action: empty($queryParams['filterAction']) ? null : (string) $queryParams['filterAction'],
            actorUid: empty($queryParams['actorUid']) ? null : (int) $queryParams['actorUid'],
            success: $success,
            since: $since,
            until: $until,
        );

        return [
            'filter' => $filter->isEmpty() ? null : $filter,
            'form' => $formData,
        ];
    }

    /**
     * @param list<AuditLogEntry> $entries
     */
    private function exportAsCsv(array $entries): ResponseInterface
    {
        $response = new Response();
        $output = fopen('php://temp', 'r+');

        if ($entries === []) {
            fwrite($output, "No data\n");
        } else {
            $first = $entries[0]->jsonSerialize();
            fputcsv($output, array_keys($first), escape: '\\');

            foreach ($entries as $entry) {
                $row = $entry->jsonSerialize();
                if (\is_array($row['context'])) {
                    $row['context'] = json_encode($row['context']);
                }
                /** @var array<int, bool|float|int|string|null> $values */
                $values = array_values($row);
                fputcsv($output, $values, escape: '\\');
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
            $verifyButton = $this->componentFactory->createLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.verifyChain'))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.verify_chain'))
                ->setIcon($this->iconFactory->getIcon('actions-check', IconSize::SMALL));
            $buttonBar->addButton($verifyButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

            // Export JSON button
            $exportJsonButton = $this->componentFactory->createLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'json']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.export') . ' JSON')
                ->setIcon($this->iconFactory->getIcon('actions-download', IconSize::SMALL));
            $buttonBar->addButton($exportJsonButton, ButtonBar::BUTTON_POSITION_LEFT, 2);

            // Export CSV button
            $exportCsvButton = $this->componentFactory->createLinkButton()
                ->setHref((string) $this->uriBuilder->buildUriFromRoute(self::MODULE_NAME . '.export', ['format' => 'csv']))
                ->setTitle($lang->sL('LLL:EXT:nr_vault/Resources/Private/Language/locallang_mod.xlf:audit.export') . ' CSV')
                ->setIcon($this->iconFactory->getIcon('actions-document-export-csv', IconSize::SMALL));
            $buttonBar->addButton($exportCsvButton, ButtonBar::BUTTON_POSITION_LEFT, 3);
        }

        // Note: Reload button is automatically added by TYPO3's DocHeaderComponent
    }

    private function getActionBadgeClass(string $action): string
    {
        return match ($action) {
            'create' => 'success',
            'read' => 'info',
            'update' => 'warning',
            'delete' => 'danger',
            'rotate' => 'info',
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
