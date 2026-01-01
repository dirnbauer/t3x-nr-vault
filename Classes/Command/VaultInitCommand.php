<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use TYPO3\CMS\Core\Core\Environment;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to initialize the vault (generate master key).
 */
#[AsCommand(
    name: 'vault:init',
    description: 'Initialize the vault by generating a master key',
)]
final class VaultInitCommand extends Command
{
    public function __construct(
        private readonly ExtensionConfigurationInterface $configuration,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output file for the master key (defaults to configured path or var/vault/master.key)',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Overwrite existing master key (DANGEROUS: will make existing secrets unrecoverable)',
            )
            ->addOption(
                'env',
                'e',
                InputOption::VALUE_NONE,
                'Output key as environment variable format instead of file',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Check if using typo3 provider (no init needed)
        $provider = $this->configuration->getMasterKeyProvider();
        if ($provider === 'typo3' && !$input->getOption('output') && !$input->getOption('env')) {
            $io->success('No initialization needed. Using TYPO3 encryption key as master key provider.');
            $io->note('To use a different provider, configure masterKeyProvider in extension settings.');

            return Command::SUCCESS;
        }

        // Determine output location
        $outputFile = $input->getOption('output');
        $outputEnv = $input->getOption('env');

        if ($outputFile === null && !$outputEnv) {
            // Use configured source (file path) or default
            $source = $this->configuration->getMasterKeySource();
            // Only use source if it looks like a path (contains / or \)
            $outputFile = (str_contains($source, '/') || str_contains($source, '\\')) ? $source : '';
            if ($outputFile === '') {
                $outputFile = Environment::getVarPath() . '/vault/master.key';
            }
        }

        // Check if key already exists
        if ($outputFile !== null && file_exists($outputFile) && !$input->getOption('force')) {
            $io->error(\sprintf(
                'Master key already exists at: %s' . PHP_EOL .
                'Use --force to overwrite (WARNING: existing secrets will become unrecoverable)',
                $outputFile,
            ));

            return Command::FAILURE;
        }

        // Generate master key using sodium
        $masterKey = sodium_crypto_secretbox_keygen();

        if ($outputEnv) {
            // Output as environment variable format
            $encoded = sodium_bin2base64($masterKey, SODIUM_BASE64_VARIANT_ORIGINAL);
            $io->writeln('Add the following to your environment:');
            $io->newLine();
            $io->writeln(\sprintf('export TYPO3_VAULT_MASTER_KEY="%s"', $encoded));
            $io->newLine();
            $io->warning('Store this key securely! It cannot be recovered if lost.');
        } else {
            // Ensure directory exists
            $dir = \dirname($outputFile);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0o700, true)) {
                    $io->error(\sprintf('Failed to create directory: %s', $dir));
                    sodium_memzero($masterKey);

                    return Command::FAILURE;
                }
            }

            // Write key to file with restrictive permissions
            $result = file_put_contents($outputFile, $masterKey);
            if ($result === false) {
                $io->error(\sprintf('Failed to write master key to: %s', $outputFile));
                sodium_memzero($masterKey);

                return Command::FAILURE;
            }

            // Set restrictive permissions (owner read/write only)
            chmod($outputFile, 0o600);

            $io->success(\sprintf('Master key generated and saved to: %s', $outputFile));
            $io->table(
                ['Property', 'Value'],
                [
                    ['Key file', $outputFile],
                    ['Permissions', '0600 (owner read/write only)'],
                    ['Algorithm', 'XSalsa20-Poly1305 (sodium_crypto_secretbox)'],
                    ['Key length', \strlen($masterKey) . ' bytes'],
                ],
            );

            $io->warning([
                'IMPORTANT SECURITY NOTES:',
                '1. Back up this key securely - it cannot be recovered if lost',
                '2. All secrets are unrecoverable without this key',
                '3. Keep this file outside of version control',
                '4. Consider using environment variables in production',
            ]);
        }

        // Clear key from memory
        sodium_memzero($masterKey);

        return Command::SUCCESS;
    }
}
