<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Netresearch\NrVault\Command\VaultDeleteCommand;
use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VaultDeleteCommand::class)]
final class VaultDeleteCommandTest extends TestCase
{
    private VaultServiceInterface&MockObject $vaultService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultService = $this->createMock(VaultServiceInterface::class);

        $command = new VaultDeleteCommand($this->vaultService);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function hasCorrectName(): void
    {
        $command = new VaultDeleteCommand($this->vaultService);

        self::assertSame('vault:delete', $command->getName());
    }

    #[Test]
    public function failsWhenSecretNotFound(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->with('nonexistent')
            ->willReturn(null);

        $exitCode = $this->commandTester->execute([
            'identifier' => 'nonexistent',
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Secret not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function deletesSecretWhenConfirmed(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->with('to-delete')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 5,
                'last_read_at' => 1704150000,
            ]);

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('to-delete', 'Manual deletion via CLI');

        $this->commandTester->setInputs(['yes']);
        $exitCode = $this->commandTester->execute([
            'identifier' => 'to-delete',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('deleted successfully', $this->commandTester->getDisplay());
    }

    #[Test]
    public function deletesSecretWithForceOption(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 0,
                'last_read_at' => null,
            ]);

        $this->vaultService
            ->expects($this->once())
            ->method('delete')
            ->with('force-delete', 'Forced deletion');

        $exitCode = $this->commandTester->execute([
            'identifier' => 'force-delete',
            '--force' => true,
            '--reason' => 'Forced deletion',
        ]);

        self::assertSame(0, $exitCode);
    }

    #[Test]
    public function cancelsWhenNotConfirmed(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 0,
                'last_read_at' => null,
            ]);

        $this->vaultService
            ->expects($this->never())
            ->method('delete');

        $this->commandTester->setInputs(['no']);
        $exitCode = $this->commandTester->execute([
            'identifier' => 'cancelled',
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('cancelled', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesSecretNotFoundException(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 0,
                'last_read_at' => null,
            ]);

        $this->vaultService
            ->method('delete')
            ->willThrowException(SecretNotFoundException::forIdentifier('gone'));

        $exitCode = $this->commandTester->execute([
            'identifier' => 'gone',
            '--force' => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesVaultException(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 0,
                'last_read_at' => null,
            ]);

        $this->vaultService
            ->method('delete')
            ->willThrowException(new VaultException('Delete failed'));

        $exitCode = $this->commandTester->execute([
            'identifier' => 'error',
            '--force' => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Delete failed', $this->commandTester->getDisplay());
    }

    #[Test]
    public function displaysSecretMetadataBeforeDeletion(): void
    {
        $this->vaultService
            ->method('getMetadata')
            ->willReturn([
                'crdate' => 1704067200,
                'read_count' => 10,
                'last_read_at' => 1704150000,
            ]);

        $this->commandTester->setInputs(['no']);
        $this->commandTester->execute([
            'identifier' => 'show-metadata',
        ]);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Secret to be deleted', $display);
        self::assertStringContainsString('show-metadata', $display);
        self::assertStringContainsString('10', $display);
    }
}
