<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Exception\VaultException;
use Netresearch\NrVault\Service\VaultServiceInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to list secrets in the vault.
 */
#[AsCommand(
    name: 'vault:list',
    description: 'List secrets in the vault',
)]
final class VaultListCommand extends Command
{
    public function __construct(
        private readonly VaultServiceInterface $vaultService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'pattern',
                'p',
                InputOption::VALUE_REQUIRED,
                'Filter by identifier pattern (supports * wildcard)',
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table, json, csv',
                'table',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of results',
                '100',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pattern = $input->getOption('pattern');
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');

        try {
            $secrets = $this->vaultService->list($pattern);

            // Apply limit
            if ($limit > 0 && \count($secrets) > $limit) {
                $secrets = \array_slice($secrets, 0, $limit);
            }

            if (empty($secrets)) {
                $io->info('No secrets found');

                return Command::SUCCESS;
            }

            switch ($format) {
                case 'json':
                    $output->writeln(json_encode($secrets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'csv':
                    $this->outputCsv($output, $secrets);
                    break;
                default:
                    $this->outputTable($io, $secrets);
            }

            return Command::SUCCESS;
        } catch (VaultException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function outputTable(SymfonyStyle $io, array $secrets): void
    {
        $rows = [];
        foreach ($secrets as $secret) {
            $rows[] = [
                $secret['identifier'],
                $secret['owner_uid'] ?? 0,
                date('Y-m-d H:i', $secret['crdate'] ?? 0),
                date('Y-m-d H:i', $secret['tstamp'] ?? 0),
                $secret['read_count'] ?? 0,
                $secret['last_read_at'] ? date('Y-m-d H:i', $secret['last_read_at']) : '-',
            ];
        }

        $io->table(
            ['Identifier', 'Owner', 'Created', 'Updated', 'Reads', 'Last Read'],
            $rows,
        );

        $io->writeln(\sprintf('<info>Total: %d secrets</info>', \count($secrets)));
    }

    private function outputCsv(OutputInterface $output, array $secrets): void
    {
        // Header
        $output->writeln('identifier,owner_uid,created,updated,read_count,last_read');

        // Rows
        foreach ($secrets as $secret) {
            $output->writeln(\sprintf(
                '%s,%d,%s,%s,%d,%s',
                $this->escapeCsv($secret['identifier']),
                $secret['owner_uid'] ?? 0,
                date('Y-m-d H:i:s', $secret['crdate'] ?? 0),
                date('Y-m-d H:i:s', $secret['tstamp'] ?? 0),
                $secret['read_count'] ?? 0,
                $secret['last_read_at'] ? date('Y-m-d H:i:s', $secret['last_read_at']) : '',
            ));
        }
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
