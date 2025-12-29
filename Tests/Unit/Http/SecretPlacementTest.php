<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http;

use Netresearch\NrVault\Http\SecretPlacement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SecretPlacement::class)]
final class SecretPlacementTest extends TestCase
{
    #[Test]
    public function allCasesHaveDescriptions(): void
    {
        foreach (SecretPlacement::cases() as $case) {
            $description = $case->description();
            self::assertNotEmpty($description, "Case {$case->name} should have a description");
            self::assertIsString($description);
        }
    }

    #[Test]
    #[DataProvider('configRequirementProvider')]
    public function requiresConfigReturnsCorrectValue(SecretPlacement $placement, bool $expected): void
    {
        self::assertSame($expected, $placement->requiresConfig());
    }

    public static function configRequirementProvider(): iterable
    {
        yield 'Bearer does not require config' => [SecretPlacement::Bearer, false];
        yield 'BasicAuth does not require config' => [SecretPlacement::BasicAuth, false];
        yield 'Header requires config' => [SecretPlacement::Header, true];
        yield 'QueryParam requires config' => [SecretPlacement::QueryParam, true];
        yield 'BodyField requires config' => [SecretPlacement::BodyField, true];
        yield 'OAuth2 does not require config' => [SecretPlacement::OAuth2, false];
        yield 'ApiKey does not require config' => [SecretPlacement::ApiKey, false];
    }

    #[Test]
    #[DataProvider('defaultConfigKeyProvider')]
    public function defaultConfigKeyReturnsCorrectValue(SecretPlacement $placement, ?string $expected): void
    {
        self::assertSame($expected, $placement->defaultConfigKey());
    }

    public static function defaultConfigKeyProvider(): iterable
    {
        yield 'Header has auth_header key' => [SecretPlacement::Header, 'auth_header'];
        yield 'QueryParam has auth_query_param key' => [SecretPlacement::QueryParam, 'auth_query_param'];
        yield 'BodyField has auth_body_field key' => [SecretPlacement::BodyField, 'auth_body_field'];
        yield 'Bearer has no config key' => [SecretPlacement::Bearer, null];
        yield 'BasicAuth has no config key' => [SecretPlacement::BasicAuth, null];
        yield 'OAuth2 has no config key' => [SecretPlacement::OAuth2, null];
        yield 'ApiKey has no config key' => [SecretPlacement::ApiKey, null];
    }

    #[Test]
    public function tryFromReturnsCorrectCase(): void
    {
        self::assertSame(SecretPlacement::Bearer, SecretPlacement::tryFrom('bearer'));
        self::assertSame(SecretPlacement::BasicAuth, SecretPlacement::tryFrom('basic'));
        self::assertSame(SecretPlacement::Header, SecretPlacement::tryFrom('header'));
        self::assertSame(SecretPlacement::QueryParam, SecretPlacement::tryFrom('query'));
        self::assertSame(SecretPlacement::BodyField, SecretPlacement::tryFrom('body_field'));
        self::assertSame(SecretPlacement::OAuth2, SecretPlacement::tryFrom('oauth2'));
        self::assertSame(SecretPlacement::ApiKey, SecretPlacement::tryFrom('api_key'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(SecretPlacement::tryFrom('invalid'));
        self::assertNull(SecretPlacement::tryFrom(''));
        self::assertNull(SecretPlacement::tryFrom('BEARER'));
    }

    #[Test]
    public function casesReturnsAllValues(): void
    {
        $cases = SecretPlacement::cases();

        self::assertCount(7, $cases);
        self::assertContains(SecretPlacement::Bearer, $cases);
        self::assertContains(SecretPlacement::BasicAuth, $cases);
        self::assertContains(SecretPlacement::Header, $cases);
        self::assertContains(SecretPlacement::QueryParam, $cases);
        self::assertContains(SecretPlacement::BodyField, $cases);
        self::assertContains(SecretPlacement::OAuth2, $cases);
        self::assertContains(SecretPlacement::ApiKey, $cases);
    }
}
