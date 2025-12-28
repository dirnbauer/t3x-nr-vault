<?php

declare(strict_types=1);

/*
 * This file is part of the "nr_vault" Extension for TYPO3 CMS.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace Netresearch\NrVault\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\PHPat;
use PHPat\Test\Rule;

/**
 * Architecture tests for nr-vault extension.
 *
 * Enforces clean architecture boundaries and security patterns.
 */
final class ArchitectureTest
{
    /**
     * Events must be readonly for immutability.
     */
    public function testEventsMustBeReadonly(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Event'))
            ->shouldBeReadonly()
            ->because('events must be immutable for security and predictability');
    }

    /**
     * Services must not depend on Controllers.
     */
    public function testServicesDoNotDependOnControllers(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Service'))
            ->shouldNotDependOn()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Controller'))
            ->because('services should be independent of the presentation layer');
    }

    /**
     * Domain layer must not depend on infrastructure.
     */
    public function testDomainDoesNotDependOnInfrastructure(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Domain'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrVault\Controller'),
                Selector::inNamespace('Netresearch\NrVault\Command'),
            )
            ->because('domain layer must be isolated from infrastructure concerns');
    }

    /**
     * Crypto layer must not depend on HTTP layer.
     */
    public function testCryptoDoesNotDependOnHttp(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Crypto'))
            ->shouldNotDependOn()
            ->classes(
                Selector::inNamespace('Netresearch\NrVault\Http'),
                Selector::inNamespace('Netresearch\NrVault\Controller'),
            )
            ->because('crypto operations must be independent of HTTP context');
    }

    /**
     * Exceptions must be final.
     */
    public function testExceptionsMustBeFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('Netresearch\NrVault\Exception'))
            ->shouldBeFinal()
            ->because('exceptions should not be extended for security');
    }

    /**
     * Services must implement an interface.
     */
    public function testServicesMustImplementInterface(): Rule
    {
        return PHPat::rule()
            ->classes(
                Selector::classname('/^Netresearch\\\\NrVault\\\\.*Service$/', true),
            )
            ->excluding(
                Selector::classname('/.*Interface$/', true),
                Selector::classname('/.*Factory$/', true),
            )
            ->shouldImplement()
            ->classes(Selector::classname('/.*Interface$/', true))
            ->because('services should be injected via interfaces for testability');
    }
}
