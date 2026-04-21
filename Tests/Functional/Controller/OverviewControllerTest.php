<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\OverviewController;
use Netresearch\NrVault\Tests\Functional\AbstractVaultFunctionalTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional smoke tests for {@see OverviewController} wiring.
 *
 * The controller's `indexAction()` renders a full backend module
 * template through `ModuleTemplateFactory` → `BackendViewFactory`, which
 * pulls the current "module" request attribute to resolve view paths.
 * In a functional test the synthetic `ServerRequest` has no module
 * attribute attached — reproducing that routing context requires a
 * fake backend module registry that is deep enough to not belong here.
 * The full-rendering path is covered by the Playwright E2E suite
 * (`Tests/E2E/user-pathways/overview.spec.ts` — runs against a live
 * DDEV instance with the real module registered).
 *
 * This file only verifies the DI graph — that the controller can be
 * instantiated from the container with all its real collaborators,
 * which is still a regression guard against Services.yaml drift.
 *
 * `CoversNothing` because the class is excluded from unit coverage in
 * `Build/phpunit.xml` (its indexAction is covered functionally by E2E);
 * without it, PHPUnit 12 emits a "not a valid target for code
 * coverage" warning that `failOnWarning=true` promotes to an error.
 */
#[CoversNothing]
final class OverviewControllerTest extends AbstractVaultFunctionalTestCase
{
    protected ?string $backendUserFixture = __DIR__ . '/../Fixtures/Users/be_users.csv';

    #[Test]
    public function controllerCanBeResolvedFromContainer(): void
    {
        $controller = $this->get(OverviewController::class);

        self::assertInstanceOf(OverviewController::class, $controller);
    }
}
