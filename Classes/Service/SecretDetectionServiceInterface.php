<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Service;

use Netresearch\NrVault\Service\Detection\SecretFinding;

/**
 * Interface for secret detection service.
 */
interface SecretDetectionServiceInterface
{
    /**
     * Scan for potential plaintext secrets across all sources.
     *
     * @param array<string> $excludeTables Tables to exclude from scanning
     *
     * @return array<string, SecretFinding>
     */
    public function scan(array $excludeTables = []): array;

    /**
     * Scan database tables for columns that might contain secrets.
     *
     * @param array<string> $excludeTables Tables to exclude
     */
    public function scanDatabaseTables(array $excludeTables = []): void;

    /**
     * Scan extension configuration for potential secrets.
     */
    public function scanExtensionConfiguration(): void;

    /**
     * Scan LocalConfiguration for potential secrets.
     */
    public function scanLocalConfiguration(): void;

    /**
     * Get detected secrets grouped by severity.
     *
     * @return array<string, array<string, SecretFinding>>
     */
    public function getDetectedSecretsBySeverity(): array;

    /**
     * Get total count of detected secrets.
     */
    public function getDetectedSecretsCount(): int;
}
