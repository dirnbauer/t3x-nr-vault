<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

use JsonSerializable;

/**
 * Represents an audit log entry.
 */
final readonly class AuditLogEntry implements JsonSerializable
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public int $uid,
        public string $secretIdentifier,
        public string $action,
        public bool $success,
        public ?string $errorMessage,
        public ?string $reason,
        public int $actorUid,
        public string $actorType,
        public string $actorUsername,
        public string $actorRole,
        public string $ipAddress,
        public string $userAgent,
        public string $requestId,
        public string $previousHash,
        public string $entryHash,
        public string $hashBefore,
        public string $hashAfter,
        public int $crdate,
        public array $context,
    ) {}

    /**
     * Create from database row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        /** @var array<string, mixed> $context */
        $context = [];
        if (!empty($row['context'])) {
            $contextValue = $row['context'];
            $decoded = json_decode(\is_string($contextValue) ? $contextValue : '', true);
            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $context = $decoded;
            }
        }

        $errorMessage = $row['error_message'] ?? null;
        $reason = $row['reason'] ?? null;

        return new self(
            uid: is_numeric($row['uid'] ?? null) ? (int) $row['uid'] : 0,
            secretIdentifier: \is_string($row['secret_identifier'] ?? null) ? $row['secret_identifier'] : '',
            action: \is_string($row['action'] ?? null) ? $row['action'] : '',
            success: (bool) ($row['success'] ?? false),
            errorMessage: \is_string($errorMessage) && $errorMessage !== '' ? $errorMessage : null,
            reason: \is_string($reason) && $reason !== '' ? $reason : null,
            actorUid: is_numeric($row['actor_uid'] ?? null) ? (int) $row['actor_uid'] : 0,
            actorType: \is_string($row['actor_type'] ?? null) ? $row['actor_type'] : '',
            actorUsername: \is_string($row['actor_username'] ?? null) ? $row['actor_username'] : '',
            actorRole: \is_string($row['actor_role'] ?? null) ? $row['actor_role'] : '',
            ipAddress: \is_string($row['ip_address'] ?? null) ? $row['ip_address'] : '',
            userAgent: \is_string($row['user_agent'] ?? null) ? $row['user_agent'] : '',
            requestId: \is_string($row['request_id'] ?? null) ? $row['request_id'] : '',
            previousHash: \is_string($row['previous_hash'] ?? null) ? $row['previous_hash'] : '',
            entryHash: \is_string($row['entry_hash'] ?? null) ? $row['entry_hash'] : '',
            hashBefore: \is_string($row['hash_before'] ?? null) ? $row['hash_before'] : '',
            hashAfter: \is_string($row['hash_after'] ?? null) ? $row['hash_after'] : '',
            crdate: is_numeric($row['crdate'] ?? null) ? (int) $row['crdate'] : 0,
            context: $context,
        );
    }

    /**
     * @return array<string, scalar|array<string, mixed>|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'uid' => $this->uid,
            'secretIdentifier' => $this->secretIdentifier,
            'action' => $this->action,
            'success' => $this->success,
            'errorMessage' => $this->errorMessage,
            'reason' => $this->reason,
            'actorUid' => $this->actorUid,
            'actorType' => $this->actorType,
            'actorUsername' => $this->actorUsername,
            'actorRole' => $this->actorRole,
            'ipAddress' => $this->ipAddress,
            'userAgent' => $this->userAgent,
            'requestId' => $this->requestId,
            'previousHash' => $this->previousHash,
            'entryHash' => $this->entryHash,
            'hashBefore' => $this->hashBefore,
            'hashAfter' => $this->hashAfter,
            'timestamp' => date('c', $this->crdate),
            'context' => $this->context,
        ];
    }
}
