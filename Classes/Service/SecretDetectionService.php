<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\StringType;
use Doctrine\DBAL\Types\TextType;
use Exception;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Service for detecting potential plaintext secrets in the TYPO3 installation.
 *
 * Scans database columns, extension configuration, and LocalConfiguration
 * for values that appear to be unencrypted secrets based on naming patterns
 * and known API key formats.
 */
final class SecretDetectionService implements SingletonInterface
{
    /**
     * Table.column combinations that should be excluded (contain hashes, not secrets).
     *
     * @var array<string>
     */
    private const EXCLUDED_COLUMNS = [
        'be_users.password',    // Contains password hashes (bcrypt/argon2)
        'fe_users.password',    // Contains password hashes (bcrypt/argon2)
    ];

    /**
     * Column name patterns that typically contain secrets.
     *
     * @var array<string>
     */
    private const COLUMN_NAME_PATTERNS = [
        '/password$/i',
        '/^password/i',
        '/api[_-]?key$/i',
        '/api[_-]?secret$/i',
        '/secret[_-]?key$/i',
        '/secret$/i',
        '/token$/i',
        '/access[_-]?token$/i',
        '/refresh[_-]?token$/i',
        '/auth[_-]?token$/i',
        '/credential/i',
        '/private[_-]?key$/i',
        '/encryption[_-]?key$/i',
        '/smtp[_-]?password$/i',
        '/db[_-]?password$/i',
        '/database[_-]?password$/i',
    ];

    /**
     * Value patterns that indicate known API key formats.
     *
     * @var array<string, string>
     */
    private const VALUE_PATTERNS = [
        'Stripe live key' => '/^sk_live_[a-zA-Z0-9]{24,}$/',
        'Stripe test key' => '/^sk_test_[a-zA-Z0-9]{24,}$/',
        'Stripe publishable live' => '/^pk_live_[a-zA-Z0-9]{24,}$/',
        'Stripe publishable test' => '/^pk_test_[a-zA-Z0-9]{24,}$/',
        'AWS Access Key' => '/^AKIA[0-9A-Z]{16}$/',
        'GitHub Personal Access Token' => '/^ghp_[a-zA-Z0-9]{36}$/',
        'GitHub OAuth Token' => '/^gho_[a-zA-Z0-9]{36}$/',
        'GitHub App Token' => '/^ghu_[a-zA-Z0-9]{36}$/',
        'GitHub Refresh Token' => '/^ghr_[a-zA-Z0-9]{36}$/',
        'Slack Bot Token' => '/^xoxb-[0-9]{10,13}-[0-9]{10,13}-[a-zA-Z0-9]{24}$/',
        'Slack User Token' => '/^xoxp-[0-9]{10,13}-[0-9]{10,13}-[a-zA-Z0-9]{24}$/',
        'Slack App Token' => '/^xapp-[0-9]-[A-Z0-9]+-[0-9]+-[a-z0-9]+$/',
        'Google API Key' => '/^AIza[0-9A-Za-z_-]{35}$/',
        'Mailchimp API Key' => '/^[a-f0-9]{32}-us[0-9]{1,2}$/',
        'SendGrid API Key' => '/^SG\.[a-zA-Z0-9_-]{22}\.[a-zA-Z0-9_-]{43}$/',
        'Twilio Auth Token' => '/^[a-f0-9]{32}$/',
        'PayPal Client Secret' => '/^E[A-Za-z0-9_-]{50,80}$/',
        'JWT Token' => '/^eyJ[a-zA-Z0-9_-]+\.eyJ[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+$/',
    ];

    /**
     * Patterns for extension configuration keys that typically contain secrets.
     * Uses regex with word boundaries to avoid false positives like "secretPrefix".
     *
     * @var array<string>
     */
    private const EXT_CONFIG_KEY_PATTERNS = [
        '/password$/i',           // ends with "password" (smtpPassword, dbPassword)
        '/^password$/i',          // exactly "password"
        '/secret$/i',             // ends with "secret" (apiSecret, clientSecret) - NOT "secretPrefix"
        '/token$/i',              // ends with "token" (accessToken, authToken)
        '/apiKey$/i',             // ends with "apiKey"
        '/privateKey$/i',         // ends with "privateKey"
        '/encryptionKey$/i',      // ends with "encryptionKey"
        '/credential/i',          // contains "credential"
    ];

