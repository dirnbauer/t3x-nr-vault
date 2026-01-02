<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Domain\Model;

/**
 * Secret entity representing an encrypted secret.
 */
final class Secret
{
    private ?int $uid = null;

    private int $scopePid = 0;

    private string $identifier = '';

    private string $description = '';

    private ?string $encryptedValue = null;

    private string $encryptedDek = '';

    private string $dekNonce = '';

    private string $valueNonce = '';

    private int $encryptionVersion = 1;

    private string $valueChecksum = '';

    private int $ownerUid = 0;

    /** @var int[] */
    private array $allowedGroups = [];

    private string $context = '';

    private bool $frontendAccessible = false;

    private int $version = 1;

    private int $expiresAt = 0;

    private int $lastRotatedAt = 0;

    private array $metadata = [];

    private string $adapter = 'local';

    private string $externalReference = '';

    private int $tstamp = 0;

    private int $crdate = 0;

    private int $cruserId = 0;

    private bool $deleted = false;

    private bool $hidden = false;

    private int $readCount = 0;

    private int $lastReadAt = 0;

    public function getUid(): ?int
    {
        return $this->uid;
    }

    public function setUid(?int $uid): self
    {
        $this->uid = $uid;

        return $this;
    }

    public function getScopePid(): int
    {
        return $this->scopePid;
    }

