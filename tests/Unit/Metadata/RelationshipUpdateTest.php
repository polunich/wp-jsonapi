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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Operation\OperationKind;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(RelationshipUpdate::class)]
final class RelationshipUpdateTest extends TestCase
{
    public function testTheCasesAreReplaceAddAndRemove(): void
    {
        self::assertSame(
            ['Replace' => 'replace', 'Add' => 'add', 'Remove' => 'remove'],
            array_combine(
                array_map(static function (RelationshipUpdate $update): string {
                    return $update->name;
                }, RelationshipUpdate::cases()),
                array_map(static function (RelationshipUpdate $update): string {
                    return $update->value;
                }, RelationshipUpdate::cases()),
            ),
        );
    }

    public function testTheEnumIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionEnum(RelationshipUpdate::class))->getDocComment(),
        );
    }

    #[DataProvider('kinds')]
    public function testReturnsTheUpdateAKindPerforms(OperationKind $kind, ?RelationshipUpdate $expected): void
    {
        self::assertSame($expected, RelationshipUpdate::of($kind));
    }

    /**
     * @return iterable<string, array{OperationKind, ?RelationshipUpdate}>
     */
    public static function kinds(): iterable
    {
        foreach (OperationKind::cases() as $kind) {
            yield $kind->value => [$kind, match ($kind) {
                OperationKind::ReplaceRelationship => RelationshipUpdate::Replace,
                OperationKind::AddToRelationship => RelationshipUpdate::Add,
                OperationKind::RemoveFromRelationship => RelationshipUpdate::Remove,
                default => null,
            }];
        }
    }
}
