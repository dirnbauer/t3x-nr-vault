<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

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
 * CLI command to store a secret in the vault.
 */
#[AsCommand(
    name: 'vault:store',
    description: 'Store a secret in the vault',
)]
final class VaultStoreCommand extends Command
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
                'Unique identifier for the secret (alphanumeric, underscores, max 255 chars)',
            )
            ->addOption(
                'value',
                null,
                InputOption::VALUE_REQUIRED,
                'The secret value (will prompt if not provided)',
            )
            ->addOption(
                'stdin',
                null,
                InputOption::VALUE_NONE,
                'Read secret value from stdin',
            )
            ->addOption(
                'file',
                'f',
                InputOption::VALUE_REQUIRED,
                'Read secret value from file',
            )
            ->addOption(
                'metadata',
                'm',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Additional metadata as key=value pairs',
            )
            ->addOption(
                'groups',
                'g',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Backend user group IDs that can access this secret',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');

        // Get secret value from various sources
        $value = $this->getSecretValue($input, $io);
        if ($value === null) {
            $io->error('No secret value provided');

            return Command::FAILURE;
        }

        // Parse metadata
        $metadata = $this->parseMetadata($input->getOption('metadata'));

        // Add groups to metadata if specified
        $groups = $input->getOption('groups');
        if (!empty($groups)) {
            $metadata['allowed_groups'] = array_map('intval', $groups);
        }

        try {
            $this->vaultService->store($identifier, $value, $metadata);
            $io->success(\sprintf('Secret "%s" stored successfully', $identifier));

            // Clear the value from memory
            sodium_memzero($value);

            return Command::SUCCESS;
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
            return $io->askHidden('Enter secret value');
        }

        return null;
    }

    private function parseMetadata(array $pairs): array
    {
        $metadata = [];
        foreach ($pairs as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $metadata[trim($key)] = trim($value);
            }
        }

        return $metadata;
    }
}