    /** @var array<string, array<string, mixed>> */
    private array $detectedSecrets = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PackageManager $packageManager,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Scan for potential plaintext secrets across all sources.
     *
     * @param array<string> $excludeTables Tables to exclude from scanning
     *
     * @return array<string, array<string, mixed>> Detected secrets grouped by source
     */
    public function scan(array $excludeTables = []): array
    {
        $this->detectedSecrets = [];

        $this->scanDatabaseTables($excludeTables);
        $this->scanExtensionConfiguration();
        $this->scanLocalConfiguration();

        return $this->detectedSecrets;
    }

    /**
     * Scan database tables for columns that might contain secrets.
     *
     * @param array<string> $excludeTables Tables to exclude
     */
    public function scanDatabaseTables(array $excludeTables = []): void
    {
        $defaultExclusions = [
            'tx_nrvault_secret',
            'tx_nrvault_audit_log',
            'be_sessions',
            'fe_sessions',
            'cache_*',
            'cf_*',
            'sys_log',
            'sys_history',
        ];

        $excludeTables = array_merge($defaultExclusions, $excludeTables);

        try {
            $connection = $this->connectionPool->getConnectionByName('Default');
            $schemaManager = $connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();

            foreach ($tables as $tableName) {
                if ($this->isTableExcluded($tableName, $excludeTables)) {
                    continue;
                }

                $this->scanTable($tableName);
            }
        } catch (DbalException) {
            // Silently fail if database is not accessible
        }
    }

    /**
     * Scan extension configuration for potential secrets.
     */
    public function scanExtensionConfiguration(): void
    {
        foreach ($this->packageManager->getActivePackages() as $package) {
            $extKey = $package->getPackageKey();

            try {
                $config = $this->extensionConfiguration->get($extKey);
                if (\is_array($config)) {
                    $this->scanConfigArray($config, "extension:{$extKey}");
                }
            } catch (Exception) {
                // Extension has no configuration - skip
            }
        }
    }

    /**
     * Scan LocalConfiguration for potential secrets.
     */
    public function scanLocalConfiguration(): void
    {
        if (!isset($GLOBALS['TYPO3_CONF_VARS'])) {
            return;
        }

        // Check MAIL configuration
        $mailConfig = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        if (!empty($mailConfig['transport_smtp_password'])) {
            $value = $mailConfig['transport_smtp_password'];
            if (!$this->looksLikeVaultIdentifier($value)) {
                $this->detectedSecrets['config:MAIL.transport_smtp_password'] = [
                    'source' => 'LocalConfiguration',
                    'path' => 'MAIL.transport_smtp_password',
                    'severity' => 'high',
                    'patterns' => [],
                ];
            }
        }

        // Check SYS encryptionKey if it looks weak
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';
        if (!empty($encryptionKey) && \strlen($encryptionKey) < 32) {
            $this->detectedSecrets['config:SYS.encryptionKey'] = [
                'source' => 'LocalConfiguration',
                'path' => 'SYS.encryptionKey',
                'severity' => 'medium',
                'message' => 'Encryption key appears too short (< 32 characters)',
                'patterns' => [],
            ];
        }

        // Note: Extension configuration is already scanned in scanExtensionConfiguration()
        // which properly applies EXCLUDED_EXTENSIONS filter. Do not duplicate here.
    }

    /**
     * Get detected secrets grouped by severity.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function getDetectedSecretsBySeverity(): array
    {
        $grouped = [
            'critical' => [],
            'high' => [],
            'medium' => [],
            'low' => [],
        ];

        foreach ($this->detectedSecrets as $key => $secret) {
            $severity = $secret['severity'] ?? 'low';
            $grouped[$severity][$key] = $secret;
        }

        return $grouped;
    }

    /**
     * Get total count of detected secrets.
     */
    public function getDetectedSecretsCount(): int
    {
        return \count($this->detectedSecrets);
    }