    public function setScopePid(int $scopePid): self
    {
        $this->scopePid = $scopePid;

        return $this;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): self
    {
        $this->identifier = $identifier;

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getEncryptedValue(): ?string
    {
        return $this->encryptedValue;
    }

    public function setEncryptedValue(?string $encryptedValue): self
    {
        $this->encryptedValue = $encryptedValue;

        return $this;
    }

    public function getEncryptedDek(): string
    {
        return $this->encryptedDek;
    }

    public function setEncryptedDek(string $encryptedDek): self
    {
        $this->encryptedDek = $encryptedDek;

        return $this;
    }

    public function getDekNonce(): string
    {
        return $this->dekNonce;
    }

    public function setDekNonce(string $dekNonce): self
    {
        $this->dekNonce = $dekNonce;

        return $this;
    }

    public function getValueNonce(): string
    {
        return $this->valueNonce;
    }

    public function setValueNonce(string $valueNonce): self
    {
        $this->valueNonce = $valueNonce;

        return $this;
    }

    public function getEncryptionVersion(): int
    {
        return $this->encryptionVersion;
    }

    public function setEncryptionVersion(int $encryptionVersion): self
    {
        $this->encryptionVersion = $encryptionVersion;

        return $this;
    }

    public function getValueChecksum(): string
    {
        return $this->valueChecksum;
    }

    public function setValueChecksum(string $valueChecksum): self
    {
        $this->valueChecksum = $valueChecksum;

        return $this;
    }

    public function getOwnerUid(): int
    {
        return $this->ownerUid;
    }

    public function setOwnerUid(int $ownerUid): self
    {
        $this->ownerUid = $ownerUid;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getAllowedGroups(): array
    {
        return $this->allowedGroups;
    }

    /**
     * @param int[] $allowedGroups
     */
    public function setAllowedGroups(array $allowedGroups): self
    {
        $this->allowedGroups = array_map(intval(...), $allowedGroups);

        return $this;
    }

    public function getContext(): string
    {
        return $this->context;
    }

    public function setContext(string $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function isFrontendAccessible(): bool
    {
        return $this->frontendAccessible;
    }

    public function setFrontendAccessible(bool $frontendAccessible): self
    {
        $this->frontendAccessible = $frontendAccessible;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }

    public function incrementVersion(): self
    {
        $this->version++;

        return $this;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(int $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt > 0 && $this->expiresAt < time();
    }

    public function getLastRotatedAt(): int
    {
        return $this->lastRotatedAt;
    }

    public function setLastRotatedAt(int $lastRotatedAt): self
    {
        $this->lastRotatedAt = $lastRotatedAt;

        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getAdapter(): string
    {
        return $this->adapter;
    }

    public function setAdapter(string $adapter): self
    {
        $this->adapter = $adapter;

        return $this;
    }

    public function getExternalReference(): string
    {
        return $this->externalReference;
    }

    public function setExternalReference(string $externalReference): self
    {
        $this->externalReference = $externalReference;

        return $this;
    }

    public function getTstamp(): int
    {
        return $this->tstamp;
    }

    public function setTstamp(int $tstamp): self
    {
        $this->tstamp = $tstamp;

        return $this;
    }

    public function getCrdate(): int
    {
        return $this->crdate;
    }

    public function setCrdate(int $crdate): self
    {
        $this->crdate = $crdate;

        return $this;
    }

    public function getCruserId(): int
    {
        return $this->cruserId;
    }

    public function setCruserId(int $cruserId): self
    {
        $this->cruserId = $cruserId;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function setDeleted(bool $deleted): self
    {
        $this->deleted = $deleted;

        return $this;
    }

    public function isHidden(): bool
    {
        return $this->hidden;
    }

    public function setHidden(bool $hidden): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    public function getReadCount(): int
    {
        return $this->readCount;
    }

    public function setReadCount(int $readCount): self
    {
        $this->readCount = $readCount;

        return $this;
    }

    public function incrementReadCount(): self
    {
        $this->readCount++;

        return $this;
    }

    public function getLastReadAt(): int
    {
        return $this->lastReadAt;
    }

    public function setLastReadAt(int $lastReadAt): self
    {
        $this->lastReadAt = $lastReadAt;

        return $this;
    }

    /**
     * Create from database row.
     */
    public static function fromDatabaseRow(array $row): self
    {
        $secret = new self();
        $secret->uid = isset($row['uid']) ? (int) $row['uid'] : null;
        $secret->scopePid = (int) ($row['scope_pid'] ?? 0);
        $secret->identifier = (string) ($row['identifier'] ?? '');
        $secret->description = (string) ($row['description'] ?? '');
        $secret->encryptedValue = $row['encrypted_value'] ?? null;
        $secret->encryptedDek = (string) ($row['encrypted_dek'] ?? '');
        $secret->dekNonce = (string) ($row['dek_nonce'] ?? '');
        $secret->valueNonce = (string) ($row['value_nonce'] ?? '');
        $secret->encryptionVersion = (int) ($row['encryption_version'] ?? 1);
        $secret->valueChecksum = (string) ($row['value_checksum'] ?? '');
        $secret->ownerUid = (int) ($row['owner_uid'] ?? 0);
        $secret->context = (string) ($row['context'] ?? '');
        $secret->frontendAccessible = (bool) ($row['frontend_accessible'] ?? false);
        $secret->version = (int) ($row['version'] ?? 1);
        $secret->expiresAt = (int) ($row['expires_at'] ?? 0);
        $secret->lastRotatedAt = (int) ($row['last_rotated_at'] ?? 0);
        $secret->adapter = (string) ($row['adapter'] ?? 'local');
        $secret->externalReference = (string) ($row['external_reference'] ?? '');
        $secret->tstamp = (int) ($row['tstamp'] ?? 0);
        $secret->crdate = (int) ($row['crdate'] ?? 0);
        $secret->cruserId = (int) ($row['cruser_id'] ?? 0);
        $secret->deleted = (bool) ($row['deleted'] ?? false);
        $secret->hidden = (bool) ($row['hidden'] ?? false);
        $secret->readCount = (int) ($row['read_count'] ?? 0);
        $secret->lastReadAt = (int) ($row['last_read_at'] ?? 0);

        // Parse metadata JSON
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string) $row['metadata'], true);
            $secret->metadata = \is_array($decoded) ? $decoded : [];
        }

        // Parse allowed groups (comma-separated or from MM table)
        if (!empty($row['allowed_groups'])) {
            $groups = (string) $row['allowed_groups'];
            $secret->allowedGroups = array_filter(array_map(intval(...), explode(',', $groups)));
        }

        return $secret;
    }

    /**
     * Convert to database row.
     */
    public function toDatabaseRow(): array
    {
        return [
            'scope_pid' => $this->scopePid,
            'identifier' => $this->identifier,
            'description' => $this->description,
            'encrypted_value' => $this->encryptedValue,
            'encrypted_dek' => $this->encryptedDek,
            'dek_nonce' => $this->dekNonce,
            'value_nonce' => $this->valueNonce,
            'encryption_version' => $this->encryptionVersion,
            'value_checksum' => $this->valueChecksum,
            'owner_uid' => $this->ownerUid,
            'allowed_groups' => implode(',', $this->allowedGroups),
            'context' => $this->context,
            'frontend_accessible' => $this->frontendAccessible ? 1 : 0,
            'version' => $this->version,
            'expires_at' => $this->expiresAt,
            'last_rotated_at' => $this->lastRotatedAt,
            'metadata' => json_encode($this->metadata),
            'adapter' => $this->adapter,
            'external_reference' => $this->externalReference,
            'tstamp' => time(),
            'deleted' => $this->deleted ? 1 : 0,
            'hidden' => $this->hidden ? 1 : 0,
            'read_count' => $this->readCount,
            'last_read_at' => $this->lastReadAt,
        ];
    }
}
