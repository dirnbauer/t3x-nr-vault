<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Controller;

use Netresearch\NrVault\Controller\AjaxController;
use Netresearch\NrVault\Domain\Dto\SecretDetails;
use Netresearch\NrVault\Exception\AccessDeniedException;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\SecretExpiredException;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

#[CoversClass(AjaxController::class)]
final class AjaxControllerTest extends TestCase
{
    private AjaxController $subject;

    private VaultServiceInterface&MockObject $vaultService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);
        $this->subject = new AjaxController($this->vaultService);
    }

    #[Test]
    public function revealActionReturns400WhenNoIdentifier(): void
    {
        $request = $this->createRequestWithQueryParams([]);

        $response = $this->subject->revealAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('No identifier provided', $body['error']);
    }

    #[Test]
    public function revealActionReturnsSecretOnSuccess(): void
    {
        $identifier = 'test-secret-id';
        $secretValue = 'my-secret-value';

        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->expects(self::once())
            ->method('retrieve')
            ->with($identifier)
            ->willReturn($secretValue);

        $response = $this->subject->revealAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame($secretValue, $body['secret']);
    }

    #[Test]
    public function revealActionReturns404WhenSecretNotFound(): void
    {
        $identifier = 'nonexistent-id';
        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(new SecretNotFoundException($identifier, 1234567890));

        $response = $this->subject->revealAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Secret not found', $body['error']);
    }

    #[Test]
    public function revealActionReturns403WhenAccessDenied(): void
    {
        $identifier = 'restricted-id';
        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(new AccessDeniedException('Access denied', 1234567890));

        $response = $this->subject->revealAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Access denied', $body['error']);
    }

    #[Test]
    public function revealActionReturns410WhenSecretExpired(): void
    {
        $identifier = 'expired-id';
        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(SecretExpiredException::forIdentifier($identifier, 1234567800));

        $response = $this->subject->revealAction($request);

        self::assertSame(410, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Secret has expired', $body['error']);
    }

    #[Test]
    public function revealActionReturns500WhenEncryptionFails(): void
    {
        $identifier = 'decrypt-error-id';
        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(new EncryptionException('Decryption failed', 1234567890));

        $response = $this->subject->revealAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Decryption failed', $body['error']);
    }

    #[Test]
    public function revealActionReturns500OnGenericException(): void
    {
        $identifier = 'error-id';
        $request = $this->createRequestWithQueryParams(['identifier' => $identifier]);

        $this->vaultService
            ->method('retrieve')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $response = $this->subject->revealAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Failed to retrieve secret', $body['error']);
    }

    #[Test]
    public function revealActionSupportsJsonBody(): void
    {
        $identifier = 'json-body-id';
        $secretValue = 'secret-from-json';

        $request = $this->createRequestWithJsonBody(['identifier' => $identifier]);

        $this->vaultService
            ->expects(self::once())
            ->method('retrieve')
            ->with($identifier)
            ->willReturn($secretValue);

        $response = $this->subject->revealAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame($secretValue, $body['secret']);
    }

    #[Test]
    public function rotateActionReturns405ForNonPostRequests(): void
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('GET');

        $response = $this->subject->rotateAction($request);

        self::assertSame(405, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Method not allowed', $body['error']);
    }

    #[Test]
    public function rotateActionReturns400WhenNoIdentifier(): void
    {
        $request = $this->createPostRequestWithBody(['secret' => 'new-secret']);

        $response = $this->subject->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('No identifier provided', $body['error']);
    }

    #[Test]
    public function rotateActionReturns400WhenNoSecret(): void
    {
        $request = $this->createPostRequestWithBody(['identifier' => 'test-id']);

        $response = $this->subject->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('No secret value provided', $body['error']);
    }

    #[Test]
    public function rotateActionSuccessfullyRotatesSecret(): void
    {
        $identifier = 'rotate-id';
        $newSecret = 'new-secret-value';

        $request = $this->createPostRequestWithBody([
            'identifier' => $identifier,
            'secret' => $newSecret,
        ]);

        $this->vaultService
            ->expects(self::once())
            ->method('rotate')
            ->with($identifier, $newSecret);

        $this->vaultService
            ->method('getMetadata')
            ->with($identifier)
            ->willReturn($this->createSecretDetails($identifier, version: 2));

        $response = $this->subject->rotateAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame('Secret rotated successfully', $body['message']);
        self::assertSame(2, $body['version']);
    }

    #[Test]
    public function rotateActionReturns404WhenSecretNotFound(): void
    {
        $identifier = 'nonexistent-id';
        $request = $this->createPostRequestWithBody([
            'identifier' => $identifier,
            'secret' => 'new-value',
        ]);

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new SecretNotFoundException($identifier, 1234567890));

        $response = $this->subject->rotateAction($request);

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Secret not found', $body['error']);
    }

    #[Test]
    public function rotateActionReturns403WhenAccessDenied(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'restricted-id',
            'secret' => 'new-value',
        ]);

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new AccessDeniedException('Access denied', 1234567890));

        $response = $this->subject->rotateAction($request);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertSame('Access denied', $body['error']);
    }

    #[Test]
    public function rotateActionReturns400OnValidationError(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'test-id',
            'secret' => 'invalid-value',
        ]);

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new ValidationException('Invalid secret format', 1234567890));

        $response = $this->subject->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Validation error', $body['error']);
    }

    #[Test]
    public function rotateActionReturns500OnEncryptionError(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'test-id',
            'secret' => 'new-value',
        ]);

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new EncryptionException('Encryption failed', 1234567890));

        $response = $this->subject->rotateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Encryption failed', $body['error']);
    }

    #[Test]
    public function rotateActionReturns500OnGenericException(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'test-id',
            'secret' => 'new-value',
        ]);

        $this->vaultService
            ->method('rotate')
            ->willThrowException(new RuntimeException('Unexpected error'));

        $response = $this->subject->rotateAction($request);

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertFalse($body['success']);
        self::assertStringContainsString('Failed to rotate secret', $body['error']);
    }

    #[Test]
    public function rotateActionHandlesMetadataWithNoVersion(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'test-id',
            'secret' => 'new-value',
        ]);

        $this->vaultService
            ->method('rotate');

        $this->vaultService
            ->method('getMetadata')
            ->willReturn($this->createSecretDetails('test-id', version: 1));

        $response = $this->subject->rotateAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertTrue($body['success']);
        self::assertSame(1, $body['version']);
    }

    #[Test]
    public function revealActionQueryParamTakesPrecedenceOverJsonBody(): void
    {
        $queryIdentifier = 'query-param-id';
        $jsonIdentifier = 'json-body-id';
        $secretValue = 'secret-value';

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn(['identifier' => $queryIdentifier]);
        $request->method('getParsedBody')->willReturn(['identifier' => $jsonIdentifier]);

        $this->vaultService
            ->expects(self::once())
            ->method('retrieve')
            ->with($queryIdentifier)
            ->willReturn($secretValue);

        $response = $this->subject->revealAction($request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function revealActionHandlesEmptyStringIdentifier(): void
    {
        $request = $this->createRequestWithQueryParams(['identifier' => '']);

        $response = $this->subject->revealAction($request);

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function rotateActionHandlesEmptyStringIdentifier(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => '',
            'secret' => 'some-value',
        ]);

        $response = $this->subject->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('No identifier provided', $body['error']);
    }

    #[Test]
    public function rotateActionHandlesEmptyStringSecret(): void
    {
        $request = $this->createPostRequestWithBody([
            'identifier' => 'valid-id',
            'secret' => '',
        ]);

        $response = $this->subject->rotateAction($request);

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        self::assertSame('No secret value provided', $body['error']);
    }

    /**
     * @param array<string, mixed> $queryParams
     */
    private function createRequestWithQueryParams(array $queryParams): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($queryParams);
        $request->method('getParsedBody')->willReturn(null);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn('');
        $request->method('getBody')->willReturn($stream);

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRequestWithJsonBody(array $body): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createPostRequestWithBody(array $body): ServerRequestInterface&MockObject
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    private function createSecretDetails(string $identifier, int $version = 1): SecretDetails
    {
        return new SecretDetails(
            uid: 1,
            identifier: $identifier,
            description: 'Test secret',
            ownerUid: 1,
            groups: [],
            context: 'default',
            frontendAccessible: false,
            version: $version,
            createdAt: 1704067200,
            updatedAt: 1704067200,
            expiresAt: null,
            lastRotatedAt: null,
            readCount: 0,
            lastReadAt: null,
            metadata: [],
            scopePid: 0,
        );
    }
}
