<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Http;

use Netresearch\NrVault\Http\SecureHttpClientFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;

#[CoversClass(SecureHttpClientFactory::class)]
final class SecureHttpClientFactoryTest extends TestCase
{
    private SecureHttpClientFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new SecureHttpClientFactory();
        $GLOBALS['TYPO3_CONF_VARS'] = [];
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($GLOBALS['TYPO3_CONF_VARS']);
    }

    #[Test]
    public function createReturnsClientInterface(): void
    {
        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function createWithTypo3HttpConfig(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'timeout' => 60,
            'connect_timeout' => 5,
            'version' => '2.0',
        ];

        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function createWithProxyConfig(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'proxy' => 'http://proxy.example.com:8080',
        ];

        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function createWithSslConfig(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'verify' => false,
            'cert' => '/path/to/cert.pem',
            'ssl_key' => '/path/to/key.pem',
        ];

        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function createWithRedirectConfig(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allow_redirects' => ['max' => 5],
        ];

        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }

    #[Test]
    public function isHostAllowedReturnsTrueWhenNoRestrictions(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [];

        self::assertTrue($this->factory->isHostAllowed('any.example.com'));
    }

    #[Test]
    public function isHostAllowedReturnsTrueForExactMatch(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allowed_hosts' => ['api.example.com', 'other.example.com'],
        ];

        self::assertTrue($this->factory->isHostAllowed('api.example.com'));
    }

    #[Test]
    public function isHostAllowedReturnsFalseWhenNotInList(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allowed_hosts' => ['api.example.com'],
        ];

        self::assertFalse($this->factory->isHostAllowed('other.example.com'));
    }

    #[Test]
    public function isHostAllowedSupportsWildcardPattern(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allowed_hosts' => ['*.example.com'],
        ];

        self::assertTrue($this->factory->isHostAllowed('api.example.com'));
        self::assertTrue($this->factory->isHostAllowed('sub.domain.example.com'));
        self::assertFalse($this->factory->isHostAllowed('api.other.com'));
    }

    #[Test]
    public function isHostAllowedIgnoresNonStringPatterns(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allowed_hosts' => ['api.example.com', 123, null],
        ];

        self::assertTrue($this->factory->isHostAllowed('api.example.com'));
        self::assertFalse($this->factory->isHostAllowed('other.example.com'));
    }

    #[Test]
    public function isHostAllowedReturnsEmptyArrayAsNoRestriction(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['HTTP'] = [
            'allowed_hosts' => [],
        ];

        self::assertTrue($this->factory->isHostAllowed('any.example.com'));
    }

    #[Test]
    public function createWithEmptyConfig(): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = [];

        $client = $this->factory->create();

        self::assertInstanceOf(ClientInterface::class, $client);
    }
}
