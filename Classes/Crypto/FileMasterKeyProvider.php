<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Crypto;

use Netresearch\NrVault\Configuration\ExtensionConfiguration;
use Netresearch\NrVault\Configuration\ExtensionConfigurationInterface;
use Netresearch\NrVault\Exception\MasterKeyException;

/**
 * File-based master key provider.
 */
final readonly class FileMasterKeyProvider implements MasterKeyProviderInterface
{
    private const KEY_LENGTH = 32; // 256 bits

    public function __construct(
        private ExtensionConfigurationInterface $configuration,
    ) {}

    public function getIdentifier(): string
    {
        return 'file';
    }

    public function isAvailable(): bool
    {
        $path = $this->getKeyPath();

        return $path !== '' && file_exists($path) && is_readable($path);
    }

    public function getMasterKey(): string
    {
        $path = $this->getKeyPath();

        if ($path === '') {
            throw MasterKeyException::notFound('No path configured');
        }

        if (!file_exists($path)) {
            // Try auto-generated key path for development
            $autoPath = $this->configuration->getAutoKeyPath();
            if (file_exists($autoPath) && is_readable($autoPath)) {
                $path = $autoPath;
            } else {
                throw MasterKeyException::notFound($path);
            }
        }

        if (!is_readable($path)) {
            throw MasterKeyException::notFound($path . ' (not readable)');
        }

        $key = file_get_contents($path);
        if ($key === false) {
            throw MasterKeyException::notFound($path);
        }

        // Handle base64-encoded keys
        if (\strlen($key) !== self::KEY_LENGTH) {
            $decoded = base64_decode($key, true);
            if ($decoded !== false && \strlen($decoded) === self::KEY_LENGTH) {
                $key = $decoded;
            }
        }

        // Trim any whitespace (newlines from file)
        $key = trim($key);

        if (\strlen($key) !== self::KEY_LENGTH) {
            throw MasterKeyException::invalidLength(self::KEY_LENGTH, \strlen($key));
        }

        return $key;
    }

    public function storeMasterKey(string $key): void
    {
        if (\strlen($key) !== self::KEY_LENGTH) {
            throw MasterKeyException::invalidLength(self::KEY_LENGTH, \strlen($key));
        }

        $path = $this->getKeyPath();
        if ($path === '') {
            $path = $this->configuration->getAutoKeyPath();
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && (!mkdir($dir, 0o700, true) && !is_dir($dir))) {
            throw MasterKeyException::cannotStore("Cannot create directory: {$dir}");
        }

        // Store as base64 for easier handling
        $result = file_put_contents($path, base64_encode($key));
        if ($result === false) {
            throw MasterKeyException::cannotStore("Cannot write to: {$path}");
        }

        // Secure the file permissions
        chmod($path, 0o400);
    }

    public function generateMasterKey(): string
    {
        return random_bytes(self::KEY_LENGTH);
    }

    private function getKeyPath(): string
    {
        $source = $this->configuration->getMasterKeySource();
        // For file provider, source is the file path
        if ($source !== '' && $source !== ExtensionConfiguration::DEFAULT_MASTER_KEY_SOURCE) {
            return $source;
        }

        return '';
    }
}
