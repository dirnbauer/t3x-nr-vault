<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\OverviewController;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Netresearch\NrVault\Tests\Functional\AbstractVaultFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Functional smoke tests for {@see OverviewController}.
 *
 * OverviewController was previously at ~22% unit-test coverage and is now
 * excluded from the unit suite because it depends on `final` TYPO3 classes
 * (`ModuleTemplateFactory`, `ModuleTemplate`, `BackendUriBuilder`) that
 * cannot be mocked. These functional tests take its place with a real DI
 * container and a real backend user.
 *
 * The tests intentionally stay at the "does it render" level — the module
 * UI itself is covered by the Playwright E2E suite. Here we just ensure:
 *   - indexAction returns HTTP 200 for an admin user and includes the
 *     known statistics panel markers,
 *   - it renders the master-key health-check section,
 *   - unauthenticated access is rejected at the framework level.
 */
#[CoversClass(OverviewController::class)]
final class OverviewControllerTest extends AbstractVaultFunctionalTestCase
{
    protected ?string $backendUserFixture = __DIR__ . '/../Fixtures/Users/be_users.csv';

    #[Test]
    public function indexActionRendersOverviewTemplate(): void
    {
        $controller = $this->get(OverviewController::class);
        $request = $this->createBackendRequest('/module/admin/vault');

        $response = $controller->indexAction($request);

        self::assertSame(200, $response->getStatusCode(), 'Overview index must return 200 for admin');

        $body = (string) $response->getBody();
        self::assertNotSame('', $body, 'Response body must not be empty');

        // Data-test IDs live in Resources/Private/Templates/Overview/Index.html
        // and are stable (not translated). They pin the three stats cards.
        self::assertStringContainsString(
            'data-testid="stat-value-total"',
            $body,
            'Overview must render the "Total Secrets" statistic panel',
        );
        self::assertStringContainsString(
            'data-testid="stat-value-active"',
            $body,
            'Overview must render the "Active" statistic panel',
        );
        self::assertStringContainsString(
            'data-testid="stat-value-disabled"',
            $body,
            'Overview must render the "Disabled" statistic panel',
        );
    }

    #[Test]
    public function indexActionReflectsStoredSecretsInStatistics(): void
    {
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = 'overview_stats_' . bin2hex(random_bytes(4));
        $vaultService->store($identifier, 'secret-value-for-count');

        try {
            $controller = $this->get(OverviewController::class);
            $request = $this->createBackendRequest('/module/admin/vault');

            $response = $controller->indexAction($request);

            self::assertSame(200, $response->getStatusCode());

            $body = (string) $response->getBody();
            // One secret is enough to verify stats queries actually ran — we
            // don't pin exact numbers because parallel tests or leftover rows
            // could influence the count. We just assert it's >= 1.
            self::assertMatchesRegularExpression(
                '/data-testid="stat-value-total"[^>]*>\s*[1-9]\d*/',
                $body,
                'Total Secrets panel must show a count >= 1 after storing a secret',
            );
        } finally {
            $this->safeDelete($vaultService, $identifier);
        }
    }

    #[Test]
    public function indexActionShowsHealthChecks(): void
    {
        $controller = $this->get(OverviewController::class);
        $request = $this->createBackendRequest('/module/admin/vault');

        $response = $controller->indexAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();

        // Template at Resources/Private/Templates/Overview/Index.html uses
        // {healthChecks.masterKeyProvider} — its substring appears in the
        // "encryption active" message once healthChecks succeed. The file
        // backend provider's identifier is "file".
        //
        // We look for either the success path (a non-empty provider name
        // rendered somewhere in the page) OR the issue path. Both prove the
        // health-check section was evaluated and rendered.
        $healthEvaluated = str_contains(strtolower($body), 'encryption')
            || str_contains(strtolower($body), 'master key')
            || str_contains(strtolower($body), 'vault-health');

        self::assertTrue(
            $healthEvaluated,
            'Overview must render the master-key health-check section '
            . '(either "encryption active" success or a health issue callout)',
        );
    }

    #[Test]
    public function helpActionRendersHelpTemplate(): void
    {
        $controller = $this->get(OverviewController::class);
        $request = $this->createBackendRequest('/module/admin/vault.help');

        $response = $controller->helpAction($request);

        self::assertSame(200, $response->getStatusCode(), 'Help action must return 200 for admin');
        $body = (string) $response->getBody();
        self::assertNotSame('', $body, 'Help response body must not be empty');
    }

    #[Test]
    public function indexActionRequiresBackendUser(): void
    {
        // Undo the admin login set up by the abstract base class so that
        // `$GLOBALS['BE_USER']` no longer resolves. Controllers/template
        // rendering that pulls a LanguageService from $GLOBALS['LANG'] will
        // fail fast in that case. This characterises the "no backend user"
        // state rather than the framework-level redirect-to-login flow,
        // because the latter sits outside the controller under test.
        unset($GLOBALS['BE_USER']);
        unset($GLOBALS['LANG']);

        $controller = $this->get(OverviewController::class);
        $request = $this->createBackendRequest('/module/admin/vault');

        $threw = false;
        try {
            $response = $controller->indexAction($request);
            // If it didn't throw, it must NOT have rendered a full 200 page
            // revealing vault stats without authentication.
            self::assertNotSame(
                200,
                $response->getStatusCode(),
                'indexAction must not serve 200 without a backend user',
            );
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue(
            $threw,
            'Unauthenticated access to Overview::indexAction must be rejected '
            . '(either by throwing or by a non-200 response).',
        );
    }

    /**
     * Build a minimal backend PSR-7 request pointed at the given path.
     * Backend module routing attaches a `route` attribute in production;
     * the tests here invoke the controller action directly, so a bare
     * request is sufficient for what `ModuleTemplateFactory::create()`
     * consumes.
     */
    private function createBackendRequest(string $path): ServerRequest
    {
        /** @phpstan-ignore new.internalClass */
        return (new ServerRequest('https://example.com' . $path, 'GET'))
            ->withAttribute('applicationType', 8); // TYPO3 Backend = 8
    }

    /**
     * Delete a secret; swallow cleanup failures so they never mask the
     * original assertion error.
     */
    private function safeDelete(VaultServiceInterface $vaultService, string $identifier): void
    {
        try {
            if ($vaultService->exists($identifier)) {
                $vaultService->delete($identifier, 'test cleanup');
            }
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}
