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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Sort;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Sort\Direction;

/**
 * @internal
 */
#[CoversClass(Direction::class)]
final class DirectionTest extends TestCase
{
    #[DataProvider('prefixes')]
    public function testWritesThePrefixOfTheSpecification(Direction $direction, string $expected): void
    {
        self::assertSame($expected, $direction->prefix());
    }

    /**
     * @return iterable<string, array{Direction, string}>
     */
    public static function prefixes(): iterable
    {
        yield 'ascending' => [Direction::Ascending, ''];
        yield 'descending' => [Direction::Descending, '-'];
    }

    #[DataProvider('opposites')]
    public function testReturnsTheOppositeDirection(Direction $direction, Direction $expected): void
    {
        self::assertSame($expected, $direction->reverse());
        self::assertSame($direction, $direction->reverse()->reverse());
    }

    /**
     * @return iterable<string, array{Direction, Direction}>
     */
    public static function opposites(): iterable
    {
        yield 'ascending' => [Direction::Ascending, Direction::Descending];
        yield 'descending' => [Direction::Descending, Direction::Ascending];
    }

    public function testTheCasesAreTheTwoDirectionsOfTheSpecification(): void
    {
        self::assertSame(['asc', 'desc'], array_map(
            static function (Direction $direction): string {
                return $direction->value;
            },
            Direction::cases(),
        ));
    }
}
