<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

use DateTimeInterface;

/**
 * Interface for audit logging operations.
 */
interface AuditLogServiceInterface
{
    /**
     * Log a vault operation.
     *
     * @param string $secretIdentifier The secret that was accessed
     * @param string $action One of: create, read, update, delete, rotate, access_denied, http_call
     * @param bool $success Whether operation succeeded
     * @param string|null $errorMessage If failed, the error message
     * @param string|null $reason Reason for operation (required for rotate/delete)
     * @param string|null $hashBefore Secret's value_checksum before operation
     * @param string|null $hashAfter Secret's value_checksum after operation
     * @param array $context Additional context (JSON-serializable)
     */
    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null,
        ?string $reason = null,
        ?string $hashBefore = null,
        ?string $hashAfter = null,
        array $context = [],
    ): void;

    /**
     * Query audit logs.
     *
     * @param array{secretIdentifier?: string, action?: string, actorUid?: int, since?: DateTimeInterface, until?: DateTimeInterface, success?: bool} $filters
     *
     * @return AuditLogEntry[]
     */
    public function query(array $filters = [], int $limit = 100, int $offset = 0): array;

    /**
     * Get audit log count for filters.
     */
    public function count(array $filters = []): int;

    /**
     * Export audit logs to array.
     */
    public function export(array $filters = []): array;

    /**
     * Verify hash chain integrity.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function verifyHashChain(?int $fromUid = null, ?int $toUid = null): array;

    /**
     * Get the hash of the most recent audit log entry.
     */
    public function getLatestHash(): ?string;
}
