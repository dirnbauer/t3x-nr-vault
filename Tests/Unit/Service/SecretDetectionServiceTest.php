<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\SecretDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(SecretDetectionService::class)]
final class SecretDetectionServiceTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;
    private SecretDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->service = new SecretDetectionService($this->connectionPool);
    }

    #[Test]
    public function scanReturnsEmptyArrayWhenNothingDetected(): void
    {
        // Without database access, only config scanning happens
        // In unit test context, GLOBALS aren't set
        $result = $this->service->scan();

        self::assertIsArray($result);
    }

    #[Test]
    public function getDetectedSecretsCountReturnsZeroInitially(): void
    {
        self::assertSame(0, $this->service->getDetectedSecretsCount());
    }

    #[Test]
    public function getDetectedSecretsBySeverityReturnsGroupedArray(): void
    {
        $result = $this->service->getDetectedSecretsBySeverity();

        self::assertArrayHasKey('critical', $result);
        self::assertArrayHasKey('high', $result);
        self::assertArrayHasKey('medium', $result);
        self::assertArrayHasKey('low', $result);
    }

    #[Test]
    #[DataProvider('columnNamePatternProvider')]
    public function detectsSecretColumnNames(string $columnName, bool $shouldDetect): void
    {
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isSecretColumn');

        $result = $method->invoke($this->service, $columnName);

        self::assertSame($shouldDetect, $result, \sprintf(
            'Column "%s" should %sbe detected as secret',
            $columnName,
            $shouldDetect ? '' : 'NOT ',
        ));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function columnNamePatternProvider(): array
    {
        return [
            'password suffix' => ['user_password', true],
            'password prefix' => ['password_hash', true],
            'api_key suffix' => ['stripe_api_key', true],
            'apikey suffix' => ['stripeapikey', true],
            'api secret' => ['api_secret', true],
            'secret key' => ['secret_key', true],
            'token suffix' => ['access_token', true],
            'auth token' => ['auth_token', true],
            'refresh token' => ['refresh_token', true],
            'credential' => ['user_credentials', true],
            'private key' => ['ssl_private_key', true],
            'encryption key' => ['encryption_key', true],
            'smtp password' => ['smtp_password', true],
            'db password' => ['db_password', true],
            // Non-secret columns
            'regular column' => ['username', false],
            'title' => ['title', false],
            'description' => ['description', false],
            'created_at' => ['created_at', false],
            'uid' => ['uid', false],
            'pid' => ['pid', false],
        ];
    }

    #[Test]
    #[DataProvider('valuePatternProvider')]
    public function detectsKnownApiKeyPatterns(string $value, ?string $expectedPattern): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('detectValuePattern');

        $result = $method->invoke($this->service, $value);

        if ($expectedPattern === null) {
            self::assertNull($result, \sprintf('Value should not match any pattern'));
        } else {
            self::assertSame($expectedPattern, $result, \sprintf(
                'Value should be detected as "%s"',
                $expectedPattern,
            ));
        }
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function valuePatternProvider(): array
    {
        // Note: Using clearly fake/example values to avoid triggering secret scanners
        // The patterns match the format but use obviously fake content
        return [
            'Stripe live key' => ['sk_live_FAKE_TEST_KEY_000000000', 'Stripe live key'],
            'Stripe test key' => ['sk_test_FAKE_TEST_KEY_000000000', 'Stripe test key'],
            'Stripe publishable live' => ['pk_live_FAKE_TEST_KEY_000000000', 'Stripe publishable live'],
            'AWS access key' => ['AKIAEXAMPLEFAKEKEY1', 'AWS Access Key'],
            'GitHub PAT' => ['ghp_FAKE00000000000000000000000000000000', 'GitHub Personal Access Token'],
            'GitHub OAuth' => ['gho_FAKE00000000000000000000000000000000', 'GitHub OAuth Token'],
            'Google API Key' => ['AIzaFAKE_EXAMPLE_KEY_NOT_REAL_00000000', 'Google API Key'],
            // Non-matches
            'regular string' => ['just a regular value', null],
            'short token' => ['abc123', null],
            'email' => ['user@example.com', null],
            'url' => ['https://example.com', null],
        ];
    }

    #[Test]
    #[DataProvider('vaultIdentifierProvider')]
    public function detectsVaultIdentifiers(string $value, bool $isVaultIdentifier): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('looksLikeVaultIdentifier');

        $result = $method->invoke($this->service, $value);

        self::assertSame($isVaultIdentifier, $result);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function vaultIdentifierProvider(): array
    {
        return [
            'TCA format' => ['tx_myext_config__api_key__123', true],
            'vault reference' => ['%vault(my_secret_key)%', true],
            'regular value' => ['some_regular_value', false],
            'API key' => ['sk_live_abcdefghij', false],
            'password' => ['mySecretPassword123', false],
        ];
    }

    #[Test]
    #[DataProvider('encryptedValueProvider')]
    public function detectsEncryptedValues(string $value, bool $looksEncrypted): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('looksEncrypted');

        $result = $method->invoke($this->service, $value);

        self::assertSame($looksEncrypted, $result);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function encryptedValueProvider(): array
    {
        return [
            'long base64' => [str_repeat('YWJjZGVm', 10), true],
            'long hex' => [str_repeat('a1b2c3d4', 15), true],
            'short string' => ['abc123', false],
            'regular text' => ['Hello, World!', false],
            'mixed characters' => ['abc-123_def.ghi', false],
        ];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function calculatesSeverityCorrectly(string $name, array $patterns, string $expectedSeverity): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateSeverity');

        $result = $method->invoke($this->service, $name, $patterns);

        self::assertSame($expectedSeverity, $result);
    }

    /**
     * @return array<string, array{0: string, 1: array<string>, 2: string}>
     */
    public static function severityProvider(): array
    {
        return [
            'with patterns = critical' => ['api_key', ['Stripe live key'], 'critical'],
            'password = high' => ['user_password', [], 'high'],
            'private key = high' => ['ssl_private_key', [], 'high'],
            'secret = high' => ['client_secret', [], 'high'],
            'token = medium' => ['access_token', [], 'medium'],
            'apikey = medium' => ['stripe_apikey', [], 'medium'],
            'api_key = medium' => ['payment_api_key', [], 'medium'],
            'other = low' => ['some_config', [], 'low'],
        ];
    }

    #[Test]
    #[DataProvider('configKeyProvider')]
    public function detectsSecretConfigKeys(string $key, bool $isSecret): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isSecretConfigKey');

        $result = $method->invoke($this->service, $key);

        self::assertSame($isSecret, $result);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function configKeyProvider(): array
    {
        return [
            'apiKey' => ['apiKey', true],
            'API_KEY' => ['API_KEY', true],
            'stripeApiKey' => ['stripeApiKey', true],
            'password' => ['password', true],
            'smtpPassword' => ['smtpPassword', true],
            'clientSecret' => ['clientSecret', true],
            'accessToken' => ['accessToken', true],
            // Non-secrets
            'username' => ['username', false],
            'email' => ['email', false],
            'baseUrl' => ['baseUrl', false],
            'timeout' => ['timeout', false],
        ];
    }

    #[Test]
    #[DataProvider('tableExclusionProvider')]
    public function excludesTablesCorrectly(string $tableName, array $patterns, bool $shouldExclude): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('isTableExcluded');

        $result = $method->invoke($this->service, $tableName, $patterns);

        self::assertSame($shouldExclude, $result);
    }

    /**
     * @return array<string, array{0: string, 1: array<string>, 2: bool}>
     */
    public static function tableExclusionProvider(): array
    {
        return [
            'exact match' => ['cache_hash', ['cache_hash'], true],
            'wildcard match' => ['cache_pages', ['cache_*'], true],
            'cf wildcard' => ['cf_extbase_reflection', ['cf_*'], true],
            'no match' => ['tx_myext_domain_model_item', ['cache_*', 'cf_*'], false],
            'vault table' => ['tx_nrvault_secret', ['tx_nrvault_secret'], true],
        ];
    }
}
