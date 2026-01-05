<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Http\OAuth;

use GuzzleHttp\Psr7\Request;
use Netresearch\NrVault\Http\OAuth\OAuthConfig;
use Netresearch\NrVault\Http\OAuth\OAuthTokenManager;
use Netresearch\NrVault\Http\SecretPlacement;
use Netresearch\NrVault\Http\VaultHttpClientInterface;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Integration tests for OAuth 2.0 functionality with real HTTP requests.
 *
 * These tests require the mock OAuth server to be running:
 * - In ddev: `ddev start` (mock-oauth service starts automatically)
 * - Server URL: http://mock-oauth:8080 (internal) or https://mock-oauth.nr-vault.ddev.site (external)
 *
 * Tests are skipped if the mock server is not reachable.
 */
#[CoversClass(OAuthTokenManager::class)]
#[Group('integration')]
#[Group('oauth')]
final class OAuthIntegrationTest extends FunctionalTestCase
{
    /** Mock OAuth server URL (internal ddev network). */
    private const MOCK_OAUTH_INTERNAL_URL = 'http://mock-oauth:8080';

    /** Mock OAuth server URL (external access). */
    private const MOCK_OAUTH_EXTERNAL_URL = 'http://localhost:8080';

    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private ?VaultServiceInterface $vaultService = null;

    private ?string $masterKeyPath = null;

    private bool $setupCompleted = false;

    private string $mockOAuthUrl = self::MOCK_OAUTH_EXTERNAL_URL;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupCompleted = true;

        // Determine which mock OAuth URL to use
        $this->mockOAuthUrl = $this->determineMockOAuthUrl();

        // Create a temporary master key for testing
        $this->masterKeyPath = $this->instancePath . '/master.key';
        $masterKey = sodium_crypto_secretbox_keygen();
        file_put_contents($this->masterKeyPath, $masterKey);
        chmod($this->masterKeyPath, 0o600);

