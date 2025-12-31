<?php

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Fuzz;

use Netresearch\NrVault\Utility\FlexFormVaultResolver;
use Netresearch\NrVault\Utility\IdentifierValidator;
use Netresearch\NrVault\Utility\VaultFieldResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Fuzz tests for identifier validation and parsing.
 *
 * These tests ensure that malicious, malformed, or edge case inputs
 * are handled gracefully without crashes or security vulnerabilities.
 */
#[CoversClass(VaultFieldResolver::class)]
#[CoversClass(FlexFormVaultResolver::class)]
#[CoversClass(IdentifierValidator::class)]
final class IdentifierFuzzTest extends TestCase
{
    /**
     * Test VaultFieldResolver with fuzzed inputs.
     *
     * Validates that isVaultIdentifier never throws and returns boolean.
     * Note: parseIdentifier may return null even if isVaultIdentifier returns true
     * (regex matches format but parsing may fail due to structure issues).
     */
    #[Test]
    #[DataProvider('fuzzedIdentifierProvider')]
    public function vaultFieldResolverHandlesFuzzedInput(mixed $input): void
    {
        // Should not throw exceptions
        $result = VaultFieldResolver::isVaultIdentifier($input);
        self::assertIsBool($result);

        // If it passes validation, parseIdentifier should be called but may return null
        if ($result && \is_string($input)) {
            $parsed = VaultFieldResolver::parseIdentifier($input);
            // parseIdentifier returns array or null - either is acceptable
            self::assertTrue($parsed === null || \is_array($parsed));
        }
    }

    /**
     * Test FlexFormVaultResolver with fuzzed inputs.
     *
     * Validates that isFlexFormVaultIdentifier never throws and returns boolean.
     */
    #[Test]
    #[DataProvider('fuzzedIdentifierProvider')]
    public function flexFormVaultResolverHandlesFuzzedInput(mixed $input): void
    {
        $result = FlexFormVaultResolver::isFlexFormVaultIdentifier($input);
        self::assertIsBool($result);

        if ($result && \is_string($input)) {
            $parsed = FlexFormVaultResolver::parseIdentifier($input);
            // parseIdentifier returns array or null - either is acceptable
            self::assertTrue($parsed === null || \is_array($parsed));
        }
    }

    /**
     * Test IdentifierValidator with fuzzed inputs.
     *
     * For non-string inputs, we verify the method handles them gracefully.
     */
    #[Test]
    #[DataProvider('fuzzedIdentifierProvider')]
    public function identifierValidatorHandlesFuzzedInput(mixed $input): void
    {
        // Should not throw exceptions for any input
        if (\is_string($input)) {
            $result = IdentifierValidator::isValid($input);
            self::assertIsBool($result);
        } else {
            // Non-string inputs: verify no exception is thrown by other methods
            self::assertFalse(VaultFieldResolver::isVaultIdentifier($input));
        }
    }

    /**
     * Test buildIdentifier with fuzzed table/field names.
     */
    #[Test]
    #[DataProvider('fuzzedComponentProvider')]
    public function buildIdentifierHandlesFuzzedComponents(
        string $table,
        string $field,
        int $uid,
    ): void {
        $identifier = VaultFieldResolver::buildIdentifier($table, $field, $uid);

        // Should return a string
        self::assertIsString($identifier);

        // Should contain the components
        self::assertStringContainsString((string) $uid, $identifier);
    }

    /**
     * Test FlexForm buildIdentifier with fuzzed components.
     */
    #[Test]
    #[DataProvider('fuzzedFlexFormComponentProvider')]
    public function flexFormBuildIdentifierHandlesFuzzedComponents(
        string $table,
        string $flexField,
        string $sheet,
        string $fieldPath,
        int $uid,
    ): void {
        $identifier = FlexFormVaultResolver::buildIdentifier(
            $table,
            $flexField,
            $sheet,
            $fieldPath,
            $uid,
        );

        self::assertIsString($identifier);
        self::assertStringContainsString((string) $uid, $identifier);
    }