    /**
     * Scan a specific table for secret columns.
     */
    private function scanTable(string $tableName): void
    {
        try {
            $connection = $this->connectionPool->getConnectionByName('Default');
            $schemaManager = $connection->createSchemaManager();
            $columns = $schemaManager->listTableColumns($tableName);

            foreach ($columns as $column) {
                $columnName = $column->getName();
                $columnType = $column->getType();

                // Only check string-type columns (DBAL 4.x removed Type::getName())
                if (!($columnType instanceof StringType)
                    && !($columnType instanceof TextType)
                    && !($columnType instanceof BlobType)) {
                    continue;
                }

                // Skip known hash columns (e.g., be_users.password, fe_users.password)
                if (\in_array("{$tableName}.{$columnName}", self::EXCLUDED_COLUMNS, true)) {
                    continue;
                }

                if ($this->isSecretColumn($columnName)) {
                    $this->analyzeTableColumn($tableName, $columnName);
                }
            }
        } catch (DbalException) {
            // Silently skip tables that cannot be analyzed
        }
    }

    /**
     * Analyze a table column for plaintext secrets.
     */
    private function analyzeTableColumn(string $tableName, string $columnName): void
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($tableName);

            // Count non-empty values
            $count = $queryBuilder
                ->count('uid')
                ->from($tableName)
                ->where(
                    $queryBuilder->expr()->isNotNull($columnName),
                    $queryBuilder->expr()->neq($columnName, $queryBuilder->createNamedParameter('')),
                )
                ->executeQuery()
                ->fetchOne();

