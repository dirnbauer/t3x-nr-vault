<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Service;

use Netresearch\NrVault\Service\Detection\Severity;
use Netresearch\NrVault\Service\SecretDetectionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;

#[CoversClass(SecretDetectionService::class)]
final class SecretDetectionServiceTest extends TestCase
{
    private ConnectionPool&MockObject $connectionPool;

    private PackageManager&MockObject $packageManager;

    private ExtensionConfiguration&MockObject $extensionConfiguration;

    private SecretDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->packageManager = $this->createMock(PackageManager::class);
        $this->extensionConfiguration = $this->createMock(ExtensionConfiguration::class);

        $this->service = new SecretDetectionService(
            $this->connectionPool,
            $this->packageManager,
            $this->extensionConfiguration,
        );
    }

    #[Test]
    public function getDetectedSecretsReturnsEmptyArrayInitially(): void
    {
        // Test that the service returns empty results before any scanning
        // Note: scan() requires full TYPO3 bootstrap, so we test the result accessors instead
        $result = $this->service->getDetectedSecretsBySeverity();

        self::assertIsArray($result);
        self::assertArrayHasKey('critical', $result);
        self::assertEmpty($result['critical']);
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
        $reflection = new ReflectionClass($this->service);
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
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('detectValuePattern');

        $result = $method->invoke($this->service, $value);

        if ($expectedPattern === null) {
            self::assertNull($result, 'Value should not match any pattern');
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
        // Note: Using runtime-constructed values to avoid triggering GitHub's secret scanner
        // Values are assembled from parts so they don't appear as complete secrets in source
        $stripePrefix = 'sk_live_';
        $stripeTestPrefix = 'sk_test_';
        $stripePubPrefix = 'pk_live_';
        $fakeSuffix = '0123456789ABCDEFGHIJKLMNOP'; // 26 chars, meets 24+ requirement

        return [
            'Stripe live key' => [$stripePrefix . $fakeSuffix, 'Stripe live key'],
            'Stripe test key' => [$stripeTestPrefix . $fakeSuffix, 'Stripe test key'],
            'Stripe publishable live' => [$stripePubPrefix . $fakeSuffix, 'Stripe publishable live'],
            'AWS access key' => ['AKIAEXAMPLEFAKEKEY12', 'AWS Access Key'],
            'GitHub PAT' => ['ghp_FAKE000000000000000000000000000000AB', 'GitHub Personal Access Token'],
            'GitHub OAuth' => ['gho_FAKE000000000000000000000000000000AB', 'GitHub OAuth Token'],
            'Google API Key' => ['AIzaFAKEEXAMPLEKEYNOTREAL000000000000ab', 'Google API Key'],
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
        $reflection = new ReflectionClass($this->service);
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
            'UUID v7 format' => ['01937b6e-4b6c-7abc-8def-0123456789ab', true],
            'vault reference' => ['%vault(my_secret_key)%', true],
            'old TCA format (no longer detected)' => ['tx_myext_config__api_key__123', false],
            'regular value' => ['some_regular_value', false],
            'API key' => ['sk_live_abcdefghij', false],
            'password' => ['mySecretPassword123', false],
        ];
    }

    #[Test]
    #[DataProvider('encryptedValueProvider')]
    public function detectsEncryptedValues(string $value, bool $looksEncrypted): void
    {
        $reflection = new ReflectionClass($this->service);
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
            // Password hashes (should be detected as "encrypted"/secured)
            'bcrypt hash $2y$' => ['$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', true],
            'bcrypt hash $2a$' => ['$2a$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', true],
            'bcrypt hash $2b$' => ['$2b$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234', true],
            'argon2i hash' => ['$argon2i$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG', true],
            'argon2id hash' => ['$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$GpZ3sK6WLbDpeYfZ8bLz', true],
            // Encrypted data
            'long base64' => [\str_repeat('YWJjZGVm', 10), true],
            'long hex' => [\str_repeat('a1b2c3d4', 15), true],
            // Plaintext (should NOT be detected as encrypted)
            'short string' => ['abc123', false],
            'regular text' => ['Hello, World!', false],
            'mixed characters' => ['abc-123_def.ghi', false],
            'plaintext password' => ['MySecretPassword123!', false],
            'short hash-like' => ['$2y$10$short', false],
        ];
    }

    #[Test]
    #[DataProvider('severityProvider')]
    public function calculatesSeverityCorrectly(string $name, array $patterns, Severity $expectedSeverity): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('calculateSeverity');

        $result = $method->invoke($this->service, $name, $patterns);

        self::assertSame($expectedSeverity, $result);
    }

    /**
     * @return array<string, array{0: string, 1: array<string>, 2: Severity}>
     */
    public static function severityProvider(): array
    {
        return [
            'with patterns = critical' => ['api_key', ['Stripe live key'], Severity::Critical],
            'password = high' => ['user_password', [], Severity::High],
            'private key = high' => ['ssl_private_key', [], Severity::High],
            'secret = high' => ['client_secret', [], Severity::High],
            'token = medium' => ['access_token', [], Severity::Medium],
            'apikey = medium' => ['stripe_apikey', [], Severity::Medium],
            'api_key = medium' => ['payment_api_key', [], Severity::Medium],
            'other = low' => ['some_config', [], Severity::Low],
        ];
    }

    #[Test]
    #[DataProvider('configKeyProvider')]
    public function detectsSecretConfigKeys(string $key, bool $isSecret): void
    {
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('isSecretConfigKey');

        $result = $method->invoke($this->service, $key);

        self::assertSame($isSecret, $result);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function configKeyProvider(): array
    {
        // Note: isSecretConfigKey uses regex suffix matching to avoid false positives
        return [
            // Should match (suffix patterns)
            'apiKey' => ['apiKey', true],
            'stripeApiKey' => ['stripeApiKey', true],
            'password' => ['password', true],
            'smtpPassword' => ['smtpPassword', true],
            'clientSecret' => ['clientSecret', true],
            'apiSecret' => ['apiSecret', true],
            'accessToken' => ['accessToken', true],
            'authToken' => ['authToken', true],
            'token' => ['token', true],              // standalone "token" (e.g., hashicorp.token)
            'privateKey' => ['privateKey', true],
            'encryptionKey' => ['encryptionKey', true],
            'userCredential' => ['userCredential', true],
            // Should NOT match (false positives avoided)
            'secretPrefix' => ['secretPrefix', false],  // "secret" at start, not end
            'tokenizer' => ['tokenizer', false],        // "token" not at end
            'passwordReset' => ['passwordReset', false], // "password" not at end
            'API_KEY' => ['API_KEY', false],            // underscore variant
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
        $reflection = new ReflectionClass($this->service);
        $method = $reflection->getMethod('isTableExcluded');

        $result = $method->invoke($this->service, $tableName, $patterns);

        self::assertSame($shouldExclude, $result);
    }

    #[Test]
    public function excludedColumnsContainsPasswordHashColumns(): void
    {
        $reflection = new ReflectionClass(SecretDetectionService::class);
        $constant = $reflection->getConstant('EXCLUDED_COLUMNS');

        self::assertIsArray($constant);
        self::assertContains('be_users.password', $constant, 'be_users.password should be excluded (contains hashes)');
        self::assertContains('fe_users.password', $constant, 'fe_users.password should be excluded (contains hashes)');
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
