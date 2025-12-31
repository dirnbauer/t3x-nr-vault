<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Controller;

use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Backend module controller for vault overview/dashboard.
 *
 * Shows summary statistics and quick links to submodules.
 */
#[AsController]
final class OverviewController
{
    private const MODULE_NAME = 'admin_vault';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly SecretRepository $secretRepository,
        private readonly AuditLogServiceInterface $auditLogService,
    ) {}

    /**
     * Display overview dashboard.
     */
    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->makeDocHeaderModuleMenu();

        // Gather statistics
        $totalSecrets = $this->secretRepository->countAll();
        $recentAuditCount = $this->auditLogService->count([]);

        $moduleTemplate->assignMultiple([
            'totalSecrets' => $totalSecrets,
            'recentAuditCount' => $recentAuditCount,
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
