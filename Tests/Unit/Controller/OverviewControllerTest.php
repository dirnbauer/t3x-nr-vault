<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Controller;

use Exception;
use Netresearch\NrVault\Controller\OverviewController;
use Netresearch\NrVault\Crypto\MasterKeyProviderFactoryInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * Unit tests for OverviewController health checks.
 *
 * Tests the getHealthChecks() logic via reflection since the controller
 * depends on final TYPO3 classes that cannot be fully mocked for
 * indexAction(). Because the SUT is excluded from unit coverage in
 * Build/phpunit.xml (its indexAction is covered functionally), we mark
 * this test as `CoversNothing` so PHPUnit 12 does not raise
 * "Class X is not a valid target for code coverage" warnings that
 * `failOnWarning=true` would promote to errors.
 */
#[CoversNothing]
final class OverviewControllerTest extends TestCase
{
    /**
     * PHPUnit 12 `createStub()` returns an opaque TestStub class that does not
     * implement the `Stub` interface nor inherit from `MockObject`; declaring
     * the intersection type `Interface&Stub` fails at runtime. Use the bare
     * interface type — `createStub()` returns an instance of it.
     */
    private MasterKeyProviderFactoryInterface $masterKeyProviderFactory;

    private OverviewController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masterKeyProviderFactory = $this->createStub(MasterKeyProviderFactoryInterface::class);

        // Build controller using newInstanceWithoutConstructor to bypass
        // final readonly ModuleTemplateFactory, then initialize the property
        // needed for getHealthChecks() via reflection.
        $reflection = new ReflectionClass(OverviewController::class);
        $this->subject = $reflection->newInstanceWithoutConstructor();

        $factoryProperty = $reflection->getProperty('masterKeyProviderFactory');
        $factoryProperty->setValue($this->subject, $this->masterKeyProviderFactory);
    }

    #[Test]
    public function healthCheckReturnsGreenWhenProviderAvailableAndKeyWorks(): void
    {
        $provider = $this->createStub(MasterKeyProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('file');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getMasterKey')->willReturn('a-valid-32-byte-master-key------');

        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willReturn($provider);

        $result = $this->invokeGetHealthChecks();

        self::assertTrue($result['masterKeyAvailable']);
        self::assertTrue($result['encryptionWorking']);
        self::assertFalse($result['hasIssues']);
        self::assertSame('file', $result['masterKeyProvider']);
        self::assertSame('', $result['masterKeyError']);
        self::assertSame('', $result['encryptionError']);
    }

    #[Test]
    public function healthCheckReturnsErrorWhenNoProviderAvailable(): void
    {
        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willThrowException(new Exception('No master key provider configured'));

        $result = $this->invokeGetHealthChecks();

        self::assertFalse($result['masterKeyAvailable']);
        self::assertFalse($result['encryptionWorking']);
        self::assertTrue($result['hasIssues']);
        self::assertSame('No master key provider configured', $result['masterKeyError']);
    }

    #[Test]
    public function healthCheckReturnsErrorWhenProviderNotAvailable(): void
    {
        $provider = $this->createStub(MasterKeyProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('env');
        $provider->method('isAvailable')->willReturn(false);

        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willReturn($provider);

        $result = $this->invokeGetHealthChecks();

        self::assertFalse($result['masterKeyAvailable']);
        self::assertFalse($result['encryptionWorking']);
        self::assertTrue($result['hasIssues']);
        self::assertStringContainsString('env', $result['masterKeyError']);
        self::assertStringContainsString('not available', $result['masterKeyError']);
    }

    #[Test]
    public function healthCheckReturnsErrorWhenKeyDerivationFails(): void
    {
        $provider = $this->createStub(MasterKeyProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('file');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getMasterKey')->willThrowException(new Exception('Key file not readable'));

        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willReturn($provider);

        $result = $this->invokeGetHealthChecks();

        self::assertTrue($result['masterKeyAvailable']);
        self::assertFalse($result['encryptionWorking']);
        self::assertTrue($result['hasIssues']);
        self::assertSame('Key file not readable', $result['encryptionError']);
    }

    #[Test]
    public function healthCheckReturnsErrorWhenMasterKeyReturnsEmptyString(): void
    {
        $provider = $this->createStub(MasterKeyProviderInterface::class);
        $provider->method('getIdentifier')->willReturn('file');
        $provider->method('isAvailable')->willReturn(true);
        $provider->method('getMasterKey')->willReturn('');

        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willReturn($provider);

        $result = $this->invokeGetHealthChecks();

        self::assertTrue($result['masterKeyAvailable']);
        self::assertFalse($result['encryptionWorking']);
        self::assertTrue($result['hasIssues']);
        self::assertSame('Master key provider returned an empty key.', $result['encryptionError']);
    }

    /**
     * Invoke the private getHealthChecks() method via reflection.
     *
     * @return array{masterKeyAvailable: bool, masterKeyProvider: string, masterKeyError: string, encryptionWorking: bool, encryptionError: string, hasIssues: bool}
     */
    private function invokeGetHealthChecks(): array
    {
        $method = (new ReflectionClass(OverviewController::class))->getMethod('getHealthChecks');

        /** @var array{masterKeyAvailable: bool, masterKeyProvider: string, masterKeyError: string, encryptionWorking: bool, encryptionError: string, hasIssues: bool} */
        return $method->invoke($this->subject);
    }
}
