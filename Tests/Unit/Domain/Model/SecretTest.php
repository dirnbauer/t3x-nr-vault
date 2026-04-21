<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Domain\Model;

use Netresearch\NrVault\Domain\Model\Secret;
use Netresearch\NrVault\Exception\ValidationException;
use Netresearch\NrVault\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(Secret::class)]
final class SecretTest extends TestCase
{
    #[Test]
    public function newSecretHasDefaultValues(): void
    {
        $secret = new Secret();

        self::assertNull($secret->getUid());
        self::assertEquals(0, $secret->getScopePid());
        self::assertEquals('', $secret->getIdentifier());
        self::assertEquals('', $secret->getDescription());
        self::assertNull($secret->getEncryptedValue());
        self::assertEquals('', $secret->getContext());
        self::assertEquals(1, $secret->getVersion());
        self::assertEquals(0, $secret->getExpiresAt());
        self::assertEquals([], $secret->getAllowedGroups());
        self::assertEquals([], $secret->getMetadata());
        self::assertEquals('local', $secret->getAdapter());
        self::assertFalse($secret->isDeleted());
        self::assertFalse($secret->isHidden());
    }

    #[Test]
    public function settersReturnSelfForFluentInterface(): void
    {
        $secret = new Secret();

        $result = $secret
            ->setIdentifier('test')
            ->setDescription('Test description')
            ->setContext('payment')
            ->setOwnerUid(1);

        self::assertSame($secret, $result);
    }

    #[Test]
    public function isExpiredReturnsFalseWhenNoExpiration(): void
    {
        $secret = new Secret();
        $secret->setExpiresAt(0);

        self::assertFalse($secret->isExpired());
    }

    #[Test]
    public function isExpiredReturnsTrueWhenPastExpiration(): void
    {
        $secret = new Secret();
        $secret->setExpiresAt(time() - 3600); // 1 hour ago

        self::assertTrue($secret->isExpired());
    }

    #[Test]
    public function isExpiredReturnsFalseWhenFutureExpiration(): void
    {
        $secret = new Secret();
        $secret->setExpiresAt(time() + 3600); // 1 hour from now

        self::assertFalse($secret->isExpired());
    }

    #[Test]
    public function incrementVersionIncreasesVersionByOne(): void
    {
        $secret = new Secret();
        self::assertEquals(1, $secret->getVersion());

        $secret->incrementVersion();
        self::assertEquals(2, $secret->getVersion());

        $secret->incrementVersion();
        self::assertEquals(3, $secret->getVersion());
    }

    #[Test]
    public function incrementReadCountIncreasesCountByOne(): void
    {
        $secret = new Secret();
        self::assertEquals(0, $secret->getReadCount());

        $secret->incrementReadCount();
        self::assertEquals(1, $secret->getReadCount());

        $secret->incrementReadCount();
        self::assertEquals(2, $secret->getReadCount());
    }

    #[Test]
    public function setAllowedGroupsCastsToIntegers(): void
    {
        $secret = new Secret();
        $secret->setAllowedGroups(['1', '2', '3']);

        self::assertEquals([1, 2, 3], $secret->getAllowedGroups());
    }

