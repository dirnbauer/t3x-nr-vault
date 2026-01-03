<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Http\AuthenticatedPsr18Client;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

#[CoversClass(AuthenticatedPsr18Client::class)]
final class AuthenticatedPsr18ClientTest extends TestCase
{
    private VaultServiceInterface&MockObject $vaultService;
    private ClientInterface&MockObject $innerClient;

    protected function setUp(): void
    {
        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->innerClient = $this->createMock(ClientInterface::class);
    }

    #[Test]
    public function sendRequestInjectsBearerToken(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('secret-token-123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                self::assertSame('Bearer secret-token-123', $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::Bearer,
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $response = $client->sendRequest($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function sendRequestInjectsApiKeyHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('key-abc-123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                self::assertSame('key-abc-123', $request->getHeaderLine('X-API-Key'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::ApiKey,
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestInjectsCustomHeader(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('custom-value');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                self::assertSame('custom-value', $request->getHeaderLine('X-Custom-Auth'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::Header,
            headerName: 'X-Custom-Auth',
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestInjectsBasicAuthFromCombinedSecret(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_credentials')
            ->willReturn('user:pass123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                $expected = 'Basic ' . base64_encode('user:pass123');
                self::assertSame($expected, $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_credentials',
            placement: SecretPlacement::BasicAuth,
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestInjectsBasicAuthFromSeparateSecrets(): void
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
            ->willReturnCallback(function ($request) {
                $expected = 'Basic ' . base64_encode('john_doe:secret123');
                self::assertSame($expected, $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_password',
            placement: SecretPlacement::BasicAuth,
            usernameSecretIdentifier: 'my_username',
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestInjectsQueryParam(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('query-key-value');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                self::assertStringContainsString('api_key=query-key-value', $request->getUri()->getQuery());

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::QueryParam,
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestInjectsCustomQueryParam(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('token123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                self::assertStringContainsString('access_token=token123', $request->getUri()->getQuery());

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::QueryParam,
            queryParam: 'access_token',
        );

        $request = new Request('GET', 'https://api.example.com/data');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestAppendsToExistingQueryParams(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('my_api_key')
            ->willReturn('key123');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                $query = $request->getUri()->getQuery();
                self::assertStringContainsString('existing=value', $query);
                self::assertStringContainsString('api_key=key123', $query);

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_api_key',
            placement: SecretPlacement::QueryParam,
        );

        $request = new Request('GET', 'https://api.example.com/data?existing=value');
        $client->sendRequest($request);
    }

    #[Test]
    public function sendRequestThrowsOnMissingSecret(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->with('nonexistent_key')
            ->willReturn(null);

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'nonexistent_key',
            placement: SecretPlacement::Bearer,
        );

        $request = new Request('GET', 'https://api.example.com/data');

        $this->expectException(SecretNotFoundException::class);
        $client->sendRequest($request);
    }

    #[Test]
    #[DataProvider('unsupportedPlacementProvider')]
    public function sendRequestThrowsOnUnsupportedPlacement(SecretPlacement $placement): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('secret');

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_key',
            placement: $placement,
        );

        $request = new Request('GET', 'https://api.example.com/data');

        $this->expectException(\InvalidArgumentException::class);
        $client->sendRequest($request);
    }

    /**
     * @return iterable<string, array{SecretPlacement}>
     */
    public static function unsupportedPlacementProvider(): iterable
    {
        yield 'BodyField' => [SecretPlacement::BodyField];
        yield 'OAuth2' => [SecretPlacement::OAuth2];
    }

    #[Test]
    public function sendRequestPreservesOriginalRequest(): void
    {
        $this->vaultService
            ->method('retrieve')
            ->willReturn('token');

        $this->innerClient
            ->expects(self::once())
            ->method('sendRequest')
            ->willReturnCallback(function ($request) {
                // Original headers should be preserved
                self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
                self::assertSame('CustomAgent', $request->getHeaderLine('User-Agent'));
                // Auth header should be added
                self::assertSame('Bearer token', $request->getHeaderLine('Authorization'));

                return new Response(200);
            });

        $client = new AuthenticatedPsr18Client(
            vaultService: $this->vaultService,
            innerClient: $this->innerClient,
            secretIdentifier: 'my_key',
            placement: SecretPlacement::Bearer,
        );

        $request = new Request('POST', 'https://api.example.com/data', [
            'Content-Type' => 'application/json',
            'User-Agent' => 'CustomAgent',
        ]);

        $client->sendRequest($request);
    }
}
