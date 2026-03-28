<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Audit;

use Netresearch\NrVault\Audit\HashChainVerificationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HashChainVerificationResult::class)]
final class HashChainVerificationResultTest extends TestCase
{
    #[Test]
    public function constructorSetsValidAndErrors(): void
    {
        $errors = [1 => 'Hash mismatch', 5 => 'Missing entry'];
        $subject = new HashChainVerificationResult(valid: false, errors: $errors);

        self::assertFalse($subject->valid);
        self::assertSame($errors, $subject->errors);
    }

    #[Test]
    public function constructorErrorsDefaultToEmptyArray(): void
    {
        $subject = new HashChainVerificationResult(valid: true);

        self::assertSame([], $subject->errors);
    }

    #[Test]
    public function validFactoryCreatesSuccessfulResult(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertTrue($subject->valid);
        self::assertSame([], $subject->errors);
    }

    #[Test]
    public function invalidFactoryCreatesFailedResultWithErrors(): void
    {
        $errors = [3 => 'Chain broken', 7 => 'Invalid hash'];
        $subject = HashChainVerificationResult::invalid($errors);

        self::assertFalse($subject->valid);
        self::assertSame($errors, $subject->errors);
    }

    #[Test]
    public function invalidFactoryWithEmptyErrorsCreatesInvalidResult(): void
    {
        // invalid() can be called with an empty array (unusual but valid)
        $subject = HashChainVerificationResult::invalid([]);

        self::assertFalse($subject->valid);
        self::assertSame([], $subject->errors);
    }

    #[Test]
    public function isValidReturnsTrueForValidResult(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertTrue($subject->isValid());
    }

    #[Test]
    public function isValidReturnsFalseForInvalidResult(): void
    {
        $subject = HashChainVerificationResult::invalid([1 => 'error']);

        self::assertFalse($subject->isValid());
    }

    #[Test]
    public function isValidMirrorsValidProperty(): void
    {
        $trueResult = new HashChainVerificationResult(valid: true);
        $falseResult = new HashChainVerificationResult(valid: false);

        self::assertSame($trueResult->valid, $trueResult->isValid());
        self::assertSame($falseResult->valid, $falseResult->isValid());
    }

    #[Test]
    public function getErrorCountReturnsZeroForValidResult(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertSame(0, $subject->getErrorCount());
    }

    #[Test]
    public function getErrorCountReturnsCorrectCountForInvalidResult(): void
    {
        $errors = [1 => 'err1', 5 => 'err2', 9 => 'err3'];
        $subject = HashChainVerificationResult::invalid($errors);

        self::assertSame(3, $subject->getErrorCount());
    }

    #[Test]
    public function getErrorCountReturnsOneForSingleError(): void
    {
        $subject = HashChainVerificationResult::invalid([42 => 'hash mismatch']);

        self::assertSame(1, $subject->getErrorCount());
    }

    #[Test]
    public function toArrayReturnsCorrectStructureForValidResult(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertSame([
            'valid' => true,
            'errors' => [],
            'warnings' => [],
        ], $subject->toArray());
    }

    #[Test]
    public function toArrayReturnsCorrectStructureForInvalidResult(): void
    {
        $errors = [2 => 'Chain broken at entry 2', 8 => 'Entry 8 missing'];
        $subject = HashChainVerificationResult::invalid($errors);

        self::assertSame([
            'valid' => false,
            'errors' => $errors,
            'warnings' => [],
        ], $subject->toArray());
    }

    #[Test]
    public function toArrayContainsExactlyThreeKeys(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertCount(3, $subject->toArray());
    }

    #[Test]
    public function constructorWarningsDefaultToEmptyArray(): void
    {
        $subject = new HashChainVerificationResult(valid: true);

        self::assertSame([], $subject->warnings);
    }

    #[Test]
    public function validFactoryAcceptsWarnings(): void
    {
        $warnings = [5 => 'HMAC key epoch boundary: 0 -> 1'];
        $subject = HashChainVerificationResult::valid($warnings);

        self::assertTrue($subject->valid);
        self::assertSame([], $subject->errors);
        self::assertSame($warnings, $subject->warnings);
    }

    #[Test]
    public function invalidFactoryAcceptsWarnings(): void
    {
        $errors = [3 => 'Chain broken'];
        $warnings = [2 => 'Epoch boundary'];
        $subject = HashChainVerificationResult::invalid($errors, $warnings);

        self::assertFalse($subject->valid);
        self::assertSame($errors, $subject->errors);
        self::assertSame($warnings, $subject->warnings);
    }

    #[Test]
    public function getWarningCountReturnsZeroForNoWarnings(): void
    {
        $subject = HashChainVerificationResult::valid();

        self::assertSame(0, $subject->getWarningCount());
    }

    #[Test]
    public function getWarningCountReturnsCorrectCount(): void
    {
        $warnings = [1 => 'warn1', 5 => 'warn2'];
        $subject = HashChainVerificationResult::valid($warnings);

        self::assertSame(2, $subject->getWarningCount());
    }

    #[Test]
    public function toArrayIncludesWarnings(): void
    {
        $warnings = [10 => 'epoch change'];
        $subject = HashChainVerificationResult::valid($warnings);

        $array = $subject->toArray();
        self::assertSame($warnings, $array['warnings']);
    }
}
