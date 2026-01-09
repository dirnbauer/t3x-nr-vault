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
 * Value object representing the result of hash chain verification.
 *
 * Used to validate audit log integrity by checking the hash chain.
 */
readonly class HashChainVerificationResult
{
    /**
     * @param bool $valid Whether the hash chain is valid
     * @param array<int, string> $errors Map of UID => error message for invalid entries
     */
    public function __construct(
        public bool $valid,
        public array $errors = [],
    ) {}

    /**
     * Create a successful verification result.
     */
    public static function valid(): self
    {
        return new self(valid: true, errors: []);
    }

    /**
     * Create a failed verification result.
     *
     * @param array<int, string> $errors Map of UID => error message
     */
    public static function invalid(array $errors): self
    {
        return new self(valid: false, errors: $errors);
    }

    /**
     * Check if verification passed.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Get error count.
     */
    public function getErrorCount(): int
    {
        return \count($this->errors);
    }

    /**
     * Convert to array for JSON serialization.
     *
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'errors' => $this->errors,
        ];
    }
}
