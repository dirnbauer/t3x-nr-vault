<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Utility;

use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Utility\IdentifierValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifierValidator::class)]
final class IdentifierValidatorTest extends TestCase
{
    #[Test]
    public function validateAcceptsValidIdentifier(): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierValidator::validate('myApiKey');
    }

    #[Test]
    public function validateAcceptsIdentifierWithUnderscores(): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierValidator::validate('my_api_key');
    }

    #[Test]
    public function validateAcceptsIdentifierWithNumbers(): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierValidator::validate('apiKey123');
    }

    #[Test]
    public function validateRejectsEmptyIdentifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('empty');

        IdentifierValidator::validate('');
    }

    #[Test]
    public function validateRejectsTooShortIdentifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least');

        IdentifierValidator::validate('ab');
    }

    #[Test]
    public function validateRejectsTooLongIdentifier(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceed');

        IdentifierValidator::validate(\str_repeat('a', 256));
    }

    #[Test]
    public function validateRejectsIdentifierStartingWithNumber(): void
    {
        $this->expectException(ValidationException::class);

        IdentifierValidator::validate('123apiKey');
    }

    #[Test]
    #[DataProvider('invalidCharactersProvider')]
    public function validateRejectsInvalidCharacters(string $identifier): void
    {
        $this->expectException(ValidationException::class);

        IdentifierValidator::validate($identifier);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCharactersProvider(): array
    {
        return [
            'hyphen' => ['api-key'],
            'space' => ['api key'],
            'dot' => ['api.key'],
            'special chars' => ['api@key'],
            'unicode' => ['apiKeyäöü'],
            'slash' => ['api/key'],
        ];
    }

    #[Test]
    public function isValidReturnsTrueForValidIdentifier(): void
    {
        self::assertTrue(IdentifierValidator::isValid('myValidKey'));
    }

    #[Test]
    public function isValidReturnsFalseForInvalidIdentifier(): void
    {
        self::assertFalse(IdentifierValidator::isValid(''));
        self::assertFalse(IdentifierValidator::isValid('ab'));
        self::assertFalse(IdentifierValidator::isValid('123key'));
        self::assertFalse(IdentifierValidator::isValid('key-with-dashes'));
    }

    #[Test]
    public function sanitizeRemovesInvalidCharacters(): void
    {
        $result = IdentifierValidator::sanitize('api-key.test@123');

        self::assertEquals('api_key_test_123', $result);
    }

    #[Test]
    public function sanitizePrependsPrefixIfStartsWithNumber(): void
    {
        $result = IdentifierValidator::sanitize('123apiKey');

        // Implementation converts to lowercase and prepends 'secret_' for numbers
        self::assertEquals('secret_123apikey', $result);
    }

    #[Test]
    public function sanitizeTruncatesToMaxLength(): void
    {
        $longIdentifier = \str_repeat('a', 300);
        $result = IdentifierValidator::sanitize($longIdentifier);

        self::assertEquals(255, \strlen($result));
    }

    #[Test]
    public function validateAcceptsMinimumLengthIdentifier(): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierValidator::validate('abc'); // Exactly 3 characters
    }

    #[Test]
    public function validateAcceptsMaximumLengthIdentifier(): void
    {
        $this->expectNotToPerformAssertions();

        IdentifierValidator::validate('a' . \str_repeat('b', 254)); // Exactly 255 characters
    }
}
