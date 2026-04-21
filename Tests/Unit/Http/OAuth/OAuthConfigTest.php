<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http\OAuth;

use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

#[CoversClass(OAuthConfig::class)]
final class OAuthConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $config = new OAuthConfig(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
            grantType: 'client_credentials',
            refreshTokenSecret: 'oauth/refresh-token',
            scopes: ['read', 'write'],
            tokenExpiryBuffer: 120,
            additionalParams: ['audience' => 'https://api.example.com'],
        );

        self::assertSame('https://auth.example.com/token', $config->tokenEndpoint);
        self::assertSame('oauth/client-id', $config->clientIdSecret);
        self::assertSame('oauth/client-secret', $config->clientSecretSecret);
        self::assertSame('client_credentials', $config->grantType);
        self::assertSame('oauth/refresh-token', $config->refreshTokenSecret);
        self::assertSame(['read', 'write'], $config->scopes);
        self::assertSame(120, $config->tokenExpiryBuffer);
        self::assertSame(['audience' => 'https://api.example.com'], $config->additionalParams);
    }

    #[Test]
    public function constructorHasCorrectDefaults(): void
    {
        $config = new OAuthConfig(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
        );

        self::assertSame('client_credentials', $config->grantType);
        self::assertNull($config->refreshTokenSecret);
        self::assertSame([], $config->scopes);
        self::assertSame(60, $config->tokenExpiryBuffer);
        self::assertSame([], $config->additionalParams);
    }

    #[Test]
    public function clientCredentialsCreatesCorrectConfig(): void
    {
        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
            scopes: ['api.read'],
        );

        self::assertSame('https://auth.example.com/token', $config->tokenEndpoint);
        self::assertSame('oauth/client-id', $config->clientIdSecret);
        self::assertSame('oauth/client-secret', $config->clientSecretSecret);
        self::assertSame('client_credentials', $config->grantType);
        self::assertNull($config->refreshTokenSecret);
        self::assertSame(['api.read'], $config->scopes);
    }

    #[Test]
    public function refreshTokenCreatesCorrectConfig(): void
    {
        $config = OAuthConfig::refreshToken(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
            refreshTokenSecret: 'oauth/refresh-token',
            scopes: ['offline_access'],
        );

        self::assertSame('https://auth.example.com/token', $config->tokenEndpoint);
        self::assertSame('oauth/client-id', $config->clientIdSecret);
        self::assertSame('oauth/client-secret', $config->clientSecretSecret);
        self::assertSame('refresh_token', $config->grantType);
        self::assertSame('oauth/refresh-token', $config->refreshTokenSecret);
        self::assertSame(['offline_access'], $config->scopes);
    }

    #[Test]
    public function getScopesStringReturnsSpaceSeparatedScopes(): void
    {
        $config = new OAuthConfig(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
            scopes: ['read', 'write', 'admin'],
        );

        self::assertSame('read write admin', $config->getScopesString());
    }

    #[Test]
    public function getScopesStringReturnsEmptyForNoScopes(): void
    {
        $config = new OAuthConfig(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
            scopes: [],
        );

        self::assertSame('', $config->getScopesString());
    }

    #[Test]
    public function configIsReadonly(): void
    {
        $reflection = new ReflectionClass(OAuthConfig::class);

        self::assertTrue($reflection->isReadOnly());
    }
}