            if ($count > 0) {
                // Check if values look encrypted (vault identifiers or encrypted blobs)
                $sampleQuery = $this->connectionPool->getQueryBuilderForTable($tableName);
                $samples = $sampleQuery
                    ->select($columnName)
                    ->from($tableName)
                    ->where(
                        $sampleQuery->expr()->isNotNull($columnName),
                        $sampleQuery->expr()->neq($columnName, $sampleQuery->createNamedParameter('')),
                    )
                    ->setMaxResults(5)
                    ->executeQuery()
                    ->fetchAllAssociative();

                $plaintextCount = 0;
                $patterns = [];

                foreach ($samples as $row) {
                    $value = (string) $row[$columnName];

                    // Skip if it looks like a vault identifier
                    if ($this->looksLikeVaultIdentifier($value)) {
                        continue;
                    }

                    // Skip if it looks already encrypted
                    if ($this->looksEncrypted($value)) {
                        continue;
                    }

                    // Check for known patterns
                    $detectedPattern = $this->detectValuePattern($value);
                    if ($detectedPattern !== null) {
                        $patterns[$detectedPattern] = true;
                    }

                    ++$plaintextCount;
                }

                if ($plaintextCount > 0) {
                    $key = "database:{$tableName}.{$columnName}";
                    $this->detectedSecrets[$key] = [
                        'source' => 'database',
                        'table' => $tableName,
                        'column' => $columnName,
                        'count' => (int) $count,
                        'plaintextCount' => $plaintextCount,
                        'patterns' => array_keys($patterns),
                        'severity' => $this->calculateSeverity($columnName, array_keys($patterns)),
                    ];
                }
            }
        } catch (DbalException) {
            // Silently skip columns that cannot be analyzed
        }
    }

    /**
     * Recursively scan a configuration array for secret keys.
     *
     * @param array<string, mixed> $config Configuration array
     * @param string $prefix Path prefix for keys
     */
    private function scanConfigArray(array $config, string $prefix): void
    {
        foreach ($config as $key => $value) {
            if (\is_array($value)) {
                $this->scanConfigArray($value, "{$prefix}.{$key}");
                continue;
            }

            if (!\is_string($value) || $value === '') {
                continue;
            }

            // Check if key name suggests a secret
            if ($this->isSecretConfigKey($key)) {
                // Skip vault references
                if ($this->looksLikeVaultIdentifier($value)) {
                    continue;
                }

                $detectedPattern = $this->detectValuePattern($value);
                $patterns = $detectedPattern !== null ? [$detectedPattern] : [];

                $this->detectedSecrets["{$prefix}.{$key}"] = [
                    'source' => 'configuration',
                    'path' => "{$prefix}.{$key}",
                    'severity' => $this->calculateSeverity($key, $patterns),
                    'patterns' => $patterns,
                ];
            }
        }
    }

    /**
     * Check if a column name matches secret patterns.
     */
    private function isSecretColumn(string $columnName): bool
    {
        foreach (self::COLUMN_NAME_PATTERNS as $pattern) {
            if (preg_match($pattern, $columnName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a config key matches secret patterns.
     * Uses regex to match suffixes/patterns, avoiding false positives like "secretPrefix".
     */
    private function isSecretConfigKey(string $key): bool
    {
        foreach (self::EXT_CONFIG_KEY_PATTERNS as $pattern) {
            if (preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a table should be excluded from scanning.
     *
     * @param string $tableName Table name
     * @param array<string> $excludePatterns Patterns to exclude
     */
    private function isTableExcluded(string $tableName, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            if (str_contains($pattern, '*')) {
                // Quote all special chars first, then replace escaped \* with .*
                $quotedPattern = preg_quote($pattern, '/');
                $regex = '/^' . str_replace('\\*', '.*', $quotedPattern) . '$/';
                if (preg_match($regex, $tableName)) {
                    return true;
                }
            } elseif ($tableName === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a value looks like a vault identifier.
     */
    private function looksLikeVaultIdentifier(string $value): bool
    {
        // Vault identifiers follow pattern: table__field__uid or prefix_suffix
        // Also check for vault reference format: %vault(identifier)%
        return preg_match('/^[a-z][a-z0-9_]+__[a-z][a-z0-9_]+__\d+$/i', $value) === 1
            || preg_match('/^%vault\([^)]+\)%$/', $value) === 1;
    }

    /**
     * Check if a value looks already encrypted or is a password hash.
     */
    private function looksEncrypted(string $value): bool
    {
        // Check for bcrypt hash ($2y$, $2a$, $2b$)
        if (preg_match('/^\$2[yab]\$[0-9]{2}\$[.\/A-Za-z0-9]{53}$/', $value)) {
            return true;
        }

        // Check for Argon2 hash ($argon2i$, $argon2id$)
        if (str_starts_with($value, '$argon2i$') || str_starts_with($value, '$argon2id$')) {
            return true;
        }

        // Check for base64-encoded encrypted data (typically > 50 chars, high entropy)
        if (\strlen($value) > 50 && preg_match('/^[A-Za-z0-9+\/=]+$/', $value)) {
            return true;
        }

        // Check for hex-encoded data
        return (bool) (\strlen($value) > 50 && preg_match('/^[0-9a-f]+$/i', $value));
    }

    /**
     * Detect if a value matches known API key patterns.
     */
    private function detectValuePattern(string $value): ?string
    {
        foreach (self::VALUE_PATTERNS as $name => $pattern) {
            if (preg_match($pattern, $value)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Calculate severity based on column/key name and detected patterns.
     *
     * @param string $name Column or config key name
     * @param array<string> $patterns Detected value patterns
     *
     * @return string 'critical', 'high', 'medium', 'low'
     */
    private function calculateSeverity(string $name, array $patterns): string
    {
        $nameLower = strtolower($name);

        // Critical: Known API key patterns detected
        if (!empty($patterns)) {
            return 'critical';
        }

        // High: Password or private key fields
        if (str_contains($nameLower, 'password')
            || str_contains($nameLower, 'private')
            || str_contains($nameLower, 'secret')) {
            return 'high';
        }

        // Medium: Token or API key fields
        if (str_contains($nameLower, 'token')
            || str_contains($nameLower, 'apikey')
            || str_contains($nameLower, 'api_key')) {
            return 'medium';
        }

        return 'low';
    }
}
