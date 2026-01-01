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
 * CLI command to retrieve a secret from the vault.
 */
#[AsCommand(
    name: 'vault:retrieve',
    description: 'Retrieve a secret from the vault',
)]
final class VaultRetrieveCommand extends Command
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
                'Identifier of the secret to retrieve',
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Write secret to file instead of stdout',
            )
            ->addOption(
                'no-newline',
                'n',
                InputOption::VALUE_NONE,
                'Do not append newline to output',
            )
            ->addOption(
                'reason',
                'r',
                InputOption::VALUE_REQUIRED,
                'Reason for retrieving this secret (for audit log)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');

        try {
            $value = $this->vaultService->retrieve($identifier);

            if ($value === null) {
                throw new SecretNotFoundException($identifier, 9236747158);
            }

            $outputFile = $input->getOption('output');
            if ($outputFile !== null) {
                // Write to file with restricted permissions
                $result = file_put_contents($outputFile, $value);
                if ($result === false) {
                    $io->error(\sprintf('Failed to write to file: %s', $outputFile));
                    sodium_memzero($value);

                    return Command::FAILURE;
                }

                // Set restrictive permissions
                chmod($outputFile, 0o600);

                $io->success(\sprintf('Secret written to: %s', $outputFile));
            } else {
                // Write to stdout
                $newline = $input->getOption('no-newline') ? '' : PHP_EOL;
                $output->write($value . $newline, false, OutputInterface::OUTPUT_RAW);
            }

            // Clear the value from memory
            sodium_memzero($value);

            return Command::SUCCESS;
        } catch (SecretNotFoundException) {
            $io->error(\sprintf('Secret not found: %s', $identifier));

            return Command::FAILURE;
        } catch (VaultException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
