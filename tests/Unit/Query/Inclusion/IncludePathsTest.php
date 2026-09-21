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
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(IncludePaths::class)]
final class IncludePathsTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(IncludePaths::class))->isReadOnly());
    }

    /**
     * the spec's example, `include=comments.author,ratings`
     */
    public function testKeepsThePathsInTheOrderOfTheRequest(): void
    {
        $comments = new IncludePath(['comments', 'author']);
        $ratings = new IncludePath(['ratings']);

        $paths = new IncludePaths([$comments, $ratings]);

        self::assertSame([$comments, $ratings], $paths->all());
        self::assertSame('comments.author,ratings', $paths->toString());
        self::assertSame('comments.author,ratings', (string) $paths);
        self::assertFalse($paths->isEmpty());
    }

    public function testTheEmptySetAsksForNoRelatedResource(): void
    {
        $paths = new IncludePaths([]);

        self::assertTrue($paths->isEmpty());
        self::assertSame([], $paths->all());
        self::assertSame('', $paths->toString());
    }

    #[DataProvider('membership')]
    public function testTellsWhetherThePathWasAskedFor(IncludePath $path, bool $expected): void
    {
        $paths = new IncludePaths([
            new IncludePath(['orders', 'customer']),
            new IncludePath(['ratings']),
        ]);

        self::assertSame($expected, $paths->has($path));
    }

    /**
     * @return iterable<string, array{IncludePath, bool}>
     */
    public static function membership(): iterable
    {
        yield 'a path of the request' => [new IncludePath(['ratings']), true];
        yield 'a deeper path of the request' => [new IncludePath(['orders', 'customer']), true];
        yield 'a path of another request' => [new IncludePath(['customer']), false];
        yield 'a prefix of a path of the request' => [new IncludePath(['orders']), false];
    }

    public function testAPathWrittenTwiceIsOnePathAtItsFirstPlace(): void
    {
        $first = new IncludePath(['orders', 'customer']);
        $ratings = new IncludePath(['ratings']);
        $repeat = new IncludePath(['orders', 'customer']);

        $paths = new IncludePaths([$first, $ratings, $repeat, $ratings]);

        self::assertSame([$first, $ratings], $paths->all());
        self::assertSame('orders.customer,ratings', $paths->toString());
        self::assertTrue($paths->has($repeat));
    }

    /**
     * the paths are told apart by their text as an array key,
     * which PHP turns into an int for digits
     */
    public function testAPathOfDigitsWrittenTwiceIsOnePath(): void
    {
        $first = new IncludePath(['0']);

        $paths = new IncludePaths([$first, new IncludePath(['1']), new IncludePath(['0'])]);

        self::assertSame(['0', '1'], array_map(
            static function (IncludePath $path): string {
                return $path->toString();
            },
            $paths->all(),
        ));
        self::assertSame($first, $paths->all()[0]);
    }

    /**
     * `orders.customer` returns the orders anyway, yet the client asked for both paths
     */
    public function testAPathAndItsPrefixAreTwoPaths(): void
    {
        $paths = new IncludePaths([
            new IncludePath(['orders']),
            new IncludePath(['orders', 'customer']),
        ]);

        self::assertCount(2, $paths->all());
    }

    public function testPathsThatStartWithTheRelationshipAreRootedAtIt(): void
    {
        $this->expectNotToPerformAssertions();

        (new IncludePaths([
            new IncludePath(['orders']),
            new IncludePath(['orders', 'customer']),
        ]))->checkRootedAt('orders', 'pets');
    }

    public function testAPathThatStartsWithAnotherRelationshipIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'include path "owner" does not start with relationship "orders" of a parent of type "pets", whose linkage'
            . ' is the primary data every included resource is reached from',
        );

        (new IncludePaths([new IncludePath(['orders']), new IncludePath(['owner'])]))->checkRootedAt('orders', 'pets');
    }
}