    /**
     * Provide fuzzed identifier values for testing.
     */
    public static function fuzzedIdentifierProvider(): array
    {
        return [
            // Null and empty
            'null' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'tab and newline' => ["\t\n\r"],

            // Type confusion
            'integer zero' => [0],
            'integer positive' => [12345],
            'integer negative' => [-1],
            'float' => [3.14159],
            'boolean true' => [true],
            'boolean false' => [false],
            'empty array' => [[]],
            'array with values' => [['a', 'b', 'c']],
            'nested array' => [['a' => ['b' => 'c']]],
            'object' => [new stdClass()],

            // Injection attempts
            'sql injection single quote' => ["table__field__1'; DROP TABLE users; --"],
            'sql injection double quote' => ['table__field__1" OR "1"="1'],
            'sql injection union' => ['table__field__1 UNION SELECT * FROM users'],
            'sql injection comment' => ['table__field__/**/1'],

            // XSS attempts
            'xss script tag' => ['<script>alert("xss")</script>__field__1'],
            'xss img onerror' => ['<img src=x onerror=alert(1)>__field__1'],
            'xss event handler' => ['table__onload=alert(1)__1'],

            // Path traversal
            'path traversal dots' => ['../../../etc/passwd__field__1'],
            'path traversal encoded' => ['..%2F..%2F..%2Fetc%2Fpasswd__field__1'],
            'path traversal null' => ['table__field__1\x00.txt'],

            // Unicode and encoding
            'unicode null' => ["table__field__1\u0000"],
            'unicode bom' => ["\xEF\xBB\xBFtable__field__1"],
            'unicode rtl override' => ["table__field__1\u202E"],
            'unicode homoglyph' => ['tаble__field__1'], // Cyrillic 'а'
            'unicode emoji' => ['table__field__1🔥'],
            'unicode high codepoint' => ["table__field__\u{10FFFF}"],

            // Boundary values
            'max int as string' => ['table__field__9223372036854775807'],
            'overflow int' => ['table__field__99999999999999999999999999999'],
            'negative uid' => ['table__field__-1'],
            'zero uid' => ['table__field__0'],

            // Format edge cases
            'single underscore' => ['table_field_1'],
            'triple underscore' => ['table___field___1'],
            'quadruple underscore' => ['table____field____1'],
            'leading underscore' => ['__table__field__1'],
            'trailing underscore' => ['table__field__1__'],
            'only separators' => ['________'],

            // Long strings
            'very long table name' => [str_repeat('a', 1000) . '__field__1'],
            'very long field name' => ['table__' . str_repeat('b', 1000) . '__1'],
            'very long overall' => [str_repeat('x__', 10000) . '1'],

            // Special characters
            'newline in middle' => ["table__field\n__1"],
            'carriage return' => ["table__field\r__1"],
            'null byte' => ["table__field\x00__1"],
            'backslash' => ['table\\__field\\__1'],
            'forward slash' => ['table/__field/__1'],
            'angle brackets' => ['<table>__<field>__1'],
            'ampersand' => ['table&field__test__1'],
            'percent' => ['table%20field__test__1'],
            'hash' => ['table#field__test__1'],

            // Valid-looking but invalid
            'valid format non-numeric uid' => ['tx_ext__field__abc'],
            'valid format float uid' => ['tx_ext__field__1.5'],
            'valid format with spaces' => ['tx ext__field__1'],
            'flexform format 3 parts' => ['table__field__1'], // 3 parts is TCA, not FlexForm

            // Actually valid identifiers
            'valid simple' => ['tx_ext__field__1'],
            'valid with underscores' => ['tx_my_ext__api_key__42'],
            'valid flexform' => ['tt_content__pi_flexform__settings__apiKey__123'],
        ];
    }

