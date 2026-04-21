<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

declare(strict_types=1);

namespace Netresearch\NrVault\Tests\Unit\Traits;

use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Schema\Field\FieldCollection;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Shared helper for mocking TYPO3 TCA schema in unit tests.
 *
 * Extracted from ~4 duplicated copies:
 *  - `Tests/Unit/Hook/DataHandlerHookTest.php`
 *  - `Tests/Unit/Utility/VaultFieldResolverTest.php`
 *  - `Tests/Unit/Hook/FlexFormVaultHookTest.php`
 *  - `Tests/Unit/Command/VaultMigrateFieldCommandTest.php`
 *
 * Usage:
 *
 * ```php
 * use Netresearch\NrVault\Tests\Unit\Traits\TcaSchemaMockTrait;
 *
 * final class MyTest extends UnitTestCase
 * {
 *     use TcaSchemaMockTrait;
 *
 *     private TcaSchemaFactory&MockObject $tcaSchemaFactory;
 *
 *     protected function setUp(): void
 *     {
 *         parent::setUp();
 *         $this->tcaSchemaFactory = $this->createMock(TcaSchemaFactory::class);
 *     }
 *
 *     public function testSomething(): void
 *     {
 *         $this->mockTcaSchemaForTable('tx_test', [
 *             'secret_field' => ['type' => 'text', 'renderType' => 'vaultSecret'],
 *         ]);
 *         // ...
 *     }
 * }
 * ```
 *
 * The trait requires the consuming test class to expose a
 * `TcaSchemaFactory&MockObject` property named `$tcaSchemaFactory`.
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait TcaSchemaMockTrait
{
    /**
     * Configure the `$tcaSchemaFactory` mock to return a schema with the given fields.
     *
     * @param array<string, array<string, mixed>> $fields field name => TCA field config
     */
    protected function mockTcaSchemaForTable(string $table, array $fields = []): void
    {
        if (!isset($this->tcaSchemaFactory) || !$this->tcaSchemaFactory instanceof TcaSchemaFactory) {
            throw new \LogicException(
                \sprintf(
                    '%s requires the test to define a `$tcaSchemaFactory` property '
                    . 'of type `TcaSchemaFactory&MockObject` before calling %s().',
                    self::class,
                    __FUNCTION__,
                ),
            );
        }

        /** @var TcaSchema&MockObject $schema */
        $schema = $this->createMock(TcaSchema::class);

        $fieldMocks = [];
        foreach ($fields as $fieldName => $config) {
            /** @var FieldTypeInterface&MockObject $field */
            $field = $this->createMock(FieldTypeInterface::class);
            $field->method('getName')->willReturn($fieldName);
            $field->method('getConfiguration')->willReturn($config);
            $fieldMocks[$fieldName] = $field;
        }

        $schema->method('getFields')->willReturn(new FieldCollection($fieldMocks));

        $this->tcaSchemaFactory->method('has')->with($table)->willReturn(true);
        $this->tcaSchemaFactory->method('get')->with($table)->willReturn($schema);
    }
}
