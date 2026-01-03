<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

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
     * @param AuditContextInterface|null $context Additional context data
     */
    public function log(
        string $secretIdentifier,
        string $action,
        bool $success,
        ?string $errorMessage = null,
        ?string $reason = null,
        ?string $hashBefore = null,
        ?string $hashAfter = null,
        ?AuditContextInterface $context = null,
    ): void;

    /**
     * Query audit logs.
     *
     * @param AuditLogFilter|null $filter Filter criteria (null = no filter)
     *
     * @return list<AuditLogEntry>
     */
    public function query(?AuditLogFilter $filter = null, int $limit = 100, int $offset = 0): array;

    /**
     * Get audit log count for filter.
     */
    public function count(?AuditLogFilter $filter = null): int;

    /**
     * Export all audit logs matching filter (no pagination).
     *
     * @return list<AuditLogEntry>
     */
    public function export(?AuditLogFilter $filter = null): array;

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
