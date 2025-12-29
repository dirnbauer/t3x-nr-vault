<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH <info@netresearch.de>
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http\OAuth;

use DateTimeImmutable;

/**
 * Represents an OAuth 2.0 access token.
 */
final readonly class OAuthToken
{
    public function __construct(
        public string $accessToken,
        public string $tokenType,
        public DateTimeImmutable $expiresAt,
        public ?string $scope = null,
    ) {}

    /**
     * Check if the token is expired.
     *
     * @param int $buffer Seconds before actual expiry to consider expired
     */
    public function isExpired(int $buffer = 0): bool
    {
        $now = new DateTimeImmutable();
        $expiryWithBuffer = $this->expiresAt->modify("-{$buffer} seconds");

        return $now >= $expiryWithBuffer;
    }

    /**
     * Get the Authorization header value.
     */
    public function getAuthorizationHeader(): string
    {
        return $this->tokenType . ' ' . $this->accessToken;
    }

    /**
     * Get seconds until token expires.
     */
    public function getExpiresIn(): int
    {
        $now = new DateTimeImmutable();
        $diff = $this->expiresAt->getTimestamp() - $now->getTimestamp();

        return \max(0, $diff);
    }
}
