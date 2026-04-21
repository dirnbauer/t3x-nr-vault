<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Configuration\Dto;

use Netresearch\NrVault\Configuration\Dto\VaultServerConfig;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(VaultServerConfig::class)]
final class VaultServerConfigTest extends TestCase
{
    #[Test]
    public function constructorDefaultsAllPropertiesToEmptyString(): void
    {
        $subject = new VaultServerConfig();

        self::assertSame('', $subject->address);
        self::assertSame('', $subject->path);
        self::assertSame('', $subject->authMethod);
        self::assertSame('', $subject->token);
    }

    #[Test]
    public function constructorSetsProvidedValues(): void
    {
        $subject = new VaultServerConfig(
            address: 'https://vault.example.com',
            path: 'secret/data',
            authMethod: 'token',
            token: 's.mytoken123',
        );

        self::assertSame('https://vault.example.com', $subject->address);
        self::assertSame('secret/data', $subject->path);
        self::assertSame('token', $subject->authMethod);
        self::assertSame('s.mytoken123', $subject->token);
    }

    #[Test]
    public function fromArrayCreatesObjectWithAllFields(): void
    {
        $subject = VaultServerConfig::fromArray([
            'address' => 'https://vault.internal',
            'path' => 'kv/myapp',
            'authMethod' => 'token',
            'token' => 's.abc123',
        ]);

        self::assertSame('https://vault.internal', $subject->address);
        self::assertSame('kv/myapp', $subject->path);
        self::assertSame('token', $subject->authMethod);
        self::assertSame('s.abc123', $subject->token);
    }

    #[Test]
    public function fromArrayWithEmptyArrayUsesEmptyStringDefaults(): void
    {
        $subject = VaultServerConfig::fromArray([]);

        self::assertSame('', $subject->address);
        self::assertSame('', $subject->path);
        self::assertSame('', $subject->authMethod);
        self::assertSame('', $subject->token);
    }

    #[Test]
    public function fromArrayIgnoresMissingOptionalFields(): void
    {
        $subject = VaultServerConfig::fromArray([
            'address' => 'https://vault.example.com',
            'path' => 'secret',
        ]);

        self::assertSame('https://vault.example.com', $subject->address);
        self::assertSame('secret', $subject->path);
        self::assertSame('', $subject->authMethod);
        self::assertSame('', $subject->token);
    }

    #[Test]
    #[DataProvider('isValidProvider')]
    public function isValidReturnsCorrectResult(
        string $address,
        string $path,
        bool $expected,
    ): void {
        $subject = new VaultServerConfig(address: $address, path: $path);

        self::assertSame($expected, $subject->isValid());
    }

    public static function isValidProvider(): iterable
    {
        yield 'address and path set => valid' => ['https://vault.example.com', 'kv/data', true];
        yield 'empty address => invalid' => ['', 'kv/data', false];
        yield 'empty path => invalid' => ['https://vault.example.com', '', false];
        yield 'both empty => invalid' => ['', '', false];
    }

    #[Test]
    public function isValidIgnoresAuthMethodAndToken(): void
    {
        $withToken = new VaultServerConfig(
            address: 'https://vault.com',
            path: 'kv',
            authMethod: 'token',
            token: 's.abc',
        );
        $withoutToken = new VaultServerConfig(
            address: 'https://vault.com',
            path: 'kv',
        );

        self::assertTrue($withToken->isValid());
        self::assertTrue($withoutToken->isValid());
    }

    #[Test]
    #[DataProvider('hasTokenAuthProvider')]
    public function hasTokenAuthReturnsCorrectResult(
        string $token,
        string $authMethod,
        bool $expected,
    ): void {
        $subject = new VaultServerConfig(
            address: 'https://vault.com',
            path: 'kv',
            authMethod: $authMethod,
            token: $token,
        );

        self::assertSame($expected, $subject->hasTokenAuth());
    }

    public static function hasTokenAuthProvider(): iterable
    {
        yield 'token and authMethod=token => true' => ['s.mytoken', 'token', true];
        yield 'token but wrong authMethod => false' => ['s.mytoken', 'approle', false];
        yield 'authMethod=token but no token => false' => ['', 'token', false];
        yield 'both empty => false' => ['', '', false];
        yield 'neither set => false' => ['', 'approle', false];
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $subject = new VaultServerConfig(
            address: 'https://vault.example.com',
            path: 'kv/myapp',
            authMethod: 'token',
            token: 's.secret',
        );

        self::assertSame([
            'address' => 'https://vault.example.com',
            'path' => 'kv/myapp',
            'authMethod' => 'token',
            'token' => 's.secret',
        ], $subject->toArray());
    }

    #[Test]
    public function toArrayWithDefaultsReturnsAllEmptyStrings(): void
    {
        $subject = new VaultServerConfig();

        self::assertSame([
            'address' => '',
            'path' => '',
            'authMethod' => '',
            'token' => '',
        ], $subject->toArray());
    }

    #[Test]
    public function toArrayContainsExactlyFourKeys(): void
    {
        $subject = new VaultServerConfig();

        self::assertCount(4, $subject->toArray());
    }

    #[Test]
    public function fromArrayRoundTripToArray(): void
    {
        $original = [
            'address' => 'https://vault.internal',
            'path' => 'secret/data',
            'authMethod' => 'token',
            'token' => 's.roundtrip',
        ];

        $subject = VaultServerConfig::fromArray($original);

        self::assertSame($original, $subject->toArray());
    }
}
