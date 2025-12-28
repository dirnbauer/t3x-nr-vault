<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Audit;

/**
 * Represents an audit log entry.
 */
final class AuditLogEntry
{
    public function __construct(
        public readonly int $uid,
        public readonly string $secretIdentifier,
        public readonly string $action,
        public readonly bool $success,
        public readonly ?string $errorMessage,
        public readonly ?string $reason,
        public readonly int $actorUid,
        public readonly string $actorType,
        public readonly string $actorUsername,
        public readonly string $actorRole,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly string $requestId,
        public readonly string $previousHash,
        public readonly string $entryHash,
        public readonly string $hashBefore,
        public readonly string $hashAfter,
        public readonly int $crdate,
        public readonly array $context,
    ) {}

    /**
     * Create from database row.
     */
    public static function fromDatabaseRow(array $row): self
    {
        $context = [];
        if (!empty($row['context'])) {
            $decoded = json_decode((string) $row['context'], true);
            if (\is_array($decoded)) {
                $context = $decoded;
            }
        }

        return new self(
            uid: (int) $row['uid'],
            secretIdentifier: (string) ($row['secret_identifier'] ?? ''),
            action: (string) ($row['action'] ?? ''),
            success: (bool) ($row['success'] ?? false),
            errorMessage: $row['error_message'] ?: null,
            reason: $row['reason'] ?: null,
            actorUid: (int) ($row['actor_uid'] ?? 0),
            actorType: (string) ($row['actor_type'] ?? ''),
            actorUsername: (string) ($row['actor_username'] ?? ''),
            actorRole: (string) ($row['actor_role'] ?? ''),
            ipAddress: (string) ($row['ip_address'] ?? ''),
            userAgent: (string) ($row['user_agent'] ?? ''),
            requestId: (string) ($row['request_id'] ?? ''),
            previousHash: (string) ($row['previous_hash'] ?? ''),
            entryHash: (string) ($row['entry_hash'] ?? ''),
            hashBefore: (string) ($row['hash_before'] ?? ''),
            hashAfter: (string) ($row['hash_after'] ?? ''),
            crdate: (int) ($row['crdate'] ?? 0),
            context: $context,
        );
    }

    /**
     * Convert to array for export.
     */
    public function toArray(): array
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
