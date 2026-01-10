<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Netresearch\NrVault\Command\VaultStoreCommand;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VaultStoreCommand::class)]
#[AllowMockObjectsWithoutExpectations]
final class VaultStoreCommandTest extends TestCase
{
    private VaultServiceInterface&MockObject $vaultService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);

        $command = new VaultStoreCommand($this->vaultService);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function hasCorrectName(): void
    {
        $command = new VaultStoreCommand($this->vaultService);

        self::assertSame('vault:store', $command->getName());
    }

    #[Test]
    public function storesSecretWithValueOption(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with('my-api-key', 'secret-value-123', []);

        $exitCode = $this->commandTester->execute([
            'identifier' => 'my-api-key',
            '--value' => 'secret-value-123',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('stored successfully', $this->commandTester->getDisplay());
    }

    #[Test]
    public function storesSecretFromFile(): void
    {
        $root = vfsStream::setup('test');
        vfsStream::newFile('secret.txt')
            ->withContent('file-secret-content')
            ->at($root);

        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with('file-secret', 'file-secret-content', []);

        $exitCode = $this->commandTester->execute([
            'identifier' => 'file-secret',
            '--file' => vfsStream::url('test/secret.txt'),
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function failsWhenFileNotFound(): void
    {
        $exitCode = $this->commandTester->execute([
            'identifier' => 'test',
            '--file' => '/nonexistent/file.txt',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('File not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWhenNoValueProvided(): void
    {
        $exitCode = $this->commandTester->execute(
            ['identifier' => 'test'],
            ['interactive' => false],
        );

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('No secret value provided', $this->commandTester->getDisplay());
    }

    #[Test]
    public function parsesMetadataOptions(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with(
                'metadata-test',
                'secret',
                $this->callback(fn (array $metadata): bool => $metadata['service'] === 'stripe'
                    && $metadata['env'] === 'production'),
            );

        $exitCode = $this->commandTester->execute([
            'identifier' => 'metadata-test',
            '--value' => 'secret',
            '--metadata' => ['service=stripe', 'env=production'],
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function parsesGroupsOption(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with(
                'groups-test',
                'secret',
                $this->callback(fn (array $metadata): bool => isset($metadata['allowed_groups'])
                    && $metadata['allowed_groups'] === [1, 2, 3]),
            );

        $exitCode = $this->commandTester->execute([
            'identifier' => 'groups-test',
            '--value' => 'secret',
            '--groups' => ['1', '2', '3'],
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function handlesVaultException(): void
    {
        $this->vaultService
            ->method('store')
            ->willThrowException(new VaultException('Storage failed'));

        $exitCode = $this->commandTester->execute([
            'identifier' => 'test',
            '--value' => 'secret',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Storage failed', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesValidationException(): void
    {
        $this->vaultService
            ->method('store')
            ->willThrowException(ValidationException::invalidIdentifier('bad!identifier', 'contains invalid characters'));

        $exitCode = $this->commandTester->execute([
            'identifier' => 'bad!identifier',
            '--value' => 'secret',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('invalid', $this->commandTester->getDisplay());
    }

    #[Test]
    public function trimsNewlineFromFileContent(): void
    {
        $root = vfsStream::setup('test');
        vfsStream::newFile('secret-with-newline.txt')
            ->withContent("secret-content\n")
            ->at($root);

        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with('file-trim-test', "secret-content\n", []);

        $exitCode = $this->commandTester->execute([
            'identifier' => 'file-trim-test',
            '--file' => vfsStream::url('test/secret-with-newline.txt'),
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function ignoresMetadataWithoutEquals(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with(
                'metadata-no-equals',
                'secret',
                $this->callback(fn (array $metadata): bool => $metadata === ['valid' => 'value']),
            );

        $exitCode = $this->commandTester->execute([
            'identifier' => 'metadata-no-equals',
            '--value' => 'secret',
            '--metadata' => ['valid=value', 'invalid-no-equals'],
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function displaysSuccessMessageWithIdentifier(): void
    {
        $exitCode = $this->commandTester->execute([
            'identifier' => 'display-test-id',
            '--value' => 'secret',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('display-test-id', $this->commandTester->getDisplay());
        self::assertStringContainsString('stored successfully', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesEmptyGroupsArray(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with('empty-groups', 'secret', []);

        $exitCode = $this->commandTester->execute([
            'identifier' => 'empty-groups',
            '--value' => 'secret',
            '--groups' => [],
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function parsesSingleGroupCorrectly(): void
    {
        $this->vaultService
            ->expects($this->once())
            ->method('store')
            ->with(
                'single-group',
                'secret',
                $this->callback(fn (array $metadata): bool => isset($metadata['allowed_groups'])
                    && $metadata['allowed_groups'] === [5]),
            );

        $exitCode = $this->commandTester->execute([
            'identifier' => 'single-group',
            '--value' => 'secret',
            '--groups' => ['5'],
        ]);

        self::assertSame(0, $exitCode);
    }
}
