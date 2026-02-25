<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Utility;

use Netresearch\NrVault\Exception\ValidationException;

/**
 * Validates secret identifiers.
 */
final class IdentifierValidator
{
    private const int MIN_LENGTH = 3;

    private const int MAX_LENGTH = 255;

    /** User-friendly identifier pattern (e.g., my_api_key). */
    private const string USER_PATTERN = '/^[a-zA-Z]\w*$/';

    /** UUID v7 pattern for TCA/FlexForm vault field identifiers. */
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Validate a secret identifier.
     *
     * Accepts two formats:
     * - User-friendly: starts with letter, contains letters/numbers/underscores (e.g., my_api_key)
     * - UUID v7: standard UUID v7 format used by TCA/FlexForm vault fields
     *
     * @throws ValidationException If identifier is invalid
     */
    public static function validate(string $identifier): void
    {
        if ($identifier === '') {
            throw ValidationException::invalidIdentifier($identifier, 'cannot be empty');
        }

        // Check if it's a valid UUID v7 (used by TCA/FlexForm vault fields)
        if (preg_match(self::UUID_PATTERN, $identifier)) {
            return;
        }

        // Otherwise validate as user-friendly identifier
        $length = \strlen($identifier);
        if ($length < self::MIN_LENGTH) {
            throw ValidationException::invalidIdentifier(
                $identifier,
                \sprintf('must be at least %d characters', self::MIN_LENGTH),
            );
        }

        if ($length > self::MAX_LENGTH) {
            throw ValidationException::invalidIdentifier(
                $identifier,
                \sprintf('cannot exceed %d characters', self::MAX_LENGTH),
            );
        }

        if (!preg_match(self::USER_PATTERN, $identifier)) {
            throw ValidationException::invalidIdentifier(
                $identifier,
                'must start with a letter and contain only letters, numbers, and underscores',
            );
        }
    }

    /**
     * Check if identifier is valid without throwing.
     */
    public static function isValid(string $identifier): bool
    {
        try {
            self::validate($identifier);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    /**
     * Sanitize a string to create a valid identifier.
     */
    public static function sanitize(string $input): string
    {
        // Convert to lowercase
        $identifier = strtolower($input);

        // Replace invalid characters with underscores
        $replaced = preg_replace('/[^a-z0-9_]/', '_', $identifier);
        $identifier = \is_string($replaced) ? $replaced : $identifier;

        // Ensure starts with letter
        if ($identifier !== '' && !ctype_alpha($identifier[0])) {
            $identifier = 'secret_' . $identifier;
        }

        // Remove consecutive underscores
        $replaced = preg_replace('/_+/', '_', $identifier);
        $identifier = \is_string($replaced) ? $replaced : $identifier;

        // Trim underscores
        $identifier = trim($identifier, '_');

        // Enforce length limits
        if (\strlen($identifier) < self::MIN_LENGTH) {
            $identifier = str_pad($identifier, self::MIN_LENGTH, '_');
        }

        if (\strlen($identifier) > self::MAX_LENGTH) {
            return substr($identifier, 0, self::MAX_LENGTH);
        }

        return $identifier;
    }
}
