<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Domain\Model;

use Netresearch\NrVault\Domain\Model\Secret;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