    /**
     * Provide fuzzed component values for buildIdentifier.
     */
    public static function fuzzedComponentProvider(): array
    {
        return [
            'normal values' => ['tx_test', 'api_key', 42],
            'empty table' => ['', 'field', 1],
            'empty field' => ['table', '', 1],
            'zero uid' => ['table', 'field', 0],
            'large uid' => ['table', 'field', PHP_INT_MAX],
            'special chars table' => ['tx-test.table', 'field', 1],
            'special chars field' => ['table', 'api-key.name', 1],
            'unicode table' => ['tаble', 'field', 1], // Cyrillic 'а'
            'long table' => [str_repeat('a', 100), 'field', 1],
            'long field' => ['table', str_repeat('b', 100), 1],
        ];
    }

    /**
     * Provide fuzzed FlexForm component values.
     */
    public static function fuzzedFlexFormComponentProvider(): array
    {
        return [
            'normal values' => ['tt_content', 'pi_flexform', 'settings', 'apiKey', 123],
            'empty components' => ['', '', '', '', 0],
            'dots in path' => ['table', 'flex', 'sheet', 'nested.field', 1],
            'slashes in path' => ['table', 'flex', 'sheet', 'path/to/field', 1],
            'special chars' => ['tt-content', 'pi_flex', 'my-sheet', 'api.key', 1],
            'long path' => ['t', 'f', 's', str_repeat('field/', 50), 1],
        ];
    }

    /**
     * Test that identifier parsing never accepts injection patterns.
     */
    #[Test]
    #[DataProvider('injectionPatternProvider')]
    public function parsingRejectsInjectionPatterns(string $input): void
    {
        // These should all be rejected
        self::assertFalse(VaultFieldResolver::isVaultIdentifier($input));
        self::assertFalse(FlexFormVaultResolver::isFlexFormVaultIdentifier($input));
    }

    public static function injectionPatternProvider(): array
    {
        return [
            'sql comment' => ['table__field__1--'],
            'sql semicolon' => ['table__field__1;'],
            'sql or' => ['table__field__1 OR 1=1'],
            'shell command' => ['table__field__1; rm -rf /'],
            'shell backtick' => ['table__field__`whoami`'],
            'shell dollar' => ['table__field__$(whoami)'],
            'ldap injection' => ['table__field__1)(cn=*)'],
            'xpath injection' => ['table__field__1\' or \'1\'=\'1'],
            'xml entity' => ['table__field__1<!ENTITY xxe SYSTEM "file:///etc/passwd">'],
            'template injection' => ['table__field__{{7*7}}'],
            'ssti jinja' => ['table__field__{{ config.items() }}'],
        ];
    }

    /**
     * Test resilience to resource exhaustion attempts.
     */
    #[Test]
    public function handlesExtremelyLongInputWithoutMemoryExhaustion(): void
    {
        // 10MB string - should not cause memory issues
        $longString = str_repeat('a', 10 * 1024 * 1024);

        $startMemory = memory_get_usage();

        VaultFieldResolver::isVaultIdentifier($longString);
        FlexFormVaultResolver::isFlexFormVaultIdentifier($longString);

        $endMemory = memory_get_usage();

        // Memory increase should be reasonable (less than 50MB additional)
        self::assertLessThan(50 * 1024 * 1024, $endMemory - $startMemory);
    }

    /**
     * Test that the same input always produces the same result (determinism).
     */
    #[Test]
    public function validationIsDeterministic(): void
    {
        $inputs = [
            'tx_ext__field__1',
            'invalid',
            '',
            'tt_content__pi_flexform__settings__apiKey__123',
        ];

        foreach ($inputs as $input) {
            $results = [];
            for ($i = 0; $i < 100; $i++) {
                $results[] = [
                    VaultFieldResolver::isVaultIdentifier($input),
                    FlexFormVaultResolver::isFlexFormVaultIdentifier($input),
                ];
            }

            // All results should be identical
            self::assertCount(1, array_unique($results, SORT_REGULAR));
        }
    }
}
