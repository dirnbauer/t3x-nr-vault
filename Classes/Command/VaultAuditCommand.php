<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use DateTimeImmutable;
use Exception;
use Netresearch\NrVault\Audit\AuditLogServiceInterface;
use Netresearch\NrVault\Exception\VaultException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to query and export audit logs.
 */
#[AsCommand(
    name: 'vault:audit',
    description: 'Query and export vault audit logs',
)]
final class VaultAuditCommand extends Command
{
    public function __construct(
        private readonly AuditLogServiceInterface $auditLogService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'identifier',
                'i',
                InputOption::VALUE_REQUIRED,
                'Filter by secret identifier',
            )
            ->addOption(
                'action',
                'a',
                InputOption::VALUE_REQUIRED,
                'Filter by action (create, read, update, delete, rotate, access_denied)',
            )
            ->addOption(
                'actor',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by actor UID',
            )
            ->addOption(
                'since',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter entries since date (Y-m-d or Y-m-d H:i:s)',
            )
            ->addOption(
                'until',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter entries until date (Y-m-d or Y-m-d H:i:s)',
            )
            ->addOption(
                'success',
                null,
                InputOption::VALUE_REQUIRED,
                'Filter by success status (true/false)',
            )
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: table, json, csv',
                'table',
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of results',
                '50',
            )
            ->addOption(
                'verify',
                null,
                InputOption::VALUE_NONE,
                'Verify hash chain integrity',
            )
            ->addOption(
                'export',
                'e',
                InputOption::VALUE_REQUIRED,
                'Export to file',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Hash chain verification
        if ($input->getOption('verify')) {
            return $this->verifyHashChain($io);
        }

        // Build filters
        $filters = $this->buildFilters($input);
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');

        try {
            $entries = $this->auditLogService->query($filters, $limit, 0);

            if ($entries === []) {
                $io->info('No audit entries found');

                return Command::SUCCESS;
            }

            // Export to file
            $exportFile = $input->getOption('export');
            if ($exportFile !== null) {
                return $this->exportToFile($io, $entries, $exportFile, $format);
            }

            // Output to console
            switch ($format) {
                case 'json':
                    $data = array_map(fn ($e): array => $e->toArray(), $entries);
                    $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    break;
                case 'csv':
                    $this->outputCsv($output, $entries);
                    break;
                default:
                    $this->outputTable($io, $entries);
            }

            return Command::SUCCESS;
        } catch (VaultException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function buildFilters(InputInterface $input): array
    {
        $filters = [];

        if ($input->getOption('identifier')) {
            $filters['secretIdentifier'] = $input->getOption('identifier');
        }

        if ($input->getOption('action')) {
            $filters['action'] = $input->getOption('action');
        }

        if ($input->getOption('actor')) {
            $filters['actorUid'] = (int) $input->getOption('actor');
        }

        if ($input->getOption('since')) {
            try {
                $filters['since'] = new DateTimeImmutable($input->getOption('since'));
            } catch (Exception) {
                // Invalid date, skip
            }
        }

        if ($input->getOption('until')) {
            try {
                $filters['until'] = new DateTimeImmutable($input->getOption('until'));
            } catch (Exception) {
                // Invalid date, skip
            }
        }

        $success = $input->getOption('success');
        if ($success !== null) {
            $filters['success'] = filter_var($success, FILTER_VALIDATE_BOOLEAN);
        }

        return $filters;
    }

    private function verifyHashChain(SymfonyStyle $io): int
    {
        $io->section('Verifying hash chain integrity');

        $result = $this->auditLogService->verifyHashChain();

        if ($result['valid']) {
            $io->success('Hash chain is valid - no tampering detected');

            return Command::SUCCESS;
        }

        $io->error('Hash chain verification FAILED - possible tampering detected');

        if (!empty($result['errors'])) {
            $io->table(
                ['Entry UID', 'Error'],
                array_map(
                    fn ($uid, $error): array => [$uid, $error],
                    array_keys($result['errors']),
                    array_values($result['errors']),
                ),
            );
        }

        return Command::FAILURE;
    }

    private function outputTable(SymfonyStyle $io, array $entries): void
    {
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = [
                date('Y-m-d H:i:s', $entry->crdate),
                $entry->secretIdentifier,
                $entry->action,
                $entry->success ? '✓' : '✗',
                $entry->actorUsername,
                $entry->ipAddress,
                substr((string) $entry->entryHash, 0, 8) . '...',
            ];
        }

        $io->table(
            ['Timestamp', 'Secret', 'Action', 'OK', 'Actor', 'IP', 'Hash'],
            $rows,
        );

        $io->writeln(\sprintf('<info>Total: %d entries</info>', \count($entries)));
    }

    private function outputCsv(OutputInterface $output, array $entries): void
    {
        // Header
        $output->writeln('timestamp,secret_identifier,action,success,actor_username,actor_type,ip_address,entry_hash');

        // Rows
        foreach ($entries as $entry) {
            $output->writeln(\sprintf(
                '%s,%s,%s,%d,%s,%s,%s,%s',
                date('Y-m-d H:i:s', $entry->crdate),
                $this->escapeCsv($entry->secretIdentifier),
                $entry->action,
                $entry->success ? 1 : 0,
                $this->escapeCsv($entry->actorUsername),
                $entry->actorType,
                $entry->ipAddress,
                $entry->entryHash,
            ));
        }
    }

    private function exportToFile(SymfonyStyle $io, array $entries, string $file, string $format): int
    {
        $data = array_map(fn ($e) => $e->toArray(), $entries);

        $content = match ($format) {
            'csv' => $this->formatCsv($data),
            default => json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        };

        $result = file_put_contents($file, $content);
        if ($result === false) {
            $io->error(\sprintf('Failed to write to file: %s', $file));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Exported %d entries to: %s', \count($entries), $file));

        return Command::SUCCESS;
    }

    private function formatCsv(array $data): string
    {
        if ($data === []) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]), escape: '\\');

        foreach ($data as $row) {
            if (isset($row['context']) && \is_array($row['context'])) {
                $row['context'] = json_encode($row['context']);
            }
            fputcsv($output, $row, escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    private function escapeCsv(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}
