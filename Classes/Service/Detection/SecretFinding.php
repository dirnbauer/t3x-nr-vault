<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Service\Detection;

use JsonSerializable;

/**
 * Represents a detected potential secret in the system.
 */
interface SecretFinding extends JsonSerializable
{
    /**
     * Unique key identifying this finding (e.g., "database:be_users.api_key").
     */
    public function getKey(): string;

    /**
     * Source type: 'database' or 'config'.
     */
    public function getSource(): string;

    /**
     * Severity level of this finding.
     */
    public function getSeverity(): Severity;

    /**
     * Patterns that matched (e.g., "Stripe live key", "AWS Access Key").
     *
     * @return list<string>
     */
    public function getPatterns(): array;

    /**
     * Human-readable description for display.
     */
    public function getDetails(): string;
}
