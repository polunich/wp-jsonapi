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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\IncludeParser;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Parsing\CollectedProblems;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(IncludeParser::class)]
final class IncludeParserTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(IncludeParser::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testARequestWithoutTheParameterReadsAsNothing(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(null, 'pets', null, $errors));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testTheEmptyValueIsTheEmptySetOfPaths(): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse('', 'pets', null, $errors);

        self::assertNotNull($paths);
        self::assertTrue($paths->isEmpty());
        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * @param list<string> $written
     */
    #[DataProvider('accepted')]
    public function testReadsTheRelationshipPathsOfTheParameter(string $type, string $value, array $written): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse($value, $type, null, $errors);

        self::assertNotNull($paths);
        self::assertSame($written, array_map(static function (object $path): string {
            return (string) $path;
        }, $paths->all()));
        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function accepted(): iterable
    {
        yield 'one relationship' => ['pets', 'orders', ['orders']];

        yield 'two relationships' => ['pets', 'orders,owner', ['orders', 'owner']];

        yield 'a path as deep as the type allows' => ['stores', 'pets.orders', ['pets.orders']];

        yield 'a path beside a relationship' => [
            'stores',
            'pets.orders,pets',
            ['pets.orders', 'pets'],
        ];

        yield 'a path written twice' => ['pets', 'orders,orders', ['orders']];
    }

    #[DataProvider('refused')]
    public function testAPathThisEndpointDoesNotIncludeIsRefused(string $type, string $value, string $path): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse($value, $type, null, $errors);

        self::assertNotNull($paths);
        self::assertTrue($paths->isEmpty());
        self::assertSame(
            [[ErrorCode::UnsupportedInclude->value, 'include', ['path' => $path]]],
            CollectedProblems::of($errors),
        );
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refused(): iterable
    {
        yield 'a relationship the type does not declare' => ['pets', 'breeder', 'breeder'];

        yield 'a relationship of a related type that is not declared' => [
            'stores',
            'pets.breeder',
            'pets.breeder',
        ];

        yield 'a relationship that forbids inclusion' => ['pets', 'tags', 'tags'];

        yield 'a path deeper than the include depth' => ['pets', 'orders.customer', 'orders.customer'];

        yield 'an attribute in the place of a relationship' => ['pets', 'name', 'name'];
    }

    #[DataProvider('malformed')]
    public function testAValueThatIsNoListOfRelationshipPathsIsInvalid(string $value): void
    {
        $errors = self::errorList();

        self::parser()->parse($value, 'pets', null, $errors);
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(
            [[ErrorCode::InvalidQueryParameter->value, 'include', ['name' => 'include']]],
            CollectedProblems::of($errors),
        );
        self::assertInstanceOf(InvalidArgumentException::class, $exception->getPrevious());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'an empty path between two commas' => ['orders,,owner'];

        yield 'an empty path before a comma' => [',orders'];

        yield 'an empty segment' => ['orders..customer'];

        yield 'a segment that ends with a space, which is no member name' => ['orders '];

        yield 'a segment of reserved characters' => ['orders+customer'];
    }

    public function testTheRelationshipRouteKeepsTheFirstSegmentOfItsRoute(): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse('pets', 'stores', 'pets', $errors);

        self::assertNotNull($paths);
        self::assertSame(['pets'], array_map(static function (object $path): string {
            return (string) $path;
        }, $paths->all()));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testAPathOfAnotherRelationshipIsRefusedOnTheRelationshipRoute(): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse('owner', 'pets', 'orders', $errors);

        self::assertNotNull($paths);
        self::assertTrue($paths->isEmpty());
        self::assertSame(
            [[ErrorCode::UnsupportedInclude->value, 'include', ['path' => 'owner']]],
            CollectedProblems::of($errors),
        );
    }

    public function testTheDepthOfTheRelationshipRouteIsCountedFromTheParentType(): void
    {
        $errors = self::errorList();
        $deeper = self::parser()->parse('orders.customer', 'pets', 'orders', $errors);
        $allowed = self::parser()->parse('pets.orders', 'stores', 'pets', self::errorList());

        self::assertNotNull($deeper);
        self::assertTrue($deeper->isEmpty());
        self::assertNotNull($allowed);
        self::assertSame(
            ['pets.orders'],
            array_map(static function (object $path): string {
                return (string) $path;
            }, $allowed->all()),
        );
        self::assertSame(
            [[ErrorCode::UnsupportedInclude->value, 'include', ['path' => 'orders.customer']]],
            CollectedProblems::of($errors),
        );
    }

    public function testEveryPathOfTheParameterIsReturnedOrRefused(): void
    {
        $errors = self::errorList();
        $paths = self::parser()->parse('breeder,orders,tags', 'pets', null, $errors);

        self::assertNotNull($paths);
        self::assertSame(['orders'], array_map(static function (object $path): string {
            return (string) $path;
        }, $paths->all()));
        self::assertSame(
            [
                [ErrorCode::UnsupportedInclude->value, 'include', ['path' => 'breeder']],
                [ErrorCode::UnsupportedInclude->value, 'include', ['path' => 'tags']],
            ],
            CollectedProblems::of($errors),
        );
    }

    public function testAnIncludeFamilyInAnotherFormIsLeftToTheGuard(): void
    {
        $errors = self::errorList();

        self::assertNull(self::parser()->parse(['orders'], 'pets', null, $errors));
        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testTheLibraryRespondsToTheProblemsOfTheParameterWith400(): void
    {
        $errors = self::errorList();

        self::parser()->parse('breeder', 'pets', null, $errors);
        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(400, $exception->status());
    }

    private static function parser(): IncludeParser
    {
        return new IncludeParser(self::registry());
    }

    /**
     * `pets` keeps the default include depth of 1
     */
    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'stores',
                relationships: [Samples::toMany('pets', 'pets')],
                capabilities: [Capability::RelatedLoader],
                maxIncludeDepth: 2,
            ),
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name')],
                relationships: [
                    Samples::toMany('orders', 'orders'),
                    Samples::toOne('owner', 'customers'),
                    Samples::toMany('tags', 'tags', includable: false),
                ],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            ),
            Samples::resource(
                'orders',
                relationships: [Samples::toOne('customer', 'customers')],
                capabilities: [Capability::CollectionReader],
            ),
            Samples::resource('customers'),
            Samples::resource('tags', capabilities: [Capability::CollectionReader]),
        ]);
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }
}
