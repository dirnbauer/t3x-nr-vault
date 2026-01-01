<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Command;

use Netresearch\NrVault\Service\SecretDetectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command to scan for potential plaintext secrets.
 *
 * Usage:
 *   vendor/bin/typo3 vault:scan
 *   vendor/bin/typo3 vault:scan --format=json
 *   vendor/bin/typo3 vault:scan --exclude=tx_myext_cache,tx_temp_*
 */
#[AsCommand(
    name: 'vault:scan',
    description: 'Scan for potential plaintext secrets in database and configuration',
)]
final class VaultScanCommand extends Command
{
    public function __construct(
        private readonly SecretDetectionService $detectionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: table, json, or summary',
                'table',
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of tables to exclude (supports wildcards)',
                '',
            )
            ->addOption(
                'severity',
                's',
                InputOption::VALUE_REQUIRED,
                'Minimum severity to report: critical, high, medium, low',
                'low',
            )
            ->addOption(
                'database-only',
                null,
                InputOption::VALUE_NONE,
                'Only scan database tables',
            )
            ->addOption(
                'config-only',
                null,
                InputOption::VALUE_NONE,
                'Only scan configuration files',
            )
            ->setHelp(
                <<<'HELP'
                The <info>%command.name%</info> command scans your TYPO3 installation for potential
                plaintext secrets that should be migrated to the vault.

                <comment>Scan all sources:</comment>
                  <info>%command.full_name%</info>

                <comment>Output as JSON:</comment>
                  <info>%command.full_name% --format=json</info>

                <comment>Exclude specific tables:</comment>
                  <info>%command.full_name% --exclude=tx_myext_cache,tx_temp_*</info>

                <comment>Only show high severity and above:</comment>
                  <info>%command.full_name% --severity=high</info>

                <comment>Scan only database:</comment>
                  <info>%command.full_name% --database-only</info>

                The command detects:
                - Database columns with secret-like names (password, api_key, token, etc.)
                - Known API key patterns (Stripe, AWS, GitHub, Slack, etc.)
                - Extension configuration secrets
                - LocalConfiguration secrets (SMTP password, etc.)

                Severity levels:
                - <error>critical</error>: Known API key pattern detected
                - <comment>high</comment>: Password or private key fields
                - <info>medium</info>: Token or API key fields
                - low: Other potential secrets
                HELP,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $input->getOption('format');
        $excludeTables = array_filter(
            array_map(trim(...), explode(',', (string) $input->getOption('exclude'))),
        );
        $minSeverity = $input->getOption('severity');
        $databaseOnly = $input->getOption('database-only');
        $configOnly = $input->getOption('config-only');

        if ($databaseOnly && $configOnly) {
            $io->error('Cannot use both --database-only and --config-only');

            return Command::FAILURE;
        }

        $io->title('Vault Secret Scanner');
        $io->text('Scanning for potential plaintext secrets...');
        $io->newLine();

        // Perform scan based on options
        if ($databaseOnly) {
            $this->detectionService->scanDatabaseTables($excludeTables);
        } elseif ($configOnly) {
            $this->detectionService->scanExtensionConfiguration();
            $this->detectionService->scanLocalConfiguration();
        } else {
            $this->detectionService->scan($excludeTables);
        }

        $secrets = $this->detectionService->getDetectedSecretsBySeverity();
        $filteredSecrets = $this->filterBySeverity($secrets, $minSeverity);
        $totalCount = $this->countSecrets($filteredSecrets);

        if ($totalCount === 0) {
            $io->success('No potential plaintext secrets detected.');

            return Command::SUCCESS;
        }

        match ($format) {
            'json' => $this->outputJson($output, $filteredSecrets),
            'summary' => $this->outputSummary($io, $secrets),
            default => $this->outputTable($io, $filteredSecrets),
        };

        $io->newLine();
        $io->warning(\sprintf(
            'Found %d potential plaintext %s.',
            $totalCount,
            $totalCount === 1 ? 'secret' : 'secrets',
        ));

        $io->text([
            'To migrate these secrets to the vault, use:',
            '  <info>vendor/bin/typo3 vault:migrate-field TABLE COLUMN</info>',
            '',
            'For configuration secrets, update to use vault references:',
            '  <info>%vault(identifier)%</info>',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Filter secrets by minimum severity.
     *
     * @param array<string, array<string, array<string, mixed>>> $secrets
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function filterBySeverity(array $secrets, string $minSeverity): array
    {
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $minLevel = $severityOrder[$minSeverity] ?? 3;

        $filtered = [];
        foreach ($secrets as $severity => $items) {
            if (($severityOrder[$severity] ?? 3) <= $minLevel) {
                $filtered[$severity] = $items;
            }
        }

        return $filtered;
    }

    /**
     * Count total secrets across all severity levels.
     *
     * @param array<string, array<string, array<string, mixed>>> $secrets
     */
    private function countSecrets(array $secrets): int
    {
        $count = 0;
        foreach ($secrets as $items) {
            $count += \count($items);
        }

        return $count;
    }

    /**
     * Output secrets as JSON.
     *
     * @param array<string, array<string, array<string, mixed>>> $secrets
     */
    private function outputJson(OutputInterface $output, array $secrets): void
    {
        $output->writeln(json_encode($secrets, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    /**
     * Output a summary of detected secrets.
     *
     * @param array<string, array<string, array<string, mixed>>> $secrets
     */
    private function outputSummary(SymfonyStyle $io, array $secrets): void
    {
        $io->section('Summary');

        $rows = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $count = \count($secrets[$severity] ?? []);
            $label = match ($severity) {
                'critical' => '<error>Critical</error>',
                'high' => '<comment>High</comment>',
                'medium' => '<info>Medium</info>',
                default => 'Low',
            };
            $rows[] = [$label, (string) $count];
        }

        $io->table(['Severity', 'Count'], $rows);
    }

    /**
     * Output secrets as a formatted table.
     *
     * @param array<string, array<string, array<string, mixed>>> $secrets
     */
    private function outputTable(SymfonyStyle $io, array $secrets): void
    {
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $items = $secrets[$severity] ?? [];
            if (empty($items)) {
                continue;
            }

            $severityLabel = match ($severity) {
                'critical' => '<error>CRITICAL</error>',
                'high' => '<comment>HIGH</comment>',
                'medium' => '<info>MEDIUM</info>',
                default => 'LOW',
            };

            $io->section(\sprintf('%s Severity (%d)', $severityLabel, \count($items)));

            $table = new Table($io);
            $table->setHeaders(['Location', 'Details', 'Patterns']);

            $first = true;
            foreach ($items as $key => $item) {
                if (!$first) {
                    $table->addRow(new TableSeparator());
                }
                $first = false;

                $location = $key;
                $details = $this->formatDetails($item);
                $patterns = implode(', ', $item['patterns'] ?? []) ?: '-';

                $table->addRow([$location, $details, $patterns]);
            }

            $table->render();
            $io->newLine();
        }
    }

    /**
     * Format details for table output.
     *
     * @param array<string, mixed> $item
     */
    private function formatDetails(array $item): string
    {
        $source = $item['source'] ?? 'unknown';

        return match ($source) {
            'database' => \sprintf(
                '%d records (%d plaintext)',
                $item['count'] ?? 0,
                $item['plaintextCount'] ?? 0,
            ),
            'LocalConfiguration' => 'LocalConfiguration.php',
            'configuration' => 'Extension config',
            default => $source,
        };
    }
}
