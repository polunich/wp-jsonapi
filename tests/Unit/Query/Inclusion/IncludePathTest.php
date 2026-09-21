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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Inclusion;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(IncludePath::class)]
final class IncludePathTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(IncludePath::class))->isReadOnly());
    }

    /**
     * @param list<string> $relationships
     */
    #[DataProvider('paths')]
    public function testIsTheDotSeparatedListOfRelationshipNamesOfTheSpecification(
        array $relationships,
        string $expected,
        int $depth,
    ): void {
        $path = new IncludePath($relationships);

        self::assertSame($relationships, $path->relationships());
        self::assertSame($expected, $path->toString());
        self::assertSame($expected, (string) $path);
        self::assertSame($depth, $path->depth());
    }

    /**
     * @return iterable<string, array{list<string>, string, int}>
     */
    public static function paths(): iterable
    {
        yield 'one relationship' => [['orders'], 'orders', 1];
        yield 'the example of the specification' => [['comments', 'author'], 'comments.author', 2];
        yield 'three levels' => [['orders', 'customer', 'supplier'], 'orders.customer.supplier', 3];
    }

    public function testTheDepthIsTheNumberOfRelationshipsThePathRunsThrough(): void
    {
        self::assertSame(1, (new IncludePath(['orders']))->depth());
        self::assertSame(2, (new IncludePath(['orders', 'customer']))->depth());
    }

    public function testRefusesAPathThatNamesNoRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names at least one relationship');

        new IncludePath([]);
    }

    #[DataProvider('illegalSegments')]
    public function testRefusesASegmentThatIsNoMemberName(string $segment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new IncludePath([$segment]);
    }

    public function testEscapesAControlCharacterOfARefusedSegmentInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('segment "or\nders" of a relationship path is no member name, so no'
            . ' relationship carries it');

        new IncludePath(["or\nders"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalSegments(): iterable
    {
        yield 'empty' => [''];
        yield 'the separator of a path' => ['orders.customer'];
        yield 'the separator of a list' => ['orders,customer'];
        yield 'a space last' => ['orders '];
    }
}