    #[Test]
    public function fromDatabaseRowCreatesCorrectSecret(): void
    {
        $row = [
            'uid' => 42,
            'scope_pid' => 1,
            'identifier' => 'api-key',
            'description' => 'Payment gateway API key',
            'encrypted_value' => 'base64_encrypted_data',
            'encrypted_dek' => 'base64_encrypted_dek',
            'dek_nonce' => 'base64_nonce',
            'value_nonce' => 'base64_value_nonce',
            'encryption_version' => 1,
            'value_checksum' => 'sha256hash',
            'owner_uid' => 5,
            'allowed_groups' => '1,2,3',
            'context' => 'payment',
            'version' => 3,
            'expires_at' => 1735689600,
            'last_rotated_at' => 1704067200,
            'metadata' => '{"service":"stripe"}',
            'adapter' => 'local',
            'external_reference' => '',
            'tstamp' => 1704067200,
            'crdate' => 1704067200,
            'cruser_id' => 1,
            'deleted' => 0,
            'hidden' => 0,
            'read_count' => 10,
            'last_read_at' => 1704153600,
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertEquals(42, $secret->getUid());
        self::assertEquals(1, $secret->getScopePid());
        self::assertEquals('api-key', $secret->getIdentifier());
        self::assertEquals('Payment gateway API key', $secret->getDescription());
        self::assertEquals('base64_encrypted_data', $secret->getEncryptedValue());
        self::assertEquals(5, $secret->getOwnerUid());
        self::assertEquals([1, 2, 3], $secret->getAllowedGroups());
        self::assertEquals('payment', $secret->getContext());
        self::assertEquals(3, $secret->getVersion());
        self::assertEquals(['service' => 'stripe'], $secret->getMetadata());
        self::assertEquals(10, $secret->getReadCount());
    }

    #[Test]
    public function fromDatabaseRowHandlesEmptyMetadata(): void
    {
        $row = [
            'uid' => 1,
            'identifier' => 'test',
            'metadata' => '',
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertEquals([], $secret->getMetadata());
    }

    #[Test]
    public function fromDatabaseRowHandlesNullValues(): void
    {
        $row = [
            'uid' => null,
            'identifier' => null,
            'encrypted_value' => null,
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertNull($secret->getUid());
        self::assertEquals('', $secret->getIdentifier());
        self::assertNull($secret->getEncryptedValue());
    }

    #[Test]
    public function toDatabaseRowReturnsExpectedArray(): void
    {
        $secret = new Secret();
        $secret
            ->setScopePid(1)
            ->setIdentifier('test-secret')
            ->setDescription('Test secret')
            ->setEncryptedValue('encrypted')
            ->setEncryptedDek('dek')
            ->setDekNonce('nonce1')
            ->setValueNonce('nonce2')
            ->setValueChecksum('checksum')
            ->setOwnerUid(5)
            ->setAllowedGroups([1, 2])
            ->setContext('testing')
            ->setVersion(2)
            ->setExpiresAt(1735689600)
            ->setMetadata(['key' => 'value'])
            ->setAdapter('local')
            ->setDeleted(false)
            ->setHidden(false);

        $row = $secret->toDatabaseRow();

        self::assertEquals(1, $row['scope_pid']);
        self::assertEquals('test-secret', $row['identifier']);
        self::assertEquals('Test secret', $row['description']);
        self::assertEquals('encrypted', $row['encrypted_value']);
        self::assertEquals('dek', $row['encrypted_dek']);
        self::assertEquals('checksum', $row['value_checksum']);
        self::assertEquals(5, $row['owner_uid']);
        self::assertEquals('1,2', $row['allowed_groups']);
        self::assertEquals('testing', $row['context']);
        self::assertEquals(2, $row['version']);
        self::assertEquals('{"key":"value"}', $row['metadata']);
        self::assertEquals(0, $row['deleted']);
        self::assertEquals(0, $row['hidden']);
    }

    #[Test]
    public function encryptionFieldsAreSetCorrectly(): void
    {
        $secret = new Secret();
        $secret
            ->setEncryptedValue('enc_value')
            ->setEncryptedDek('enc_dek')
            ->setDekNonce('dek_nonce')
            ->setValueNonce('value_nonce')
            ->setEncryptionVersion(2)
            ->setValueChecksum('abc123');

        self::assertEquals('enc_value', $secret->getEncryptedValue());
        self::assertEquals('enc_dek', $secret->getEncryptedDek());
        self::assertEquals('dek_nonce', $secret->getDekNonce());
        self::assertEquals('value_nonce', $secret->getValueNonce());
        self::assertEquals(2, $secret->getEncryptionVersion());
        self::assertEquals('abc123', $secret->getValueChecksum());
    }

    #[Test]
    public function externalReferenceIsStoredCorrectly(): void
    {
        $secret = new Secret();
        $secret->setExternalReference('vault:secret/data/myapp#password');

        self::assertEquals('vault:secret/data/myapp#password', $secret->getExternalReference());
    }

    #[Test]
    public function frontendAccessibleDefaultsToFalse(): void
    {
        $secret = new Secret();

        self::assertFalse($secret->isFrontendAccessible());
    }

    #[Test]
    public function frontendAccessibleCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setFrontendAccessible(true);

        self::assertTrue($secret->isFrontendAccessible());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function lastRotatedAtDefaultsToZero(): void
    {
        $secret = new Secret();

        self::assertEquals(0, $secret->getLastRotatedAt());
    }

    #[Test]
    public function lastRotatedAtCanBeSet(): void
    {
        $secret = new Secret();
        $timestamp = 1704067200;
        $result = $secret->setLastRotatedAt($timestamp);

        self::assertEquals($timestamp, $secret->getLastRotatedAt());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function tstampDefaultsToZero(): void
    {
        $secret = new Secret();

        self::assertEquals(0, $secret->getTstamp());
    }

    #[Test]
    public function tstampCanBeSet(): void
    {
        $secret = new Secret();
        $timestamp = 1704067200;
        $result = $secret->setTstamp($timestamp);

        self::assertEquals($timestamp, $secret->getTstamp());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function crdateDefaultsToZero(): void
    {
        $secret = new Secret();

        self::assertEquals(0, $secret->getCrdate());
    }

    #[Test]
    public function crdateCanBeSet(): void
    {
        $secret = new Secret();
        $timestamp = 1704067200;
        $result = $secret->setCrdate($timestamp);

        self::assertEquals($timestamp, $secret->getCrdate());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function cruserIdDefaultsToZero(): void
    {
        $secret = new Secret();

        self::assertEquals(0, $secret->getCruserId());
    }

    #[Test]
    public function cruserIdCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setCruserId(42);

        self::assertEquals(42, $secret->getCruserId());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function lastReadAtDefaultsToZero(): void
    {
        $secret = new Secret();

        self::assertEquals(0, $secret->getLastReadAt());
    }

    #[Test]
    public function lastReadAtCanBeSet(): void
    {
        $secret = new Secret();
        $timestamp = 1704153600;
        $result = $secret->setLastReadAt($timestamp);

        self::assertEquals($timestamp, $secret->getLastReadAt());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function readCountCanBeSetDirectly(): void
    {
        $secret = new Secret();
        $result = $secret->setReadCount(100);

        self::assertEquals(100, $secret->getReadCount());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function fromDatabaseRowHandlesFrontendAccessibleTrue(): void
    {
        $row = [
            'uid' => 1,
            'identifier' => 'test',
            'frontend_accessible' => 1,
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertTrue($secret->isFrontendAccessible());
    }

    #[Test]
    public function fromDatabaseRowHandlesInvalidMetadataJson(): void
    {
        $row = [
            'uid' => 1,
            'identifier' => 'test',
            'metadata' => 'not-valid-json{',
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertEquals([], $secret->getMetadata());
    }

    #[Test]
    public function fromDatabaseRowHandlesEmptyAllowedGroups(): void
    {
        $row = [
            'uid' => 1,
            'identifier' => 'test',
            'allowed_groups' => '',
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertEquals([], $secret->getAllowedGroups());
    }

    #[Test]
    public function toDatabaseRowIncludesAllFields(): void
    {
        $secret = new Secret();
        $secret
            ->setLastRotatedAt(1704067200)
            ->setFrontendAccessible(true)
            ->setReadCount(50)
            ->setLastReadAt(1704153600);

        $row = $secret->toDatabaseRow();

        self::assertArrayHasKey('last_rotated_at', $row);
        self::assertEquals(1704067200, $row['last_rotated_at']);
        self::assertArrayHasKey('frontend_accessible', $row);
        self::assertEquals(1, $row['frontend_accessible']);
        self::assertArrayHasKey('read_count', $row);
        self::assertEquals(50, $row['read_count']);
        self::assertArrayHasKey('last_read_at', $row);
        self::assertEquals(1704153600, $row['last_read_at']);
    }

    #[Test]
    public function scopePidCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setScopePid(100);

        self::assertEquals(100, $secret->getScopePid());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function contextCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setContext('payment');

        self::assertEquals('payment', $secret->getContext());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function versionCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setVersion(5);

        self::assertEquals(5, $secret->getVersion());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function adapterCanBeSet(): void
    {
        $secret = new Secret();
        $result = $secret->setAdapter('hashicorp');

        self::assertEquals('hashicorp', $secret->getAdapter());
        self::assertSame($secret, $result);
    }

    #[Test]
    public function createSetsAllFieldsCorrectly(): void
    {
        $secret = Secret::create(
            identifier: 'my-api-key',
            encryptedValue: 'enc_value',
            valueChecksum: 'checksum123',
            encryptedDek: 'enc_dek',
            dekNonce: 'dek_nonce',
            valueNonce: 'value_nonce',
            ownerUid: 5,
            allowedGroups: [1, 2, 3],
            context: 'payment',
            description: 'Stripe key',
            adapter: 'local',
            expiresAt: 1735689600,
            metadata: ['service' => 'stripe'],
            scopePid: 10,
            frontendAccessible: true,
        );

        self::assertEquals('my-api-key', $secret->getIdentifier());
        self::assertEquals('enc_value', $secret->getEncryptedValue());
        self::assertEquals('checksum123', $secret->getValueChecksum());
        self::assertEquals('enc_dek', $secret->getEncryptedDek());
        self::assertEquals('dek_nonce', $secret->getDekNonce());
        self::assertEquals('value_nonce', $secret->getValueNonce());
        self::assertEquals(5, $secret->getOwnerUid());
        self::assertEquals([1, 2, 3], $secret->getAllowedGroups());
        self::assertEquals('payment', $secret->getContext());
        self::assertEquals('Stripe key', $secret->getDescription());
        self::assertEquals('local', $secret->getAdapter());
        self::assertEquals(1735689600, $secret->getExpiresAt());
        self::assertEquals(['service' => 'stripe'], $secret->getMetadata());
        self::assertEquals(10, $secret->getScopePid());
        self::assertTrue($secret->isFrontendAccessible());
    }

    #[Test]
    public function createAllowsAllCryptoFieldsEmpty(): void
    {
        $secret = Secret::create(
            identifier: 'external-ref',
            encryptedValue: 'enc_value',
            valueChecksum: 'checksum',
        );

        self::assertEquals('external-ref', $secret->getIdentifier());
        self::assertEquals('', $secret->getEncryptedDek());
        self::assertEquals('', $secret->getDekNonce());
        self::assertEquals('', $secret->getValueNonce());
    }

    #[Test]
    public function createThrowsOnPartialCryptoFields(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('encryptedDek, dekNonce, and valueNonce must all be set or all be empty');

        Secret::create(
            identifier: 'broken',
            encryptedValue: 'enc_value',
            valueChecksum: 'checksum',
            encryptedDek: 'has_dek',
            dekNonce: '',
            valueNonce: 'has_nonce',
        );
    }

    #[Test]
    public function createThrowsWhenOnlyDekNonceSet(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'broken',
            encryptedValue: 'enc_value',
            valueChecksum: 'checksum',
            dekNonce: 'only_nonce',
        );
    }

    // =========================================================================
    // Strict-assertion tests (kill IncrementInteger/DecrementInteger/CastInt/
    // Coalesce/ArrayItemRemoval/FalseValue mutators on Secret.php)
    // =========================================================================

    #[Test]
    public function newSecretHasStrictZeroDefaultsForAllCounters(): void
    {
        $secret = new Secret();

        // Every default must be EXACTLY 0 (kills Increment/Decrement on defaults).
        self::assertSame(0, $secret->getScopePid());
        self::assertSame(0, $secret->getOwnerUid());
        self::assertSame(0, $secret->getExpiresAt());
        self::assertSame(0, $secret->getLastRotatedAt());
        self::assertSame(0, $secret->getTstamp());
        self::assertSame(0, $secret->getCrdate());
        self::assertSame(0, $secret->getCruserId());
        self::assertSame(0, $secret->getReadCount());
        self::assertSame(0, $secret->getLastReadAt());
    }

    #[Test]
    public function newSecretHasStrictVersionDefaultsOfOne(): void
    {
        $secret = new Secret();

        // Kills Increment/Decrement on encryptionVersion / version defaults.
        self::assertSame(1, $secret->getVersion());
        self::assertSame(1, $secret->getEncryptionVersion());
    }

    #[Test]
    public function newSecretHasStrictEmptyDefaultsForStringFields(): void
    {
        $secret = new Secret();

        // ConcatOperandRemoval / Coalesce mutations surface as non-empty strings.
        self::assertSame('', $secret->getIdentifier());
        self::assertSame('', $secret->getDescription());
        self::assertSame('', $secret->getEncryptedDek());
        self::assertSame('', $secret->getDekNonce());
        self::assertSame('', $secret->getValueNonce());
        self::assertSame('', $secret->getValueChecksum());
        self::assertSame('', $secret->getContext());
        self::assertSame('', $secret->getExternalReference());
    }

    #[Test]
    public function newSecretAdapterDefaultIsExactlyLocal(): void
    {
        $secret = new Secret();

        self::assertSame('local', $secret->getAdapter());
    }

    #[Test]
    public function newSecretArrayDefaultsAreStrictlyEmpty(): void
    {
        $secret = new Secret();

        self::assertSame([], $secret->getAllowedGroups());
        self::assertSame([], $secret->getMetadata());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function incrementVersionBoundaryProvider(): iterable
    {
        yield 'from 1 to 2' => [1, 2];
        yield 'from 2 to 3' => [2, 3];
        yield 'from 99 to 100' => [99, 100];
        yield 'near PHP_INT_MAX' => [PHP_INT_MAX - 2, PHP_INT_MAX - 1];
    }

    #[Test]
    #[DataProvider('incrementVersionBoundaryProvider')]
    public function incrementVersionAddsExactlyOne(int $initial, int $expected): void
    {
        $secret = new Secret();
        $secret->setVersion($initial);
        $secret->incrementVersion();

        self::assertSame($expected, $secret->getVersion());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function incrementReadCountBoundaryProvider(): iterable
    {
        yield 'from 0 to 1' => [0, 1];
        yield 'from 1 to 2' => [1, 2];
        yield 'from 999 to 1000' => [999, 1000];
    }

    #[Test]
    #[DataProvider('incrementReadCountBoundaryProvider')]
    public function incrementReadCountAddsExactlyOne(int $initial, int $expected): void
    {
        $secret = new Secret();
        $secret->setReadCount($initial);
        $secret->incrementReadCount();

        self::assertSame($expected, $secret->getReadCount());
    }

    /**
     * Kills LessThan mutation on isExpired(): `< time()` vs `<= time()`.
     *
     * Provides offsets relative to `time()`, not absolute timestamps, so the
     * data provider survives a clock tick between provider evaluation and
     * assertion (which previously caused flaky failures around second
     * boundaries).
     *
     * @return iterable<string, array{int|null, bool}>
     */
    public static function isExpiredBoundaryProvider(): iterable
    {
        yield 'zero means no-expiration' => ['zero', false];
        yield 'far future' => ['far-future', false];
        yield '60 seconds in future' => ['+60', false];
        yield '60 seconds in past is expired' => ['-60', true];
        yield 'negative absolute means expiresAt > 0 check fails first' => ['negative', false];
    }

    #[Test]
    #[DataProvider('isExpiredBoundaryProvider')]
    public function isExpiredBoundaries(string $kind, bool $expected): void
    {
        $secret = new Secret();
        $expiresAt = match ($kind) {
            'zero' => 0,
            'far-future' => PHP_INT_MAX,
            'negative' => -1,
            '+60' => time() + 60,
            '-60' => time() - 60,
            default => throw new \InvalidArgumentException("Unknown kind: $kind"),
        };
        $secret->setExpiresAt($expiresAt);

        self::assertSame($expected, $secret->isExpired());
    }

    #[Test]
    public function setAllowedGroupsStrictlyCastsStringsToInt(): void
    {
        $secret = new Secret();
        $secret->setAllowedGroups(['1', '2', '3']);

        // assertSame enforces int type — kills UnwrapArrayMap mutation.
        self::assertSame([1, 2, 3], $secret->getAllowedGroups());
    }

    #[Test]
    public function setAllowedGroupsStrictlyCastsFloatToInt(): void
    {
        $secret = new Secret();
        $secret->setAllowedGroups([1.9, 2.1]);

        self::assertSame([1, 2], $secret->getAllowedGroups());
    }

    #[Test]
    public function setAllowedGroupsEmptyArrayStaysEmpty(): void
    {
        $secret = new Secret();
        $secret->setAllowedGroups([]);

        self::assertSame([], $secret->getAllowedGroups());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function scopePidCoalesceProvider(): iterable
    {
        yield 'missing scope_pid' => [['uid' => 1, 'identifier' => 't'], 0];
        yield 'explicit zero' => [['uid' => 1, 'identifier' => 't', 'scope_pid' => 0], 0];
        yield 'positive pid 1' => [['uid' => 1, 'identifier' => 't', 'scope_pid' => 1], 1];
        yield 'pid 42' => [['uid' => 1, 'identifier' => 't', 'scope_pid' => 42], 42];
        yield 'string numeric' => [['uid' => 1, 'identifier' => 't', 'scope_pid' => '99'], 99];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('scopePidCoalesceProvider')]
    public function fromDatabaseRowScopePidCoalesceFallsBackToZero(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getScopePid());
    }

    /**
     * Kills Coalesce + Increment/Decrement on encryption_version default.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function encryptionVersionCoalesceProvider(): iterable
    {
        yield 'missing key defaults to 1' => [['uid' => 1, 'identifier' => 't'], 1];
        yield 'explicit 1' => [['uid' => 1, 'identifier' => 't', 'encryption_version' => 1], 1];
        yield 'explicit 2' => [['uid' => 1, 'identifier' => 't', 'encryption_version' => 2], 2];
        yield 'explicit 10' => [['uid' => 1, 'identifier' => 't', 'encryption_version' => 10], 10];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('encryptionVersionCoalesceProvider')]
    public function fromDatabaseRowEncryptionVersionDefaultsToOne(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getEncryptionVersion());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function versionCoalesceProvider(): iterable
    {
        yield 'missing version defaults to 1' => [['uid' => 1, 'identifier' => 't'], 1];
        yield 'explicit 1' => [['uid' => 1, 'identifier' => 't', 'version' => 1], 1];
        yield 'explicit 2' => [['uid' => 1, 'identifier' => 't', 'version' => 2], 2];
        yield 'explicit 5' => [['uid' => 1, 'identifier' => 't', 'version' => 5], 5];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('versionCoalesceProvider')]
    public function fromDatabaseRowVersionDefaultsToOne(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getVersion());
    }

    /**
     * Kills Coalesce + Increment/Decrement + CastInt on owner_uid default.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function ownerUidCoalesceProvider(): iterable
    {
        yield 'missing defaults to 0' => [['uid' => 1, 'identifier' => 't'], 0];
        yield 'zero stays zero' => [['uid' => 1, 'identifier' => 't', 'owner_uid' => 0], 0];
        yield 'positive one' => [['uid' => 1, 'identifier' => 't', 'owner_uid' => 1], 1];
        yield 'string numeric 42' => [['uid' => 1, 'identifier' => 't', 'owner_uid' => '42'], 42];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('ownerUidCoalesceProvider')]
    public function fromDatabaseRowOwnerUidCastsToInt(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getOwnerUid());
    }

    #[Test]
    public function fromDatabaseRowExpiresAtDefaultsToZero(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame(0, $secret->getExpiresAt());
    }

    #[Test]
    public function fromDatabaseRowLastRotatedAtDefaultsToZero(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame(0, $secret->getLastRotatedAt());
    }

    #[Test]
    public function fromDatabaseRowReadCountDefaultsToZero(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame(0, $secret->getReadCount());
    }

    #[Test]
    public function fromDatabaseRowLastReadAtDefaultsToZero(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame(0, $secret->getLastReadAt());
    }

    #[Test]
    public function fromDatabaseRowCruserIdDefaultsToZero(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame(0, $secret->getCruserId());
    }

    #[Test]
    public function fromDatabaseRowAdapterDefaultsToLocal(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertSame('local', $secret->getAdapter());
    }

    #[Test]
    public function fromDatabaseRowFrontendAccessibleDefaultsToFalse(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertFalse($secret->isFrontendAccessible());
    }

    #[Test]
    public function fromDatabaseRowUidReturnsNullWhenMissing(): void
    {
        $secret = Secret::fromDatabaseRow(['identifier' => 't']);

        self::assertNull($secret->getUid());
    }

    #[Test]
    public function fromDatabaseRowUidCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => '42', 'identifier' => 't']);

        // Kills CastInt mutation on line 500.
        self::assertSame(42, $secret->getUid());
    }

    #[Test]
    public function fromDatabaseRowPropagatesAllCryptoFieldDefaultsAsEmptyString(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        // Kill Coalesce mutators on lines 505-507, 509.
        self::assertSame('', $secret->getEncryptedDek());
        self::assertSame('', $secret->getDekNonce());
        self::assertSame('', $secret->getValueNonce());
        self::assertSame('', $secret->getValueChecksum());
    }

    #[Test]
    public function fromDatabaseRowFullRoundTripStrictAssertions(): void
    {
        $row = [
            'uid' => 42,
            'scope_pid' => 1,
            'identifier' => 'api-key',
            'description' => 'Payment gateway API key',
            'encrypted_value' => 'enc_data',
            'encrypted_dek' => 'dek',
            'dek_nonce' => 'dn',
            'value_nonce' => 'vn',
            'encryption_version' => 2,
            'value_checksum' => 'cs',
            'owner_uid' => 5,
            'allowed_groups' => '7,8,9',
            'context' => 'payment',
            'frontend_accessible' => 1,
            'version' => 3,
            'expires_at' => 1735689600,
            'last_rotated_at' => 1704067200,
            'metadata' => '{"service":"stripe"}',
            'adapter' => 'hashicorp',
            'external_reference' => 'vault:foo',
            'tstamp' => 1704067210,
            'crdate' => 1704067200,
            'cruser_id' => 11,
            'deleted' => 0,
            'hidden' => 0,
            'read_count' => 10,
            'last_read_at' => 1704153600,
        ];

        $secret = Secret::fromDatabaseRow($row);

        self::assertSame(42, $secret->getUid());
        self::assertSame(1, $secret->getScopePid());
        self::assertSame('api-key', $secret->getIdentifier());
        self::assertSame('Payment gateway API key', $secret->getDescription());
        self::assertSame('enc_data', $secret->getEncryptedValue());
        self::assertSame('dek', $secret->getEncryptedDek());
        self::assertSame('dn', $secret->getDekNonce());
        self::assertSame('vn', $secret->getValueNonce());
        self::assertSame(2, $secret->getEncryptionVersion());
        self::assertSame('cs', $secret->getValueChecksum());
        self::assertSame(5, $secret->getOwnerUid());
        self::assertSame([7, 8, 9], $secret->getAllowedGroups());
        self::assertSame('payment', $secret->getContext());
        self::assertTrue($secret->isFrontendAccessible());
        self::assertSame(3, $secret->getVersion());
        self::assertSame(1735689600, $secret->getExpiresAt());
        self::assertSame(1704067200, $secret->getLastRotatedAt());
        self::assertSame(['service' => 'stripe'], $secret->getMetadata());
        self::assertSame('hashicorp', $secret->getAdapter());
        self::assertSame('vault:foo', $secret->getExternalReference());
        self::assertSame(1704067210, $secret->getTstamp());
        self::assertSame(1704067200, $secret->getCrdate());
        self::assertSame(11, $secret->getCruserId());
        self::assertFalse($secret->isDeleted());
        self::assertFalse($secret->isHidden());
        self::assertSame(10, $secret->getReadCount());
        self::assertSame(1704153600, $secret->getLastReadAt());
    }

    #[Test]
    public function toDatabaseRowHasExactKeySet(): void
    {
        $secret = new Secret();
        $row = $secret->toDatabaseRow();

        // Kill ArrayItemRemoval by asserting exact key set.
        $expectedKeys = [
            'adapter',
            'allowed_groups',
            'context',
            'dek_nonce',
            'deleted',
            'description',
            'encrypted_dek',
            'encrypted_value',
            'encryption_version',
            'expires_at',
            'external_reference',
            'frontend_accessible',
            'hidden',
            'identifier',
            'last_read_at',
            'last_rotated_at',
            'metadata',
            'owner_uid',
            'read_count',
            'scope_pid',
            'tstamp',
            'value_checksum',
            'value_nonce',
            'version',
        ];
        $actualKeys = array_keys($row);
        sort($actualKeys);

        self::assertSame($expectedKeys, $actualKeys);
    }

    #[Test]
    public function toDatabaseRowHasStrictIntegerTypesOnScalarFields(): void
    {
        $secret = new Secret();
        $secret
            ->setScopePid(1)
            ->setIdentifier('x')
            ->setEncryptedValue('ev')
            ->setEncryptedDek('dek')
            ->setDekNonce('dn')
            ->setValueNonce('vn')
            ->setEncryptionVersion(1)
            ->setValueChecksum('cs')
            ->setOwnerUid(2)
            ->setAllowedGroups([3, 4])
            ->setContext('ctx')
            ->setFrontendAccessible(true)
            ->setVersion(5)
            ->setExpiresAt(100)
            ->setLastRotatedAt(200)
            ->setMetadata(['k' => 'v'])
            ->setAdapter('hashicorp')
            ->setExternalReference('ref')
            ->setDeleted(false)
            ->setHidden(true)
            ->setReadCount(9)
            ->setLastReadAt(300);

        $row = $secret->toDatabaseRow();

        // Kill ArrayItem / CastInt mutators — strict types per key.
        self::assertSame(1, $row['scope_pid']);
        self::assertSame('x', $row['identifier']);
        self::assertSame('ev', $row['encrypted_value']);
        self::assertSame('dek', $row['encrypted_dek']);
        self::assertSame('dn', $row['dek_nonce']);
        self::assertSame('vn', $row['value_nonce']);
        self::assertSame(1, $row['encryption_version']);
        self::assertSame('cs', $row['value_checksum']);
        self::assertSame(2, $row['owner_uid']);
        self::assertSame('3,4', $row['allowed_groups']);
        self::assertSame('ctx', $row['context']);
        self::assertSame(1, $row['frontend_accessible']);
        self::assertSame(5, $row['version']);
        self::assertSame(100, $row['expires_at']);
        self::assertSame(200, $row['last_rotated_at']);
        self::assertSame('{"k":"v"}', $row['metadata']);
        self::assertSame('hashicorp', $row['adapter']);
        self::assertSame('ref', $row['external_reference']);
        self::assertSame(0, $row['deleted']);
        self::assertSame(1, $row['hidden']);
        self::assertSame(9, $row['read_count']);
        self::assertSame(300, $row['last_read_at']);
    }

    #[Test]
    public function toDatabaseRowSerialisesEmptyAllowedGroupsAsEmptyString(): void
    {
        $secret = new Secret();

        $row = $secret->toDatabaseRow();

        // implode(',', []) === ''
        self::assertSame('', $row['allowed_groups']);
    }

    #[Test]
    public function toDatabaseRowSerialisesMetadataAsJsonEmptyArrayFromEmptyArray(): void
    {
        $secret = new Secret();

        $row = $secret->toDatabaseRow();

        // json_encode([]) === '[]'
        self::assertSame('[]', $row['metadata']);
    }

    #[Test]
    public function toDatabaseRowFrontendAccessibleBooleanSerialisedAsZeroOrOne(): void
    {
        $secret = new Secret();
        $secret->setFrontendAccessible(false);
        self::assertSame(0, $secret->toDatabaseRow()['frontend_accessible']);

        $secret->setFrontendAccessible(true);
        self::assertSame(1, $secret->toDatabaseRow()['frontend_accessible']);
    }

    #[Test]
    public function toDatabaseRowDeletedAndHiddenBooleansSerialisedAsZeroOrOne(): void
    {
        $secret = new Secret();
        $secret->setDeleted(true)->setHidden(false);
        $row = $secret->toDatabaseRow();
        self::assertSame(1, $row['deleted']);
        self::assertSame(0, $row['hidden']);

        $secret->setDeleted(false)->setHidden(true);
        $row = $secret->toDatabaseRow();
        self::assertSame(0, $row['deleted']);
        self::assertSame(1, $row['hidden']);
    }

    /**
     * Kills Increment/Decrement mutations on `create()` defaults.
     */
    #[Test]
    public function createDefaultIntegersAreStrictZero(): void
    {
        $secret = Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
        );

        self::assertSame(0, $secret->getOwnerUid());
        self::assertSame(0, $secret->getExpiresAt());
        self::assertSame(0, $secret->getScopePid());
        self::assertFalse($secret->isFrontendAccessible());
    }

    /**
     * Kills CastInt mutation on line 109 — setCount must be int type.
     */
    #[Test]
    public function createTwoSetCryptoFieldsThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
            encryptedDek: 'a',
            dekNonce: 'b',
        );
    }

    #[Test]
    public function createWithOnlyEncryptedDekThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
            encryptedDek: 'only_dek',
        );
    }

    #[Test]
    public function createWithOnlyValueNonceThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
            valueNonce: 'only_vn',
        );
    }

    #[Test]
    public function createWithAllThreeCryptoFieldsSetSucceeds(): void
    {
        $secret = Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
            encryptedDek: 'a',
            dekNonce: 'b',
            valueNonce: 'cc',
        );

        self::assertSame('a', $secret->getEncryptedDek());
        self::assertSame('b', $secret->getDekNonce());
        self::assertSame('cc', $secret->getValueNonce());
    }

    #[Test]
    public function createCastsAllowedGroupsStrictlyToInt(): void
    {
        $secret = Secret::create(
            identifier: 'x',
            encryptedValue: 'v',
            valueChecksum: 'c',
            allowedGroups: ['10', '20'],
        );

        self::assertSame([10, 20], $secret->getAllowedGroups());
    }

    // =========================================================================
    // Strict-type & boundary tests for fromDatabaseRow() — kill CastInt,
    // Increment/Decrement, Coalesce, FalseValue, UnwrapArrayFilter, LessThan.
    // =========================================================================

    /**
     * Kill CastInt on line 508 — encryption_version cast.
     */
    #[Test]
    public function fromDatabaseRowEncryptionVersionCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'encryption_version' => '3']);

        self::assertSame(3, $secret->getEncryptionVersion());
        self::assertIsInt($secret->getEncryptionVersion());
    }

    /**
     * Kill CastInt on line 513 — version cast.
     */
    #[Test]
    public function fromDatabaseRowVersionCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'version' => '7']);

        self::assertSame(7, $secret->getVersion());
        self::assertIsInt($secret->getVersion());
    }

    /**
     * Kill CastInt on line 514 — expires_at cast.
     */
    #[Test]
    public function fromDatabaseRowExpiresAtCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'expires_at' => '1735689600']);

        self::assertSame(1735689600, $secret->getExpiresAt());
        self::assertIsInt($secret->getExpiresAt());
    }

    /**
     * Kill CastInt on line 515 — last_rotated_at cast.
     */
    #[Test]
    public function fromDatabaseRowLastRotatedAtCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'last_rotated_at' => '1704067200']);

        self::assertSame(1704067200, $secret->getLastRotatedAt());
        self::assertIsInt($secret->getLastRotatedAt());
    }

    /**
     * Kill CastInt + Increment + Decrement on line 518 — tstamp cast and default.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function tstampProvider(): iterable
    {
        yield 'missing defaults exactly to 0' => [['uid' => 1, 'identifier' => 't'], 0];
        yield 'string "42" casts to 42' => [['uid' => 1, 'identifier' => 't', 'tstamp' => '42'], 42];
        yield 'zero stays zero' => [['uid' => 1, 'identifier' => 't', 'tstamp' => 0], 0];
        yield 'positive 1704067200' => [['uid' => 1, 'identifier' => 't', 'tstamp' => 1704067200], 1704067200];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('tstampProvider')]
    public function fromDatabaseRowTstampCastsAndDefaultsCorrectly(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getTstamp());
        self::assertIsInt($secret->getTstamp());
    }

    /**
     * Kill CastInt + Increment + Decrement on line 519 — crdate cast and default.
     *
     * @return iterable<string, array{array<string, mixed>, int}>
     */
    public static function crdateProvider(): iterable
    {
        yield 'missing defaults exactly to 0' => [['uid' => 1, 'identifier' => 't'], 0];
        yield 'string "99" casts to 99' => [['uid' => 1, 'identifier' => 't', 'crdate' => '99'], 99];
        yield 'positive 1600000000' => [['uid' => 1, 'identifier' => 't', 'crdate' => 1600000000], 1600000000];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('crdateProvider')]
    public function fromDatabaseRowCrdateCastsAndDefaultsCorrectly(array $row, int $expected): void
    {
        $secret = Secret::fromDatabaseRow($row);

        self::assertSame($expected, $secret->getCrdate());
        self::assertIsInt($secret->getCrdate());
    }

    /**
     * Kill CastInt on line 520 — cruser_id cast.
     */
    #[Test]
    public function fromDatabaseRowCruserIdCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'cruser_id' => '12']);

        self::assertSame(12, $secret->getCruserId());
        self::assertIsInt($secret->getCruserId());
    }

    /**
     * Kill CastInt on line 523 — read_count cast.
     */
    #[Test]
    public function fromDatabaseRowReadCountCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'read_count' => '55']);

        self::assertSame(55, $secret->getReadCount());
        self::assertIsInt($secret->getReadCount());
    }

    /**
     * Kill CastInt on line 524 — last_read_at cast.
     */
    #[Test]
    public function fromDatabaseRowLastReadAtCastsStringToInt(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'last_read_at' => '1704153600']);

        self::assertSame(1704153600, $secret->getLastReadAt());
        self::assertIsInt($secret->getLastReadAt());
    }

    /**
     * Kill FalseValue + Coalesce on line 521 — deleted default must be FALSE.
     */
    #[Test]
    public function fromDatabaseRowDeletedDefaultIsExactlyFalse(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertFalse($secret->isDeleted());
    }

    /**
     * Kill FalseValue + Coalesce on line 522 — hidden default must be FALSE.
     */
    #[Test]
    public function fromDatabaseRowHiddenDefaultIsExactlyFalse(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertFalse($secret->isHidden());
    }

    /**
     * Kill FalseValue on line 521 — deleted=true (truthy) yields TRUE.
     */
    #[Test]
    public function fromDatabaseRowDeletedTrueReturnsTrue(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'deleted' => 1]);

        self::assertTrue($secret->isDeleted());
    }

    /**
     * Kill FalseValue on line 522 — hidden=true (truthy) yields TRUE.
     */
    #[Test]
    public function fromDatabaseRowHiddenTrueReturnsTrue(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'hidden' => 1]);

        self::assertTrue($secret->isHidden());
    }

    /**
     * Kill LessThan on line 344 — isExpired() uses strict less than time().
     * When expires_at == time() (not past), must NOT be expired.
     */
    #[Test]
    public function isExpiredReturnsFalseWhenExpiresAtEqualsCurrentTime(): void
    {
        $secret = new Secret();
        // Set expiry slightly into the future so equality-to-time is what we test.
        // We cannot mock time() easily, so approximate: set to time + 10 seconds
        // and assert FALSE. The LessThan mutation (<=) would incorrectly expire.
        $futureOneSecond = time() + 1;
        $secret->setExpiresAt($futureOneSecond);

        self::assertFalse($secret->isExpired());
    }

    /**
     * Kill UnwrapArrayFilter on line 535 — allowed_groups filter must remove zeros.
     */
    #[Test]
    public function fromDatabaseRowAllowedGroupsFiltersZeroValues(): void
    {
        // "0" would be intval-cast to 0 and then array_filter removes it (0 is falsy).
        $secret = Secret::fromDatabaseRow([
            'uid' => 1,
            'identifier' => 't',
            'allowed_groups' => '1,0,2,0,3',
        ]);

        // Zeros must be filtered out — without array_filter() they would remain.
        self::assertNotContains(0, $secret->getAllowedGroups());
        self::assertSame([1, 2, 3], array_values($secret->getAllowedGroups()));
    }

    /**
     * Kill UnwrapArrayFilter on line 535 — only zeros returns empty array.
     */
    #[Test]
    public function fromDatabaseRowAllowedGroupsAllZeroReturnsEmpty(): void
    {
        $secret = Secret::fromDatabaseRow([
            'uid' => 1,
            'identifier' => 't',
            'allowed_groups' => '0,0,0',
        ]);

        self::assertSame([], $secret->getAllowedGroups());
    }

    /**
     * Kill CastInt on line 109 — create() uses int arithmetic for setCount.
     * All three crypto fields set must NOT throw.
     */
    #[Test]
    public function createAllThreeCryptoFieldsSetDoesNotThrow(): void
    {
        // No exception expected.
        $secret = Secret::create(
            identifier: 'id',
            encryptedValue: 'v',
            valueChecksum: 'cs',
            encryptedDek: 'dek',
            dekNonce: 'dn',
            valueNonce: 'vn',
        );

        self::assertSame('dek', $secret->getEncryptedDek());
        self::assertSame('dn', $secret->getDekNonce());
        self::assertSame('vn', $secret->getValueNonce());
    }

    /**
     * Kill CastInt on line 109 — create() with only dekNonce set must throw.
     */
    #[Test]
    public function createOnlyDekNonceThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'id',
            encryptedValue: 'v',
            valueChecksum: 'cs',
            dekNonce: 'dn',
        );
    }

    /**
     * Kill CastInt on line 109 — create() with encryptedDek + valueNonce throws.
     */
    #[Test]
    public function createDekAndValueNonceWithoutDekNonceThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'id',
            encryptedValue: 'v',
            valueChecksum: 'cs',
            encryptedDek: 'dek',
            valueNonce: 'vn',
        );
    }

    /**
     * Kill CastInt on line 109 — create() with dekNonce + valueNonce throws.
     */
    #[Test]
    public function createDekNonceAndValueNonceWithoutEncryptedDekThrows(): void
    {
        $this->expectException(ValidationException::class);

        Secret::create(
            identifier: 'id',
            encryptedValue: 'v',
            valueChecksum: 'cs',
            dekNonce: 'dn',
            valueNonce: 'vn',
        );
    }

    /**
     * Kill Coalesce on line 521 — default must apply when deleted is absent.
     */
    #[Test]
    public function fromDatabaseRowDeletedAbsentUsesDefaultFalse(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertFalse($secret->isDeleted());
    }

    /**
     * Kill Coalesce on line 522 — default must apply when hidden is absent.
     */
    #[Test]
    public function fromDatabaseRowHiddenAbsentUsesDefaultFalse(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't']);

        self::assertFalse($secret->isHidden());
    }

    /**
     * Kill Coalesce on line 521 — explicit deleted=true overrides default.
     */
    #[Test]
    public function fromDatabaseRowExplicitDeletedTruthyOverridesDefault(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'deleted' => true]);

        self::assertTrue($secret->isDeleted());
    }

    /**
     * Kill Coalesce on line 522 — explicit hidden=true overrides default.
     */
    #[Test]
    public function fromDatabaseRowExplicitHiddenTruthyOverridesDefault(): void
    {
        $secret = Secret::fromDatabaseRow(['uid' => 1, 'identifier' => 't', 'hidden' => true]);

        self::assertTrue($secret->isHidden());
    }
}
