<?php

/**
 * wp-jsonapi: JSON:API 1.1 on the WordPress REST API.
 * Copyright (C) 2026 Polunich
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(PendingWrite::class)]
final class PendingWriteTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(PendingWrite::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    #[DataProvider('writes')]
    public function testCarriesWhatTheRouteAndTheQueryReadOfAWrite(
        OperationKind $kind,
        ?Identifier $target,
        ?string $relationship,
    ): void {
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);
        $write = new PendingWrite($kind, 'pets', $target, $relationship, null, $fields, $languages, new Values([]));

        self::assertSame($kind, $write->kind);
        self::assertSame('pets', $write->type);
        self::assertSame($target, $write->target);
        self::assertSame($relationship, $write->relationship);
        self::assertNull($write->include);
        self::assertSame($fields, $write->fields);
        self::assertSame($languages, $write->languages);
    }

    /**
     * @return iterable<string, array{OperationKind, ?Identifier, ?string}>
     */
    public static function writes(): iterable
    {
        $target = new Identifier('pets', '7');

        yield 'a create' => [OperationKind::CreateResource, null, null];
        yield 'an update' => [OperationKind::UpdateResource, $target, null];
        yield 'a replacement' => [OperationKind::ReplaceRelationship, $target, 'owner'];
        yield 'an addition' => [OperationKind::AddToRelationship, $target, 'orders'];
        yield 'a removal' => [OperationKind::RemoveFromRelationship, $target, 'orders'];
    }

    #[DataProvider('impossibleWrites')]
    public function testRefusesAWriteItsKindCannotBe(
        OperationKind $kind,
        string $type,
        ?Identifier $target,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PendingWrite(
            $kind,
            $type,
            $target,
            $relationship,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @return iterable<string, array{OperationKind, string, ?Identifier, ?string, string}>
     */
    public static function impossibleWrites(): iterable
    {
        $target = new Identifier('pets', '7');

        yield 'a kind without content' => [
            OperationKind::DeleteResource,
            'pets',
            $target,
            null,
            'a delete_resource request carries no content',
        ];
        yield 'an empty type' => [OperationKind::CreateResource, '', null, null, 'a type is never empty'];
        yield 'a create with a target' => [
            OperationKind::CreateResource,
            'pets',
            $target,
            null,
            'the create_resource write acts on no existing resource',
        ];
        yield 'an update without a target' => [
            OperationKind::UpdateResource,
            'pets',
            null,
            null,
            'the update_resource write acts on an existing resource',
        ];
        yield 'a target of another type' => [
            OperationKind::UpdateResource,
            'customers',
            $target,
            null,
            'a write of type "customers" names a target of type "pets"',
        ];
        yield 'an update with a relationship' => [
            OperationKind::UpdateResource,
            'pets',
            $target,
            'owner',
            'the update_resource write names no relationship, and this one names one',
        ];
        yield 'a relationship write without one' => [
            OperationKind::AddToRelationship,
            'pets',
            $target,
            null,
            'the add_to_relationship write names a relationship, and this one names none',
        ];
        yield 'an empty relationship' => [
            OperationKind::AddToRelationship,
            'pets',
            $target,
            '',
            'whose name is never empty',
        ];
    }
}
