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

namespace Polunich\WpJsonApi\Tests\Unit\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(PathTemplate::class)]
final class PathTemplateTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(PathTemplate::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheTemplateKeepsItsSegmentsAsGiven(): void
    {
        $parameter = self::digits('pet_id');
        $template = new PathTemplate(['pet toys', $parameter, 'relationships', 'bought by']);

        self::assertSame(['pet toys', $parameter, 'relationships', 'bought by'], $template->segments);
        self::assertSame([$parameter], $template->parameters());
    }

    /**
     * @param non-empty-list<string|PathParameter> $segments
     */
    #[DataProvider('paths')]
    public function testThePathEncodesEveryLiteralAndWritesEveryParameterInBraces(array $segments, string $path): void
    {
        self::assertSame($path, (new PathTemplate($segments))->path);
    }

    /**
     * @return iterable<string, array{non-empty-list<string|PathParameter>, string}>
     */
    public static function paths(): iterable
    {
        yield 'a literal alone' => [['operations'], '/operations'];
        yield 'a parameter' => [['pets', self::digits('pet_id')], '/pets/{pet_id}'];
        yield 'the unreserved characters as they are' => [['a-b.c_d~e'], '/a-b.c_d~e'];
        yield 'a space' => [['pet toys', self::digits('id'), 'bought by'], '/pet%20toys/{id}/bought%20by'];
        yield 'a character above U+007F, with upper-case hex digits' => [["caf\u{e9}"], '/caf%C3%A9'];
        yield 'braces of a literal' => [['{id}'], '/%7Bid%7D'];
        yield 'a slash of a literal' => [['a/b'], '/a%2Fb'];
    }

    /**
     * a path a project writes:
     * a literal of unreserved characters but the dot, a parameter as a whole segment
     */
    public function testAWrittenPathIsReadIntoLiteralsAndParameters(): void
    {
        $digits = new DigitsCodec();
        $uuid = new UuidCodec();

        $template = PathTemplate::written(
            '/customers/{customer_id}/orders/{order_id}/export-csv_~',
            ['order_id' => $uuid, 'customer_id' => $digits],
            'the endpoint "exportOrder"',
        );

        self::assertEquals(
            [
                'customers',
                new PathParameter('customer_id', $digits),
                'orders',
                new PathParameter('order_id', $uuid),
                'export-csv_~',
            ],
            $template->segments,
        );
        self::assertSame('/customers/{customer_id}/orders/{order_id}/export-csv_~', $template->path);
    }

    /**
     * @param array<string, DigitsCodec> $codecs
     */
    #[DataProvider('refusedWrittenPaths')]
    public function testAWrittenPathOutsideTheRulesIsRefused(string $path, array $codecs, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        PathTemplate::written($path, $codecs, 'the endpoint "exportOrders"');
    }

    /**
     * @return iterable<string, array{string, array<string, DigitsCodec>, string}>
     */
    public static function refusedWrittenPaths(): iterable
    {
        $digits = ['id' => new DigitsCodec()];

        yield 'no leading slash' => [
            'orders/export',
            [],
            'the endpoint "exportOrders" writes the path "orders/export", and a path begins with "/" and names one'
            . ' segment at least',
        ];
        yield 'the root alone' => ['/', [], 'writes the path "/", and a path begins with "/"'];
        yield 'an empty segment' => [
            '/orders//export',
            [],
            'writes the path "/orders//export", which holds an empty segment',
        ];
        yield 'a trailing slash' => ['/orders/', [], 'which holds an empty segment'];
        yield 'a dot, which WordPress would publish escaped' => [
            '/orders/export.csv',
            [],
            'the endpoint "exportOrders" writes the segment "export.csv" in its path, and a literal segment holds'
            . ' letters, digits, "-", "_" and "~" alone',
        ];
        yield 'a percent' => ['/orders/a%20b', [], 'writes the segment "a%20b"'];
        yield 'a space' => ['/orders/a b', [], 'writes the segment "a b"'];
        yield 'a brace inside a literal' => ['/orders/{id}.csv', $digits, 'writes the segment "{id}.csv"'];
        yield 'a parameter name of no group' => [
            '/orders/{1id}',
            [],
            'a parameter of the path "/orders/{1id}" of the endpoint "exportOrders" is named "1id"',
        ];
        yield 'a parameter without a codec' => [
            '/orders/{id}',
            [],
            'the endpoint "exportOrders" writes the parameter "id" in its path and declares no codec for it and'
            . ' binds it to no type',
        ];
        yield 'a codec of no parameter' => [
            '/orders/export',
            $digits,
            'the endpoint "exportOrders" declares the parameter "id", which its path "/orders/export" does not hold',
        ];
        yield 'a parameter twice' => ['/orders/{id}/{id}', $digits, 'a path template names the parameter "id" twice'];
    }

    public function testFillPutsTheValueOfEachParameterInItsSegment(): void
    {
        $template = new PathTemplate([
            'orders',
            new PathParameter('order_id', new DigitsCodec()),
            'items',
            new PathParameter('item', new DigitsCodec()),
        ]);

        self::assertSame(['orders', 'a/b', 'items', '7'], $template->fill(['item' => '7', 'order_id' => 'a/b']));
        self::assertSame(['orders'], (new PathTemplate(['orders']))->fill([]));
    }

    public function testFillRefusesAParameterWithoutAValue(): void
    {
        $template = new PathTemplate(['orders', new PathParameter('order_id', new DigitsCodec())]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the path "/orders/{order_id}" names the parameter "order_id", and no value of it is given',
        );

        $template->fill(['id' => '7']);
    }

    public function testTheParametersFollowThePathOrder(): void
    {
        $customer = self::digits('customer_id');
        $pet = self::digits('pet_id');

        self::assertSame([$customer, $pet], (new PathTemplate(['customers', $customer, 'pets', $pet]))->parameters());
        self::assertSame([], (new PathTemplate(['pets']))->parameters());
    }

    public function testAnEmptyLiteralIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a path template holds an empty literal segment, and a path segment of a route is never empty',
        );

        new PathTemplate(['pets', '']);
    }

    public function testAParameterNameTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a path template names the parameter "id" twice, and a request hands over one value per name',
        );

        new PathTemplate(['customers', self::digits('id'), 'pets', self::digits('id')]);
    }

    /**
     * @param non-empty-list<string|PathParameter> $first
     * @param non-empty-list<string|PathParameter> $second
     */
    #[DataProvider('overlaps')]
    public function testTheOverlapIsDecidedSegmentBySegment(
        array $first,
        array $second,
        bool $exactly,
        bool $ignoringCase,
    ): void {
        $one = new PathTemplate($first);
        $other = new PathTemplate($second);

        self::assertSame($exactly, $one->overlaps($other, false));
        self::assertSame($exactly, $other->overlaps($one, false));
        self::assertSame($ignoringCase, $one->overlaps($other, true));
        self::assertSame($ignoringCase, $other->overlaps($one, true));
    }

    /**
     * @return iterable<string, array{
     *     non-empty-list<string|PathParameter>,
     *     non-empty-list<string|PathParameter>,
     *     bool,
     *     bool,
     * }>
     */
    public static function overlaps(): iterable
    {
        yield 'one template' => [['pets', self::digits('id')], ['pets', self::digits('id')], true, true];
        yield 'other lengths' => [['pets'], ['pets', self::digits('id')], false, false];
        yield 'other literals' => [['pets'], ['orders'], false, false];
        yield 'literals in other case' => [['Pets'], ['pets'], false, true];
        yield 'two parameters of other codecs' => [
            ['pets', self::digits('id')],
            ['pets', new PathParameter('slug', new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+'))],
            true,
            true,
        ];
        yield 'a literal the codec admits' => [['pets', '7'], ['pets', self::digits('id')], true, true];
        yield 'a literal the codec refuses' => [['pets', 'export'], ['pets', self::digits('id')], false, false];
        yield 'a literal the codec admits in other case only' => [
            ['pets', 'ABCDEF01-2345-4789-8abc-def012345678'],
            ['pets', new PathParameter('id', new DescribedCodec(new Schema(type: JsonType::String), '[0-9a-f-]+'))],
            false,
            true,
        ];
        yield 'a literal the codec admits encoded' => [
            ['pets', 'pet toys'],
            [
                'pets',
                new PathParameter('slug', new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+%20[a-z]+')),
            ],
            true,
            true,
        ];
        yield 'a literal whose text alone the codec admits' => [
            ['pets', 'pet toys'],
            [
                'pets',
                new PathParameter('slug', new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+ [a-z]+')),
            ],
            false,
            false,
        ];
        yield 'a codec that admits a part of the literal' => [
            ['pets', '7a'],
            ['pets', self::digits('id')],
            false,
            false,
        ];
        yield 'one segment apart' => [
            ['pets', self::digits('id'), 'owner'],
            ['pets', self::digits('id'), 'orders'],
            false,
            false,
        ];
    }

    /**
     * @param non-empty-list<string|PathParameter> $first
     * @param non-empty-list<string|PathParameter> $second
     */
    #[DataProvider('hierarchies')]
    public function testOneHierarchyIsEqualLiteralsAndParametersAtTheSamePositions(
        array $first,
        array $second,
        bool $shared,
    ): void {
        self::assertSame($shared, (new PathTemplate($first))->hierarchy() === (new PathTemplate($second))->hierarchy());
    }

    public function testTheHierarchyIsThePathWithEveryParameterUnnamed(): void
    {
        self::assertSame(
            '/pet%20toys/{}/relationships/{}',
            (new PathTemplate(['pet toys', self::digits('id'), 'relationships', self::digits('tag')]))->hierarchy(),
        );
    }

    /**
     * @return iterable<string, array{
     *     non-empty-list<string|PathParameter>,
     *     non-empty-list<string|PathParameter>,
     *     bool,
     * }>
     */
    public static function hierarchies(): iterable
    {
        yield 'one template' => [['pets', self::digits('id')], ['pets', self::digits('id')], true];
        yield 'parameters named apart' => [['pets', self::digits('id')], ['pets', self::digits('pet_id')], true];
        yield 'parameters of other codecs' => [
            ['pets', self::digits('id')],
            ['pets', new PathParameter('id', new UuidCodec())],
            true,
        ];
        yield 'other literals' => [['pets', self::digits('id')], ['orders', self::digits('id')], false];
        yield 'literals in other case' => [['Pets'], ['pets'], false];
        yield 'a literal where the other has a parameter' => [['pets', '7'], ['pets', self::digits('id')], false];
        yield 'other lengths' => [['pets'], ['pets', self::digits('id')], false];
        yield 'a literal written as an unnamed parameter' => [['pets', '{}'], ['pets', self::digits('id')], false];
        yield 'a literal that holds a slash' => [['pets/toys'], ['pets', 'toys'], false];
    }

    private static function digits(string $name): PathParameter
    {
        return new PathParameter($name, new DigitsCodec());
    }
}
