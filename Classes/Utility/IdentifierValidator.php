<?php

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

    private const string PATTERN = '/^[a-zA-Z]\w*$/';

    /**
     * Validate a secret identifier.
     *
     * @throws ValidationException If identifier is invalid
     */
    public static function validate(string $identifier): void
    {
        if ($identifier === '') {
            throw ValidationException::invalidIdentifier($identifier, 'cannot be empty');
        }

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

        if (!preg_match(self::PATTERN, $identifier)) {
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
        $identifier = preg_replace('/[^a-z0-9_]/', '_', $identifier);

        // Ensure starts with letter
        if ($identifier !== '' && !ctype_alpha($identifier[0])) {
            $identifier = 'secret_' . $identifier;
        }

        // Remove consecutive underscores
        $identifier = preg_replace('/_+/', '_', $identifier);

        // Trim underscores
        $identifier = trim((string) $identifier, '_');

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
