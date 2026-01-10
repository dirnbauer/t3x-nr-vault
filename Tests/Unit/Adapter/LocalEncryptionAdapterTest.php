<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Adapter;

use Netresearch\NrVault\Adapter\LocalEncryptionAdapter;
use Netresearch\NrVault\Adapter\VaultAdapterInterface;
use Netresearch\NrVault\Domain\Dto\SecretFilters;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Domain\Repository\SecretRepositoryInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LocalEncryptionAdapter::class)]
#[AllowMockObjectsWithoutExpectations]
final class LocalEncryptionAdapterTest extends TestCase
{
    #[Test]
    public function implementsVaultAdapterInterface(): void
    {
        $repository = $this->createStub(SecretRepositoryInterface::class);
        $adapter = new LocalEncryptionAdapter($repository);

        self::assertInstanceOf(VaultAdapterInterface::class, $adapter);
    }

    #[Test]
    public function getIdentifierReturnsLocal(): void
    {
        $repository = $this->createStub(SecretRepositoryInterface::class);
        $adapter = new LocalEncryptionAdapter($repository);

        self::assertEquals('local', $adapter->getIdentifier());
    }

    #[Test]
    public function isAvailableAlwaysReturnsTrue(): void
    {
        $repository = $this->createStub(SecretRepositoryInterface::class);
        $adapter = new LocalEncryptionAdapter($repository);

        self::assertTrue($adapter->isAvailable());
    }

    #[Test]
    public function storeDelegatesToRepository(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test');

        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('save')
            ->with($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $adapter->store($secret);
    }

    #[Test]
    public function retrieveDelegatesToRepository(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test');

        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findByIdentifier')
            ->with('test')
            ->willReturn($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $result = $adapter->retrieve('test');

        self::assertSame($secret, $result);
    }

    #[Test]
    public function retrieveReturnsNullWhenNotFound(): void
    {
        $repository = $this->createStub(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn(null);

        $adapter = new LocalEncryptionAdapter($repository);

        self::assertNull($adapter->retrieve('nonexistent'));
    }

    #[Test]
    public function deleteRemovesExistingSecret(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test');

        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')
            ->with('test')
            ->willReturn($secret);
        $repository->expects(self::once())
            ->method('delete')
            ->with($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $adapter->delete('test');
    }

    #[Test]
    public function deleteDoesNothingWhenSecretNotFound(): void
    {
        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn(null);
        $repository->expects(self::never())->method('delete');

        $adapter = new LocalEncryptionAdapter($repository);
        $adapter->delete('nonexistent');
    }

    #[Test]
    public function existsDelegatesToRepository(): void
    {
        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('exists')
            ->with('test')
            ->willReturn(true);

        $adapter = new LocalEncryptionAdapter($repository);

        self::assertTrue($adapter->exists('test'));
    }

    #[Test]
    public function listDelegatesToRepository(): void
    {
        $identifiers = ['secret-1', 'secret-2', 'secret-3'];
        $filters = new SecretFilters(context: 'payment');

        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findIdentifiers')
            ->with($filters)
            ->willReturn($identifiers);

        $adapter = new LocalEncryptionAdapter($repository);

        self::assertEquals($identifiers, $adapter->list($filters));
    }

    #[Test]
    public function getMetadataReturnsNullWhenSecretNotFound(): void
    {
        $repository = $this->createStub(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn(null);

        $adapter = new LocalEncryptionAdapter($repository);

        self::assertNull($adapter->getMetadata('nonexistent'));
    }

    #[Test]
    public function getMetadataReturnsSecretMetadata(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('api-key');
        $secret->setDescription('Payment API key');
        $secret->setOwnerUid(5);
        $secret->setAllowedGroups([1, 2]);
        $secret->setContext('payment');
        $secret->setVersion(3);
        $secret->setExpiresAt(1735689600);
        $secret->setMetadata(['service' => 'stripe']);
        $secret->setAdapter('local');

        $repository = $this->createStub(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $metadata = $adapter->getMetadata('api-key');

        self::assertNotNull($metadata);
        self::assertEquals('api-key', $metadata['identifier']);
        self::assertEquals('Payment API key', $metadata['description']);
        self::assertEquals(5, $metadata['owner']);
        self::assertEquals([1, 2], $metadata['groups']);
        self::assertEquals('payment', $metadata['context']);
        self::assertEquals(3, $metadata['version']);
        self::assertEquals(1735689600, $metadata['expiresAt']);
        self::assertEquals(['service' => 'stripe'], $metadata['metadata']);
        self::assertEquals('local', $metadata['adapter']);
    }

    #[Test]
    public function getMetadataReturnsNullExpiresAtWhenZero(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test');
        $secret->setExpiresAt(0);

        $repository = $this->createStub(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $metadata = $adapter->getMetadata('test');

        self::assertNull($metadata['expiresAt']);
    }

    #[Test]
    public function updateMetadataDoesNothingWhenSecretNotFound(): void
    {
        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn(null);
        $repository->expects(self::never())->method('save');

        $adapter = new LocalEncryptionAdapter($repository);
        $adapter->updateMetadata('nonexistent', ['key' => 'value']);
    }

    #[Test]
    public function updateMetadataMergesAndSaves(): void
    {
        $secret = new Secret();
        $secret->setIdentifier('test');
        $secret->setMetadata(['existing' => 'value']);

        $repository = $this->createMock(SecretRepositoryInterface::class);
        $repository->method('findByIdentifier')->willReturn($secret);
        $repository->expects(self::once())->method('save')->with($secret);

        $adapter = new LocalEncryptionAdapter($repository);
        $adapter->updateMetadata('test', ['new' => 'data']);

        self::assertEquals(['existing' => 'value', 'new' => 'data'], $secret->getMetadata());
    }
}
