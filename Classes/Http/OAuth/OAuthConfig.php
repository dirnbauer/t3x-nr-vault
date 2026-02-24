<?php

/*
 * This file is part of the nr-vault TYPO3 extension.
 *
 * (c) Netresearch DTT GmbH
 *
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Http\OAuth;

/**
 * Configuration for OAuth 2.0 authentication.
 *
 * Supports client_credentials and refresh_token grant types.
 */
final readonly class OAuthConfig
{
    /**
     * @param string $tokenEndpoint OAuth token endpoint URL
     * @param string $clientIdSecret Vault identifier for client ID
     * @param string $clientSecretSecret Vault identifier for client secret
     * @param string $grantType Grant type (client_credentials, refresh_token)
     * @param string|null $refreshTokenSecret Vault identifier for refresh token (if using refresh_token grant)
     * @param array<string> $scopes OAuth scopes to request
     * @param int $tokenExpiryBuffer Seconds before expiry to trigger refresh (default: 60)
     * @param array<string, string> $additionalParams Additional parameters for token request
     */
    public function __construct(
        public string $tokenEndpoint,
        public string $clientIdSecret,
        public string $clientSecretSecret,
        public string $grantType = 'client_credentials',
        public ?string $refreshTokenSecret = null,
        public array $scopes = [],
        public int $tokenExpiryBuffer = 60,
        public array $additionalParams = [],
    ) {}

    /**
     * Create config for client credentials flow.
     *
     * @param string $tokenEndpoint OAuth token endpoint URL
     * @param string $clientIdSecret Vault identifier for client ID
     * @param string $clientSecretSecret Vault identifier for client secret
     * @param array<string> $scopes OAuth scopes to request
     */
    public static function clientCredentials(
        string $tokenEndpoint,
        string $clientIdSecret,
        string $clientSecretSecret,
        array $scopes = [],
    ): self {
        return new self(
            tokenEndpoint: $tokenEndpoint,
            clientIdSecret: $clientIdSecret,
            clientSecretSecret: $clientSecretSecret,
            grantType: 'client_credentials',
            scopes: $scopes,
        );
    }

    /**
     * Create config for refresh token flow.
     *
     * @param string $tokenEndpoint OAuth token endpoint URL
     * @param string $clientIdSecret Vault identifier for client ID
     * @param string $clientSecretSecret Vault identifier for client secret
     * @param string $refreshTokenSecret Vault identifier for refresh token
     * @param array<string> $scopes OAuth scopes to request
     */
    public static function refreshToken(
        string $tokenEndpoint,
        string $clientIdSecret,
        string $clientSecretSecret,
        string $refreshTokenSecret,
        array $scopes = [],
    ): self {
        return new self(
            tokenEndpoint: $tokenEndpoint,
            clientIdSecret: $clientIdSecret,
            clientSecretSecret: $clientSecretSecret,
            grantType: 'refresh_token',
            refreshTokenSecret: $refreshTokenSecret,
            scopes: $scopes,
        );
    }

    /**
     * Get scopes as space-separated string.
     */
    public function getScopesString(): string
    {
        return implode(' ', $this->scopes);
    }
}
