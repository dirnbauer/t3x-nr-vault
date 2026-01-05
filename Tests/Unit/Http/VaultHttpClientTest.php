<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrVault\Audit\AuditContextInterface;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Audit\HttpCallContext;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClient;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

#[CoversClass(VaultHttpClient::class)]
final class VaultHttpClientTest extends TestCase
{
    /** @phpstan-ignore property.uninitialized */
    private VaultServiceInterface&MockObject $vaultService;

    /** @phpstan-ignore property.uninitialized */
    private AuditLogServiceInterface&MockObject $auditLogService;

    /** @phpstan-ignore property.uninitialized */
    private ClientInterface&MockObject $innerClient;

    protected function setUp(): void
    {
        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->auditLogService = $this->createMock(AuditLogServiceInterface::class);
        $this->innerClient = $this->createMock(ClientInterface::class);
    }

    #[Test]
    public function implementsClientInterface(): void
    {
        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function sendRequestWithoutAuthPassesRequestUnmodified(): void
    {
        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertFalse($request->hasHeader('Authorization'));

                return new Response(200);
            });

        $this->auditLogService
            ->expects(self::once())
            ->method('log');

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $response = $client->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function withAuthenticationReturnNewInstance(): void
    {
        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_api_key', SecretPlacement::Bearer);

        self::assertNotSame($client, $authenticatedClient);
        self::assertInstanceOf(VaultHttpClient::class, $authenticatedClient);
    }

    #[Test]
    public function withAuthenticationInjectsBearerToken(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('secret-token-123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertSame('Bearer secret-token-123', $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_api_key', SecretPlacement::Bearer);

        $request = new Request('GET', 'https://api.example.com/data');
        $response = $authenticatedClient->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function withAuthenticationInjectsApiKeyHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('key-abc-123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertSame('key-abc-123', $request->getHeaderLine('X-API-Key'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_api_key', SecretPlacement::ApiKey);

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationInjectsCustomHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('custom-value');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertSame('custom-value', $request->getHeaderLine('X-Custom-Auth'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication(
            'my_api_key',
            SecretPlacement::Header,
            ['headerName' => 'X-Custom-Auth'],
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationInjectsBasicAuthFromCombinedSecret(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_credentials')
            ->willReturn('user:pass123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                $expected = 'Basic ' . \base64_encode('user:pass123');
                self::assertSame($expected, $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_credentials', SecretPlacement::BasicAuth);

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationInjectsBasicAuthFromSeparateSecrets(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturnMap([
                ['my_username', 'john_doe'],
                ['my_password', 'secret123'],
            ]);

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                $expected = 'Basic ' . \base64_encode('john_doe:secret123');
                self::assertSame($expected, $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication(
            'my_password',
            SecretPlacement::BasicAuth,
            ['usernameSecret' => 'my_username'],
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationInjectsQueryParam(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('query-key-value');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertStringContainsString('api_key=query-key-value', $request->getUri()->getQuery());

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_api_key', SecretPlacement::QueryParam);

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationInjectsCustomQueryParam(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('token123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                self::assertStringContainsString('access_token=token123', $request->getUri()->getQuery());

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication(
            'my_api_key',
            SecretPlacement::QueryParam,
            ['queryParam' => 'access_token'],
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withAuthenticationAppendsToExistingQueryParams(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('key123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                $query = $request->getUri()->getQuery();
                self::assertStringContainsString('existing=value', $query);
                self::assertStringContainsString('api_key=key123', $query);

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_api_key', SecretPlacement::QueryParam);

        $request = new Request('GET', 'https://api.example.com/data?existing=value');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function sendRequestThrowsOnMissingSecret(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('nonexistent_key')
            ->willReturn(null);

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('nonexistent_key', SecretPlacement::Bearer);

        $request = new Request('GET', 'https://api.example.com/data');

        $this->expectException(SecretNotFoundException::class);
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function withReasonSetsAuditReason(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('secret');

        $this->innerClient
            ->method('sendRequest')
            ->willReturn(new Response(200));

        $this->auditLogService
            ->expects(self::once())
            ->method('log')
            ->willReturnCallback(function (
                string $identifier,
                string $action,
                bool $success,
                ?string $error,
                string $reason,
            ): void {
                self::assertSame('Custom audit reason', $reason);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client
            ->withAuthentication('my_key', SecretPlacement::Bearer)
            ->withReason('Custom audit reason');

        $request = new Request('GET', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function sendRequestPreservesOriginalRequestHeaders(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('token');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function (RequestInterface $request): Response {
                // Original headers should be preserved
                self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
                self::assertSame('CustomAgent', $request->getHeaderLine('User-Agent'));
                // Auth header should be added
                self::assertSame('Bearer token', $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_key', SecretPlacement::Bearer);

        $request = new Request('POST', 'https://api.example.com/data', [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CustomAgent',
        ]);

        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function auditLogsSuccessfulRequest(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('secret');

        $this->innerClient
            ->method('sendRequest')
            ->willReturn(new Response(201));

        $this->auditLogService
            ->expects(self::once())
            ->method('log')
            ->willReturnCallback(function (
                string $identifier,
                string $action,
                bool $success,
                ?string $error,
                ?string $reason,
                ?string $hashBefore,
                ?string $hashAfter,
                ?AuditContextInterface $context,
            ): void {
                self::assertSame('my_key', $identifier);
                self::assertSame('http_call', $action);
                self::assertTrue($success);
                self::assertNull($error);
                self::assertInstanceOf(HttpCallContext::class, $context);
                self::assertSame('POST', $context->method);
                self::assertSame(201, $context->statusCode);
            });

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        $authenticatedClient = $client->withAuthentication('my_key', SecretPlacement::Bearer);

        $request = new Request('POST', 'https://api.example.com/data');
        $authenticatedClient->sendRequest($request);
    }

    #[Test]
    public function fluentChainingWorks(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('secret');

        $this->innerClient
            ->method('sendRequest')
            ->willReturn(new Response(200));

        $client = new VaultHttpClient(
            $this->vaultService,
            $this->auditLogService,
            $this->innerClient,
        );

        // Should be able to chain fluently
        $response = $client
            ->withAuthentication('my_key', SecretPlacement::Bearer)
            ->withReason('API call for order processing')
            ->sendRequest(new Request('GET', 'https://api.example.com'));

        self::assertSame(200, $response->getStatusCode());
    }
}
