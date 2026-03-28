<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Functional\Controller;

use Netresearch\NrVault\Controller\AjaxController;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Functional tests for AjaxController.
 *
 * Tests the AJAX endpoints for revealing and rotating secrets.
 */
#[CoversClass(AjaxController::class)]
final class AjaxControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
    ];

    private ?string $masterKeyPath = null;

    protected function setUp(): void
    {
        parent::setUp();

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

        // Import fixture with admin (uid=1) and editor (uid=2)
        $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
    }

    protected function tearDown(): void
    {
        if ($this->masterKeyPath !== null && file_exists($this->masterKeyPath)) {
            $content = file_get_contents($this->masterKeyPath);
            if ($content !== false) {
                sodium_memzero($content);
            }
            unlink($this->masterKeyPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function revealActionWithValidIdentifierReturnsDecryptedSecret(): void
    {
        $this->setUpBackendUser(1);

        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();
        $secretValue = 'my-super-secret-value';
        $vaultService->store($identifier, $secretValue);

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest(['identifier' => $identifier]);

        $response = $controller->revealAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame($secretValue, $body['secret']);

        // Cleanup
        $vaultService->delete($identifier, 'Test cleanup');
    }

    #[Test]
    public function revealActionWithoutIdentifierReturns400(): void
    {
        $this->setUpBackendUser(1);

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest([]);

        $response = $controller->revealAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertSame('No identifier provided', $body['error']);
    }

    #[Test]
    public function revealActionAsNonAdminReturns403(): void
    {
        // Store secret as admin first
        $this->setUpBackendUser(1);
        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();
        $vaultService->store($identifier, 'admin-secret');

        // Switch to non-admin user
        $this->setUpBackendUser(2);

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest(['identifier' => $identifier]);

        $response = $controller->revealAction($request);

        // Non-admin gets either 403 (AccessDeniedException) or 404 (not visible)
        self::assertContains($response->getStatusCode(), [403, 404]);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);

        // Cleanup as admin
        $this->setUpBackendUser(1);
        $vaultService->delete($identifier, 'Test cleanup');
    }

    #[Test]
    public function rotateActionWithValidDataReturnsSuccess(): void
    {
        $this->setUpBackendUser(1);

        $vaultService = $this->get(VaultServiceInterface::class);
        $identifier = $this->generateUuidV7();
        $vaultService->store($identifier, 'original-secret');

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest([
            'identifier' => $identifier,
            'secret' => 'rotated-secret',
        ]);

        $response = $controller->rotateAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($body['success']);
        self::assertSame('Secret rotated successfully', $body['message']);
        self::assertSame(2, $body['version']);

        // Verify the secret was actually rotated
        $retrieved = $vaultService->retrieve($identifier);
        self::assertSame('rotated-secret', $retrieved);

        // Cleanup
        $vaultService->delete($identifier, 'Test cleanup');
    }

    #[Test]
    public function rotateActionWithoutIdentifierReturns400(): void
    {
        $this->setUpBackendUser(1);

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest([
            'secret' => 'some-value',
        ]);

        $response = $controller->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertSame('No identifier provided', $body['error']);
    }

    #[Test]
    public function rotateActionWithoutSecretReturns400(): void
    {
        $this->setUpBackendUser(1);

        $controller = $this->get(AjaxController::class);
        $request = $this->createJsonPostRequest([
            'identifier' => $this->generateUuidV7(),
        ]);

        $response = $controller->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertSame('No secret value provided', $body['error']);
    }

    #[Test]
    public function rotateActionWithNonPostMethodReturns405(): void
    {
        $this->setUpBackendUser(1);

        $controller = $this->get(AjaxController::class);

        /** @phpstan-ignore new.internalClass */
        $request = new ServerRequest('https://example.com/ajax/vault/rotate', 'GET');

        $response = $controller->rotateAction($request);

        self::assertSame(405, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['success']);
        self::assertSame('Method not allowed', $body['error']);
    }

    /**
     * Create a PSR-7 POST request with JSON body.
     *
     * @param array<string, mixed> $data
     */
    private function createJsonPostRequest(array $data): ServerRequest
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        /** @phpstan-ignore new.internalClass */
        $stream = new Stream('php://temp', 'r+');
        $stream->write($json);
        $stream->rewind();

        /** @phpstan-ignore new.internalClass */
        return (new ServerRequest('https://example.com/ajax/vault/reveal', 'POST'))
            ->withHeader('Content-Type', 'application/json')
            ->withBody($stream);
    }

    /**
     * Generate a UUID v7 for testing.
     */
    private function generateUuidV7(): string
    {
        $timestamp = (int) (microtime(true) * 1000);
        $timestampHex = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);
        $randomBytes = random_bytes(10);
        $randomHex = bin2hex($randomBytes);

        return \sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($timestampHex, 0, 8),
            substr($timestampHex, 8, 4),
            substr($randomHex, 0, 3),
            dechex(8 + random_int(0, 3)),
            substr($randomHex, 3, 3),
            substr($randomHex, 6, 12),
        );
    }
}
