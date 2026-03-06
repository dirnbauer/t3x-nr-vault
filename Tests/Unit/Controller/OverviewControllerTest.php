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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for OverviewController health checks.
 *
 * Tests the getHealthChecks() logic via reflection since the controller
 * depends on final TYPO3 classes that cannot be fully mocked for indexAction().
 * CoversNothing because OverviewController is excluded from unit test coverage
 * (tested via functional tests for full coverage).
 */
#[CoversNothing]
final class OverviewControllerTest extends TestCase
{
    private MasterKeyProviderFactoryInterface&MockObject $masterKeyProviderFactory;

    private OverviewController $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masterKeyProviderFactory = $this->createMock(MasterKeyProviderFactoryInterface::class);

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
        $provider = $this->createMock(MasterKeyProviderInterface::class);
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
        $provider = $this->createMock(MasterKeyProviderInterface::class);
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
        $provider = $this->createMock(MasterKeyProviderInterface::class);
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
        $provider = $this->createMock(MasterKeyProviderInterface::class);
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
