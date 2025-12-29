<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\OAuth\OAuthTokenManager;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Http\VaultHttpResponse;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

#[CoversClass(VaultHttpClient::class)]
final class VaultHttpClientTest extends TestCase
{
    private VaultHttpClient $subject;

    private VaultServiceInterface&MockObject $vaultService;

    private AuditLogServiceInterface&MockObject $auditLogService;

    private ClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->auditLogService = $this->createMock(AuditLogServiceInterface::class);
        $this->httpClient = $this->createMock(ClientInterface::class);

        $this->subject = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->httpClient,
        );
    }

    #[Test]
    public function requestWithBearerPlacementSetsAuthorizationHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/token')
            ->willReturn('my-bearer-token');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/token',
            'placement' => SecretPlacement::Bearer,
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame('Bearer my-bearer-token', $capturedOptions['headers']['Authorization']);
    }

    #[Test]
    public function requestWithBasicAuthPlacementSetsBasicHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/credentials')
            ->willReturn('username:password');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/credentials',
            'placement' => SecretPlacement::BasicAuth,
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame(
            'Basic ' . base64_encode('username:password'),
            $capturedOptions['headers']['Authorization'],
        );
    }

    #[Test]
    public function requestWithHeaderPlacementSetsCustomHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/key')
            ->willReturn('my-api-key');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/key',
            'placement' => SecretPlacement::Header,
            'auth_header' => 'X-Custom-Auth',
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame('my-api-key', $capturedOptions['headers']['X-Custom-Auth']);
    }

    #[Test]
    public function requestWithApiKeyPlacementSetsXApiKeyHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/key')
            ->willReturn('my-api-key');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/key',
            'placement' => SecretPlacement::ApiKey,
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame('my-api-key', $capturedOptions['headers']['X-API-Key']);
    }

    #[Test]
    public function requestWithQueryParamPlacementSetsQueryParameter(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/key')
            ->willReturn('my-api-key');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/key',
            'placement' => SecretPlacement::QueryParam,
            'auth_query_param' => 'apiKey',
        ]);

        self::assertArrayHasKey('query', $capturedOptions);
        self::assertSame('my-api-key', $capturedOptions['query']['apiKey']);
    }

    #[Test]
    public function requestWithBodyFieldPlacementSetsJsonField(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/key')
            ->willReturn('my-api-key');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('POST', 'https://api.example.com/data', [
            'auth_secret' => 'api/key',
            'placement' => SecretPlacement::BodyField,
            'auth_body_field' => 'api_key',
            'json' => ['data' => 'test'],
        ]);

        self::assertArrayHasKey('json', $capturedOptions);
        self::assertSame('my-api-key', $capturedOptions['json']['api_key']);
        self::assertSame('test', $capturedOptions['json']['data']);
    }

    #[Test]
    public function requestWithOAuth2PlacementUsesTokenManager(): void
    {
        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'oauth/client-id',
            clientSecretSecret: 'oauth/client-secret',
        );

        $tokenManager = $this->createMock(OAuthTokenManager::class);
        $tokenManager
            ->expects(self::once())
            ->method('getAccessToken')
            ->with($config)
            ->willReturn('oauth-access-token');

        $this->subject->setOAuthManager($tokenManager);

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'placement' => SecretPlacement::OAuth2,
            'oauth_config' => $config,
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame('Bearer oauth-access-token', $capturedOptions['headers']['Authorization']);
    }

    #[Test]
    public function requestThrowsForMissingSecret(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/missing')
            ->willReturn(null);

        $this->expectException(SecretNotFoundException::class);

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/missing',
            'placement' => SecretPlacement::Bearer,
        ]);
    }

    #[Test]
    public function requestLogsSuccessfulCall(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('token');

        $response = $this->createSuccessfulResponse(200);

        $this->httpClient
            ->method('request')
            ->willReturn($response);

        $this->auditLogService
            ->expects(self::once())
            ->method('log')
            ->with(
                'api/token',
                'http_call',
                true,
                null,
                'API request',
                null,
                null,
                self::callback(
                    fn (array $meta) => $meta['method'] === 'GET'
                        && $meta['host'] === 'api.example.com'
                        && $meta['status_code'] === 200,
                ),
            );

        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/token',
            'placement' => SecretPlacement::Bearer,
            'reason' => 'API request',
        ]);
    }

    #[Test]
    public function requestLogsFailedCall(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->method('request')
            ->willThrowException(new RequestException(
                'Connection refused',
                new Request('GET', 'https://api.example.com/data'),
            ));

        $this->auditLogService
            ->expects(self::once())
            ->method('log')
            ->with(
                'none',
                'http_call',
                false,
                'Connection refused',
                self::anything(),
                null,
                null,
                self::callback(fn (array $meta) => $meta['status_code'] === 0),
            );

        $this->expectException(VaultException::class);
        $this->expectExceptionMessage('HTTP request failed: Connection refused');

        $this->subject->request('GET', 'https://api.example.com/data');
    }

    #[Test]
    public function sendReturnsVaultHttpResponse(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->method('request')
            ->willReturn($response);

        $result = $this->subject->send('GET', 'https://api.example.com/data');

        self::assertInstanceOf(VaultHttpResponse::class, $result);
        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function getMethodCallsRequest(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::anything())
            ->willReturn($response);

        $result = $this->subject->get('https://api.example.com/data');

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function postMethodCallsRequest(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('POST', 'https://api.example.com/data', self::anything())
            ->willReturn($response);

        $result = $this->subject->post('https://api.example.com/data', ['json' => ['foo' => 'bar']]);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function putMethodCallsRequest(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('PUT', 'https://api.example.com/data', self::anything())
            ->willReturn($response);

        $result = $this->subject->put('https://api.example.com/data');

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function deleteMethodCallsRequest(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('DELETE', 'https://api.example.com/data', self::anything())
            ->willReturn($response);

        $result = $this->subject->delete('https://api.example.com/data');

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function patchMethodCallsRequest(): void
    {
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('PATCH', 'https://api.example.com/data', self::anything())
            ->willReturn($response);

        $result = $this->subject->patch('https://api.example.com/data');

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function getOAuthManagerReturnsManager(): void
    {
        $manager = $this->subject->getOAuthManager();

        self::assertInstanceOf(OAuthTokenManager::class, $manager);
    }

    #[Test]
    public function getOAuthManagerReturnsSameInstance(): void
    {
        $manager1 = $this->subject->getOAuthManager();
        $manager2 = $this->subject->getOAuthManager();

        self::assertSame($manager1, $manager2);
    }

    #[Test]
    public function setOAuthManagerOverridesManager(): void
    {
        $customManager = $this->createMock(OAuthTokenManager::class);

        $this->subject->setOAuthManager($customManager);

        self::assertSame($customManager, $this->subject->getOAuthManager());
    }

    #[Test]
    public function requestWithStringAuthTypeUsesBackwardsCompatibility(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('api/token')
            ->willReturn('my-token');

        $capturedOptions = null;
        $response = $this->createSuccessfulResponse();

        $this->httpClient
            ->expects(self::once())
            ->method('request')
            ->with('GET', 'https://api.example.com/data', self::callback(
                function (array $options) use (&$capturedOptions) {
                    $capturedOptions = $options;

                    return true;
                },
            ))
            ->willReturn($response);

        // Using string auth_type instead of SecretPlacement enum
        $this->subject->request('GET', 'https://api.example.com/data', [
            'auth_secret' => 'api/token',
            'auth_type' => 'bearer',
        ]);

        self::assertArrayHasKey('headers', $capturedOptions);
        self::assertSame('Bearer my-token', $capturedOptions['headers']['Authorization']);
    }

    private function createSuccessfulResponse(int $statusCode = 200): ResponseInterface&MockObject
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('{"status": "ok"}');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getBody')->willReturn($stream);

        return $response;
    }
}
