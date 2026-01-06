<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Netresearch\NrVault\Audit\AuditLogEntry;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Command\VaultAuditCommand;
use Netresearch\NrVault\Exception\VaultException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(VaultAuditCommand::class)]
final class VaultAuditCommandTest extends TestCase
{
    private AuditLogServiceInterface&MockObject $auditLogService;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditLogService = $this->createMock(AuditLogServiceInterface::class);

        $command = new VaultAuditCommand($this->auditLogService);

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function hasCorrectName(): void
    {
        $command = new VaultAuditCommand($this->auditLogService);

        self::assertSame('vault:audit', $command->getName());
    }

    #[Test]
    public function showsInfoWhenNoEntriesFound(): void
    {
        $this->auditLogService
            ->method('query')
            ->willReturn([]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No audit entries found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function outputsTableFormat(): void
    {
        $entry = AuditLogEntry::fromDatabaseRow([
            'uid' => 1,
            'crdate' => 1704067200,
            'secret_identifier' => 'test-secret',
            'action' => 'read',
            'success' => 1,
            'actor_uid' => 1,
            'actor_username' => 'admin',
            'actor_type' => 'be_user',
            'ip_address' => '127.0.0.1',
            'entry_hash' => 'abc123def456',
            'previous_hash' => 'prev123',
            'context' => '{}',
        ]);

        $this->auditLogService
            ->method('query')
            ->willReturn([$entry]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('test-secret', $display);
        self::assertStringContainsString('read', $display);
        self::assertStringContainsString('admin', $display);
        self::assertStringContainsString('Total: 1 entries', $display);
    }

    #[Test]
    public function outputsJsonFormat(): void
    {
        $entry = AuditLogEntry::fromDatabaseRow([
            'uid' => 1,
            'crdate' => 1704067200,
            'secret_identifier' => 'json-test',
            'action' => 'create',
            'success' => 1,
            'actor_uid' => 2,
            'actor_username' => 'editor',
            'actor_type' => 'be_user',
            'ip_address' => '192.168.1.1',
            'entry_hash' => 'hash123',
            'previous_hash' => '',
            'context' => '{}',
        ]);

        $this->auditLogService
            ->method('query')
            ->willReturn([$entry]);

        $exitCode = $this->commandTester->execute([
            '--format' => 'json',
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertJson($display);
        self::assertStringContainsString('"secretIdentifier": "json-test"', $display);
    }

    #[Test]
    public function outputsCsvFormat(): void
    {
        $entry = AuditLogEntry::fromDatabaseRow([
            'uid' => 1,
            'crdate' => 1704067200,
            'secret_identifier' => 'csv-test',
            'action' => 'delete',
            'success' => 0,
            'actor_uid' => 1,
            'actor_username' => 'admin',
            'actor_type' => 'be_user',
            'ip_address' => '10.0.0.1',
            'entry_hash' => 'csvhash',
            'previous_hash' => '',
            'context' => '{}',
        ]);

        $this->auditLogService
            ->method('query')
            ->willReturn([$entry]);

        $exitCode = $this->commandTester->execute([
            '--format' => 'csv',
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('timestamp,secret_identifier,action', $display);
        self::assertStringContainsString('csv-test,delete,0', $display);
    }

    #[Test]
    public function filtersbyIdentifier(): void
    {
        $this->auditLogService
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(fn ($filter) => $filter !== null && $filter->secretIdentifier === 'filtered-secret'),
                50,
                0,
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--identifier' => 'filtered-secret',
        ]);
    }

    #[Test]
    public function filtersByAction(): void
    {
        $this->auditLogService
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(fn ($filter) => $filter !== null && $filter->action === 'rotate'),
                50,
                0,
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--action' => 'rotate',
        ]);
    }

    #[Test]
    public function filtersByActor(): void
    {
        $this->auditLogService
            ->expects($this->once())
            ->method('query')
            ->with(
                $this->callback(fn ($filter) => $filter !== null && $filter->actorUid === 42),
                50,
                0,
            )
            ->willReturn([]);

        $this->commandTester->execute([
            '--actor' => '42',
        ]);
    }

    #[Test]
    public function verifyHashChainSuccess(): void
    {
        $this->auditLogService
            ->method('verifyHashChain')
            ->willReturn(['valid' => true, 'errors' => []]);

        $exitCode = $this->commandTester->execute([
            '--verify' => true,
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Hash chain is valid', $this->commandTester->getDisplay());
    }

    #[Test]
    public function verifyHashChainFailure(): void
    {
        $this->auditLogService
            ->method('verifyHashChain')
            ->willReturn([
                'valid' => false,
                'errors' => [123 => 'Hash mismatch'],
            ]);

        $exitCode = $this->commandTester->execute([
            '--verify' => true,
        ]);

        self::assertSame(1, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('FAILED', $display);
        self::assertStringContainsString('123', $display);
        self::assertStringContainsString('Hash mismatch', $display);
    }

    #[Test]
    public function exportsToFile(): void
    {
        $root = vfsStream::setup('exports');

        $entry = AuditLogEntry::fromDatabaseRow([
            'uid' => 1,
            'crdate' => 1704067200,
            'secret_identifier' => 'exported',
            'action' => 'read',
            'success' => 1,
            'actor_uid' => 1,
            'actor_username' => 'admin',
            'actor_type' => 'be_user',
            'ip_address' => '127.0.0.1',
            'entry_hash' => 'exporthash',
            'previous_hash' => '',
            'context' => '{}',
        ]);

        $this->auditLogService
            ->method('query')
            ->willReturn([$entry]);

        $exportFile = vfsStream::url('exports/audit.json');

        $exitCode = $this->commandTester->execute([
            '--export' => $exportFile,
        ]);

        self::assertSame(0, $exitCode);
        self::assertFileExists($exportFile);
        $content = file_get_contents($exportFile);
        self::assertJson($content);
        self::assertStringContainsString('exported', $content);
    }

    #[Test]
    public function appliesLimit(): void
    {
        $this->auditLogService
            ->expects($this->once())
            ->method('query')
            ->with(null, 25, 0)
            ->willReturn([]);

        $this->commandTester->execute([
            '--limit' => '25',
        ]);
    }

    #[Test]
    public function handlesVaultException(): void
    {
        $this->auditLogService
            ->method('query')
            ->willThrowException(new VaultException('Audit query failed'));

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Audit query failed', $this->commandTester->getDisplay());
    }
}
