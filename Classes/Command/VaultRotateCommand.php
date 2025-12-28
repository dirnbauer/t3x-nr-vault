<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Exception\SecretNotFoundException;
use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to rotate a secret in the vault.
 */
#[AsCommand(
    name: 'vault:rotate',
    description: 'Rotate (update) a secret in the vault',
)]
final class VaultRotateCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'identifier',
                InputArgument::REQUIRED,
                'Identifier of the secret to rotate',
            )
            ->addOption(
                'value',
                null,
                InputOption::VALUE_REQUIRED,
                'The new secret value (will prompt if not provided)',
            )
            ->addOption(
                'stdin',
                null,
                InputOption::VALUE_NONE,
                'Read new secret value from stdin',
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Read new secret value from file',
            )
            ->addOption(
                'reason',
                'r',
                InputOption::VALUE_REQUIRED,
                'Reason for rotation (required)',
                'Manual rotation via CLI',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');
        $reason = $input->getOption('reason');

        // Check if secret exists
        $metadata = $this->vaultService->getMetadata($identifier);
        if ($metadata === null) {
            $io->error(\sprintf('Secret not found: %s', $identifier));

            return Command::FAILURE;
        }

        // Get new secret value
        $newValue = $this->getSecretValue($input, $io);
        if ($newValue === null) {
            $io->error('No new secret value provided');

            return Command::FAILURE;
        }

        try {
            $this->vaultService->rotate($identifier, $newValue, $reason);
            $io->success(\sprintf('Secret "%s" rotated successfully', $identifier));

            // Show rotation info
            $io->table(
                ['Property', 'Value'],
                [
                    ['Identifier', $identifier],
                    ['Reason', $reason],
                    ['Rotated at', date('Y-m-d H:i:s')],
                ],
            );

            // Clear the value from memory
            sodium_memzero($newValue);

            return Command::SUCCESS;
        } catch (SecretNotFoundException $e) {
            $io->error(\sprintf('Secret not found: %s', $identifier));

            return Command::FAILURE;
        } catch (VaultException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function getSecretValue(InputInterface $input, SymfonyStyle $io): ?string
    {
        // Option 1: From --value option
        $value = $input->getOption('value');
        if ($value !== null) {
            return $value;
        }

        // Option 2: From stdin
        if ($input->getOption('stdin')) {
            $value = file_get_contents('php://stdin');
            if ($value !== false) {
                return rtrim($value, "\n\r");
            }

            return null;
        }

        // Option 3: From file
        $file = $input->getOption('file');
        if ($file !== null) {
            if (!file_exists($file)) {
                $io->error(\sprintf('File not found: %s', $file));

                return null;
            }
            $value = file_get_contents($file);
            if ($value !== false) {
                return $value;
            }

            return null;
        }

        // Option 4: Interactive prompt (hidden input)
        if ($input->isInteractive()) {
            return $io->askHidden('Enter new secret value');
        }

        return null;
    }
}
