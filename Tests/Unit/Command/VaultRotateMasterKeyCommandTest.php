<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Command;

use Netresearch\NrVault\Command\VaultRotateMasterKeyCommand;
use Netresearch\NrVault\Crypto\EncryptionServiceInterface;
use Netresearch\NrVault\Crypto\MasterKeyProviderFactory;
use Netresearch\NrVault\Crypto\MasterKeyProviderInterface;
use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Domain\Repository\SecretRepository;
use Netresearch\NrVault\Exception\EncryptionException;
use Netresearch\NrVault\Exception\MasterKeyException;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[CoversClass(VaultRotateMasterKeyCommand::class)]
final class VaultRotateMasterKeyCommandTest extends TestCase
{
    private SecretRepository&MockObject $secretRepository;

    private EncryptionServiceInterface&MockObject $encryptionService;

    private MasterKeyProviderFactory&MockObject $masterKeyProviderFactory;

    private ConnectionPool&MockObject $connectionPool;

    private CommandTester $commandTester;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secretRepository = $this->createMock(SecretRepository::class);
        $this->encryptionService = $this->createMock(EncryptionServiceInterface::class);
        $this->masterKeyProviderFactory = $this->createMock(MasterKeyProviderFactory::class);
        $this->connectionPool = $this->createMock(ConnectionPool::class);

        $command = new VaultRotateMasterKeyCommand(
            $this->secretRepository,
            $this->encryptionService,
            $this->masterKeyProviderFactory,
            $this->connectionPool,
        );

        $application = new Application();
        $application->add($command);

        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function hasCorrectName(): void
    {
        $command = new VaultRotateMasterKeyCommand(
            $this->secretRepository,
            $this->encryptionService,
            $this->masterKeyProviderFactory,
            $this->connectionPool,
        );

        self::assertSame('vault:rotate-master-key', $command->getName());
    }

    #[Test]
    public function warnsWhenNoSecretsFound(): void
    {
        $this->mockMasterKeyProvider(\str_repeat('a', 32));

        $this->secretRepository
            ->method('findIdentifiers')
            ->willReturn([]);

        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', \str_repeat('a', 32)),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
        ]);

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('No secrets found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWhenKeysAreIdentical(): void
    {
        $keyContent = \str_repeat('x', 32);

        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', $keyContent),
            '--new-key' => $this->createKeyFile('new', $keyContent),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('identical', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWithoutConfirmOption(): void
    {
        $this->mockMasterKeyProvider(\str_repeat('a', 32));

        $this->secretRepository
            ->method('findIdentifiers')
            ->willReturn(['secret-1']);

        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', \str_repeat('a', 32)),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('--confirm', $this->commandTester->getDisplay());
    }

    #[Test]
    public function dryRunShowsNoChanges(): void
    {
        $secret = $this->createMockSecret('test-secret');

        $this->secretRepository
            ->method('findIdentifiers')
            ->willReturn(['test-secret']);

        $this->secretRepository
            ->method('findByIdentifier')
            ->willReturn($secret);

        $this->encryptionService
            ->method('reEncryptDek')
            ->willReturn([
                'encrypted_dek' => 'new-dek',
                'nonce' => 'new-nonce',
            ]);

        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', \str_repeat('a', 32)),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('DRY RUN', $display);
        self::assertStringContainsString('Would re-encrypt', $display);
    }

    #[Test]
    public function failsWhenOldKeyFileNotFound(): void
    {
        $exitCode = $this->commandTester->execute([
            '--old-key' => '/nonexistent/key.file',
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('not found', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWhenKeyHasInvalidLength(): void
    {
        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', 'short'),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Invalid master key', $this->commandTester->getDisplay());
    }

    #[Test]
    public function failsWhenDecryptionFails(): void
    {
        $secret = $this->createMockSecret('failing-secret');

        $this->secretRepository
            ->method('findIdentifiers')
            ->willReturn(['failing-secret']);

        $this->secretRepository
            ->method('findByIdentifier')
            ->willReturn($secret);

        $this->encryptionService
            ->method('reEncryptDek')
            ->willThrowException(EncryptionException::decryptionFailed());

        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', \str_repeat('a', 32)),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
            '--confirm' => true,
        ]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Failed to decrypt', $this->commandTester->getDisplay());
    }

    #[Test]
    public function handlesBase64EncodedKeys(): void
    {
        $rawKey = \str_repeat('k', 32);
        $base64Key = \base64_encode($rawKey);

        $secret = $this->createMockSecret('b64-secret');

        $this->secretRepository
            ->method('findIdentifiers')
            ->willReturn(['b64-secret']);

        $this->secretRepository
            ->method('findByIdentifier')
            ->willReturn($secret);

        $this->encryptionService
            ->method('reEncryptDek')
            ->willReturn([
                'encrypted_dek' => 'new-dek',
                'nonce' => 'new-nonce',
            ]);

        // Base64 keys should work
        $exitCode = $this->commandTester->execute([
            '--old-key' => $this->createKeyFile('old', $base64Key),
            '--new-key' => $this->createKeyFile('new', \str_repeat('b', 32)),
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode);
    }

    private function createKeyFile(string $name, string $content): string
    {
        static $root = null;
        if ($root === null) {
            $root = vfsStream::setup('keys');
        }

        vfsStream::newFile($name . '.key')
            ->withContent($content)
            ->at($root);

        return vfsStream::url('keys/' . $name . '.key');
    }

    private function createMockSecret(string $identifier): Secret&MockObject
    {
        $secret = $this->createMock(Secret::class);
        $secret->method('getIdentifier')->willReturn($identifier);
        $secret->method('getEncryptedDek')->willReturn('encrypted-dek');
        $secret->method('getDekNonce')->willReturn('dek-nonce');

        return $secret;
    }

    private function mockMasterKeyProvider(string $key): void
    {
        $provider = $this->createMock(MasterKeyProviderInterface::class);
        $provider->method('getMasterKey')->willReturn($key);

        $this->masterKeyProviderFactory
            ->method('getAvailableProvider')
            ->willReturn($provider);
    }
}