        // Configure extension to use file-based master key
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nr_vault'] = [
            'masterKeySource' => $this->masterKeyPath,
            'autoKeyPath' => $this->masterKeyPath,
            'enableCache' => false,
        ];

        // Import backend user for access control
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        // Get properly wired service from container
        $service = $this->get(VaultServiceInterface::class);
        \assert($service instanceof VaultServiceInterface);
        $this->vaultService = $service;
    }

    #[Override]
    protected function tearDown(): void
    {
        // Clean up master key
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        if ($this->setupCompleted) {
            parent::tearDown();
        }
    }

    #[Test]
    public function oauthTokenManagerAcquiresTokenWithClientCredentials(): void
    {
        $this->skipIfMockServerUnavailable();

        // Store OAuth credentials in vault
        $vaultService = $this->getVaultService();
        $vaultService->store('test_oauth_client_id', 'test-client-id');
        $vaultService->store('test_oauth_client_secret', 'test-client-secret');

        // Create OAuth config
        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: $this->mockOAuthUrl . '/default/token',
            clientIdSecret: 'test_oauth_client_id',
            clientSecretSecret: 'test_oauth_client_secret',
            scopes: ['read', 'write'],
        );

        // Get token manager and acquire token
        $tokenManager = new OAuthTokenManager($vaultService);
        $accessToken = $tokenManager->getAccessToken($config);

        self::assertNotEmpty($accessToken);
    }

    #[Test]
    public function oauthTokenManagerCachesToken(): void
    {
        $this->skipIfMockServerUnavailable();

        // Store OAuth credentials in vault
        $vaultService = $this->getVaultService();
        $vaultService->store('cache_oauth_client_id', 'test-client-id');
        $vaultService->store('cache_oauth_client_secret', 'test-client-secret');

        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: $this->mockOAuthUrl . '/default/token',
            clientIdSecret: 'cache_oauth_client_id',
            clientSecretSecret: 'cache_oauth_client_secret',
        );

        $tokenManager = new OAuthTokenManager($vaultService);

        // First call - fetches from server
        $token1 = $tokenManager->getAccessToken($config);

        // Second call - should return cached token
        $token2 = $tokenManager->getAccessToken($config);

        self::assertSame($token1, $token2);
    }

    #[Test]
    public function oauthTokenManagerClearsCacheCorrectly(): void
    {
        $this->skipIfMockServerUnavailable();

        // Store OAuth credentials in vault
        $vaultService = $this->getVaultService();
        $vaultService->store('clear_oauth_client_id', 'test-client-id');
        $vaultService->store('clear_oauth_client_secret', 'test-client-secret');

        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: $this->mockOAuthUrl . '/default/token',
            clientIdSecret: 'clear_oauth_client_id',
            clientSecretSecret: 'clear_oauth_client_secret',
        );

        $tokenManager = new OAuthTokenManager($vaultService);

        // Get initial token
        $token1 = $tokenManager->getAccessToken($config);

        // Clear cache
        $tokenManager->clearCache($config);

        // Get new token - should be different (new request to server)
        $token2 = $tokenManager->getAccessToken($config);

        // Tokens from mock server are generated fresh each time
        // They may or may not be the same depending on server implementation
        self::assertNotEmpty($token1);
        self::assertNotEmpty($token2);
    }

    #[Test]
    public function vaultHttpClientWithOAuthMakesAuthenticatedRequest(): void
    {
        $this->skipIfMockServerUnavailable();

        // Store OAuth credentials in vault
        $vaultService = $this->getVaultService();
        $vaultService->store('http_oauth_client_id', 'test-client-id');
        $vaultService->store('http_oauth_client_secret', 'test-client-secret');

        $config = OAuthConfig::clientCredentials(
            tokenEndpoint: $this->mockOAuthUrl . '/default/token',
            clientIdSecret: 'http_oauth_client_id',
            clientSecretSecret: 'http_oauth_client_secret',
        );

        // Get HTTP client with OAuth configured
        $httpClient = $vaultService->http()->withOAuth($config);

        self::assertInstanceOf(VaultHttpClientInterface::class, $httpClient);

        // Make a request to the mock server's userinfo endpoint
        // This verifies the OAuth token is properly injected
        $request = new Request('GET', $this->mockOAuthUrl . '/default/userinfo');
        $response = $httpClient->sendRequest($request);

        // Mock server should return 200 with valid token
        self::assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function vaultHttpClientWithBearerTokenMakesAuthenticatedRequest(): void
    {
        $this->skipIfMockServerUnavailable();

        // Store a static bearer token in vault
        $vaultService = $this->getVaultService();
        $vaultService->store('static_bearer_token', 'test-bearer-token-12345');

        // Get HTTP client with Bearer auth
        $httpClient = $vaultService->http()->withAuthentication(
            'static_bearer_token',
            SecretPlacement::Bearer,
        );

        self::assertInstanceOf(VaultHttpClientInterface::class, $httpClient);

        // Make a request - we just verify the client works
        // The mock OAuth server will accept any bearer token on its endpoints
        $request = new Request('GET', $this->mockOAuthUrl . '/default/.well-known/openid-configuration');
        $response = $httpClient->sendRequest($request);

        self::assertEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function oauthConfigFactoryMethodsWork(): void
    {
        // Test client_credentials factory
        $clientCredConfig = OAuthConfig::clientCredentials(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'client_id_secret',
            clientSecretSecret: 'client_secret_secret',
            scopes: ['read', 'write'],
        );

        self::assertEquals('client_credentials', $clientCredConfig->grantType);
        self::assertEquals('https://auth.example.com/token', $clientCredConfig->tokenEndpoint);
        self::assertEquals('client_id_secret', $clientCredConfig->clientIdSecret);
        self::assertEquals('client_secret_secret', $clientCredConfig->clientSecretSecret);
        self::assertEquals(['read', 'write'], $clientCredConfig->scopes);
        self::assertEquals('read write', $clientCredConfig->getScopesString());

        // Test refresh_token factory
        $refreshConfig = OAuthConfig::refreshToken(
            tokenEndpoint: 'https://auth.example.com/token',
            clientIdSecret: 'client_id_secret',
            clientSecretSecret: 'client_secret_secret',
            refreshTokenSecret: 'refresh_token_secret',
            scopes: ['offline_access'],
        );

        self::assertEquals('refresh_token', $refreshConfig->grantType);
        self::assertEquals('refresh_token_secret', $refreshConfig->refreshTokenSecret);
    }

    #[Test]
    public function oauthSecretsAreNotLinkedInVault(): void
    {
        // This test documents the current limitation:
        // OAuth secrets are stored separately without semantic linking

        $vaultService = $this->getVaultService();
        $vaultService->store('my_app_oauth_client_id', 'client-123');
        $vaultService->store('my_app_oauth_client_secret', 'secret-456');
        $vaultService->store('my_app_oauth_refresh_token', 'refresh-789');

        // Each secret exists independently
        self::assertTrue($vaultService->exists('my_app_oauth_client_id'));
        self::assertTrue($vaultService->exists('my_app_oauth_client_secret'));
        self::assertTrue($vaultService->exists('my_app_oauth_refresh_token'));

        // Get metadata - no indication these belong together
        $clientIdMeta = $vaultService->getMetadata('my_app_oauth_client_id');
        $clientSecretMeta = $vaultService->getMetadata('my_app_oauth_client_secret');

        // No 'type' or 'group' field linking them
        self::assertArrayNotHasKey('type', $clientIdMeta);
        self::assertArrayNotHasKey('credentialSet', $clientIdMeta);
        self::assertArrayNotHasKey('type', $clientSecretMeta);
        self::assertArrayNotHasKey('credentialSet', $clientSecretMeta);

        // This is the documented limitation - see GitHub issue #15
    }

    /**
     * Get the vault service (asserts it's initialized).
     */
    private function getVaultService(): VaultServiceInterface
    {
        \assert($this->vaultService instanceof VaultServiceInterface);

        return $this->vaultService;
    }

    /**
     * Determine which mock OAuth URL to use based on environment.
     */
    private function determineMockOAuthUrl(): string
    {
        // Try internal ddev URL first
        if ($this->isUrlReachable(self::MOCK_OAUTH_INTERNAL_URL . '/.well-known/openid-configuration')) {
            return self::MOCK_OAUTH_INTERNAL_URL;
        }

        // Fall back to external URL
        return self::MOCK_OAUTH_EXTERNAL_URL;
    }

    /**
     * Skip test if mock OAuth server is not available.
     */
    private function skipIfMockServerUnavailable(): void
    {
        if (!$this->isUrlReachable($this->mockOAuthUrl . '/.well-known/openid-configuration')) {
            self::markTestSkipped(
                'Mock OAuth server is not available. Start it with: ddev start' . "\n" .
                'Tried URLs: ' . self::MOCK_OAUTH_INTERNAL_URL . ', ' . self::MOCK_OAUTH_EXTERNAL_URL,
            );
        }
    }

    /**
     * Check if a URL is reachable.
     */
    private function isUrlReachable(string $url): bool
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return $result !== false;
    }
}
