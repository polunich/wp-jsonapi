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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ParameterFamilies;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Metadata\WordPressReservedParameters;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Parsing\QueryParameterGuard;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Tests\Fixtures\Parsing\CollectedProblems;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(QueryParameterGuard::class)]
final class QueryParameterGuardTest extends TestCase
{
    /**
     * one parameter per family, each in a form the library reads
     *
     * @var array<string, mixed>
     */
    private const FAMILY_SAMPLES = [
        'include' => 'owner',
        'fields' => ['pets' => 'name'],
        'sort' => '-created',
        'filter' => ['city' => ['@eq' => 'Kyiv']],
        'page' => ['size' => '3'],
    ];

    /**
     * mirrors `WordPressReservedParameters::CONSUMED` and `WordPressReservedParameters::REFUSED`
     *
     * @var list<string>
     */
    private const RESERVED = [
        'rest_route',
        '_method',
        '_wpnonce',
        '_fields',
        '_embed',
        '_envelope',
        '_jsonp',
        '_locale',
    ];

    /**
     * the `source.parameter` each sample is refused under
     *
     * @var array<string, string>
     */
    private const WRITTEN = [
        'include' => 'include',
        'fields' => 'fields[pets]',
        'sort' => 'sort',
        'filter' => 'filter[city][@eq]',
        'page' => 'page[size]',
    ];

    /**
     * @return iterable<string, array{?OperationKind, ?Cardinality, list<string>}>
     */
    public static function routes(): iterable
    {
        yield 'the collection route' => [
            OperationKind::FetchCollection,
            null,
            ['include', 'fields', 'sort', 'filter', 'page'],
        ];
        yield 'the resource route' => [OperationKind::FetchResource, null, ['include', 'fields']];
        yield 'the related route of a to-many relationship' => [
            OperationKind::FetchRelated,
            Cardinality::ToMany,
            ['include', 'fields', 'sort', 'filter', 'page'],
        ];
        yield 'the related route of a to-one relationship' => [
            OperationKind::FetchRelated,
            Cardinality::ToOne,
            ['include', 'fields'],
        ];
        yield 'the relationship route of a to-many relationship' => [
            OperationKind::FetchRelationship,
            Cardinality::ToMany,
            ['include', 'fields', 'page'],
        ];
        yield 'the relationship route of a to-one relationship' => [
            OperationKind::FetchRelationship,
            Cardinality::ToOne,
            ['include', 'fields'],
        ];
        yield 'a create' => [OperationKind::CreateResource, null, ['include', 'fields']];
        yield 'an update' => [OperationKind::UpdateResource, null, ['include', 'fields']];
        yield 'a delete' => [OperationKind::DeleteResource, null, []];
        yield 'the replacement of a to-many relationship' => [
            OperationKind::ReplaceRelationship,
            Cardinality::ToMany,
            ['include', 'fields'],
        ];
        yield 'the replacement of a to-one relationship' => [
            OperationKind::ReplaceRelationship,
            Cardinality::ToOne,
            ['include', 'fields'],
        ];
        yield 'an addition to a relationship' => [
            OperationKind::AddToRelationship,
            Cardinality::ToMany,
            ['include', 'fields'],
        ];
        yield 'a removal from a relationship' => [
            OperationKind::RemoveFromRelationship,
            Cardinality::ToMany,
            ['include', 'fields'],
        ];
        yield 'the atomic route' => [null, null, ['fields']];
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function familiesInAFormNoRouteReads(): iterable
    {
        yield 'include with a key beneath it' => [['include' => ['x' => 'owner']], 'include[x]'];
        yield 'the sort of Strapi' => [['sort' => ['name:asc']], 'sort[0]'];
        yield 'fields without a type' => [['fields' => 'name'], 'fields'];
        yield 'page without a name' => [['page' => '2'], 'page'];
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function filterShapes(): iterable
    {
        yield 'a filter without a field' => ['Kyiv'];
        yield 'a filter written as a list' => [['Kyiv']];
        yield 'a field without a comparison' => [['city' => 'Kyiv']];
        yield 'an empty filter' => [[]];
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function namesNoRouteProcesses(): iterable
    {
        yield 'a name of a-z alone' => [['locale' => 'uk'], 'locale'];
        yield 'a name of the naming convention' => [['pageSize' => '3'], 'pageSize'];
        yield 'a name that is no member name' => [['_size' => '3'], '_size'];
        yield 'a name of digits, which PHP reads as an int key' => [[0 => 'x'], '0'];
        yield 'the empty name' => [['' => 'x'], ''];
        yield 'a family of another case' => [['Filter' => ['city' => 'Kyiv']], 'Filter[city]'];
        yield 'a name with a key beneath it' => [['context' => ['view' => '1']], 'context[view]'];
        yield 'a name whose value is an empty map' => [['context' => []], 'context'];
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(QueryParameterGuard::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param list<string> $processed
     */
    #[DataProvider('routes')]
    public function testTheRouteProcessesTheFamiliesOfItsKind(
        ?OperationKind $kind,
        ?Cardinality $cardinality,
        array $processed,
    ): void {
        foreach (self::FAMILY_SAMPLES as $family => $value) {
            $errors = self::errorList();
            self::check([$family => $value], $kind, $cardinality, $errors);

            $name = self::WRITTEN[$family];
            $expected = in_array($family, $processed, true)
                ? []
                : [['unsupported_query_parameter', $name, ['name' => $name]]];

            self::assertSame($expected, CollectedProblems::of($errors), 'the family ' . $family);
        }
    }

    /**
     * the collection route processes all five families, so only the form can cause the refusal
     *
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('familiesInAFormNoRouteReads')]
    public function testAFamilyInAFormTheLibraryDoesNotReadIsRefused(array $query, string $name): void
    {
        $errors = self::errorList();
        self::check($query, OperationKind::FetchCollection, null, $errors);

        self::assertSame(
            [['unsupported_query_parameter', $name, ['name' => $name]]],
            CollectedProblems::of($errors),
        );
    }

    #[DataProvider('filterShapes')]
    public function testTheShapeOfAFilterIsTheBusinessOfItsParser(mixed $filter): void
    {
        $errors = self::errorList();
        self::check(['filter' => $filter], OperationKind::FetchCollection, null, $errors);

        self::assertSame([], CollectedProblems::of($errors));
    }

    #[DataProvider('filterShapes')]
    public function testARouteThatFiltersNothingRefusesAFilterOfEveryShape(mixed $filter): void
    {
        $errors = self::errorList();
        self::check(['filter' => $filter], OperationKind::FetchResource, null, $errors);

        self::assertNotSame([], CollectedProblems::of($errors));
        self::assertSame(
            ['unsupported_query_parameter'],
            array_unique(array_column(CollectedProblems::of($errors), 0)),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('namesNoRouteProcesses')]
    public function testAParameterOfNoFamilyOfTheLibraryIsRefused(array $query, string $name): void
    {
        $errors = self::errorList();
        self::check($query, OperationKind::FetchCollection, null, $errors);

        self::assertSame(
            [['unsupported_query_parameter', $name, ['name' => $name]]],
            CollectedProblems::of($errors),
        );
    }

    public function testTheParametersWordPressConsumesArePassedOver(): void
    {
        $errors = self::errorList();
        self::check(
            ['rest_route' => '/acme/v1/pets', '_method' => 'PATCH', '_wpnonce' => 'abc123'],
            OperationKind::FetchCollection,
            null,
            $errors,
        );

        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testTheParametersOfWordPressThatChangeTheResponseAreRefused(): void
    {
        $errors = self::errorList();
        self::check(
            ['_fields' => 'id', '_embed' => '1', '_envelope' => '1', '_jsonp' => 'cb', '_locale' => 'user'],
            OperationKind::FetchCollection,
            null,
            $errors,
        );

        self::assertSame(
            ['_fields', '_embed', '_envelope', '_jsonp', '_locale'],
            array_column(CollectedProblems::of($errors), 1),
        );
    }

    /**
     * `check()` refuses a reserved parameter before it admits a family,
     * which matters only once a family takes a reserved name; this pins that none does
     */
    public function testNoParameterOfWordPressIsAFamilyOfTheSpecification(): void
    {
        foreach (self::RESERVED as $name) {
            self::assertTrue(
                WordPressReservedParameters::isConsumed($name) !== WordPressReservedParameters::isRefused($name),
                'the parameter ' . $name . ' is consumed or refused and not both',
            );
            self::assertArrayNotHasKey($name, self::FAMILY_SAMPLES, 'the parameter ' . $name);
        }
    }

    public function testARefusedParameterIsNamedWithTheKeysBeneathIt(): void
    {
        $errors = self::errorList();
        self::check(
            ['filter' => ['@or' => [['city' => ['@eq' => 'Kyiv']], ['city' => ['@eq' => 'Lviv']]]]],
            OperationKind::FetchResource,
            null,
            $errors,
        );

        self::assertSame(
            ['filter[@or][0][city][@eq]', 'filter[@or][1][city][@eq]'],
            array_column(CollectedProblems::of($errors), 1),
        );
    }

    public function testEveryRefusedParameterIsCollected(): void
    {
        $errors = self::errorList();
        self::check(
            [
                'include' => 'owner',
                'sort' => '-created',
                'page' => ['size' => '3', 'number' => '2'],
                'unknown' => 'x',
            ],
            OperationKind::FetchResource,
            null,
            $errors,
        );

        self::assertSame(
            ['sort', 'page[size]', 'page[number]', 'unknown'],
            array_column(CollectedProblems::of($errors), 1),
        );
    }

    public function testTheRefusalIsA400(): void
    {
        $errors = self::errorList();
        self::check(['sort' => 'name'], OperationKind::FetchResource, null, $errors);

        self::assertSame(400, $errors->exception()?->status());
    }

    public function testAnEndpointProcessesTheFamiliesItDeclares(): void
    {
        $errors = self::errorList();
        (new QueryParameterGuard([]))->check(
            [
                'createdAfter' => '2026-01-01',
                'priceRange' => ['min' => '10'],
                'fields' => ['orders' => 'total'],
                'include' => 'pet',
            ],
            self::route([ParameterFamily::Fields], ['createdAfter', 'priceRange']),
            $errors,
        );

        self::assertSame(
            [['unsupported_query_parameter', 'include', ['name' => 'include']]],
            CollectedProblems::of($errors),
        );
    }

    public function testAnEndpointRefusesAParameterItDoesNotDeclare(): void
    {
        $errors = self::errorList();
        (new QueryParameterGuard([]))->check(
            ['createdBefore' => '2026-01-01', 'rest_route' => '/acme/v1/orders/export'],
            self::route([], ['createdAfter']),
            $errors,
        );

        self::assertSame(
            [['unsupported_query_parameter', 'createdBefore', ['name' => 'createdBefore']]],
            CollectedProblems::of($errors),
        );
    }

    public function testEveryRouteTakesTheLanguageParametersInAnyShape(): void
    {
        $guard = new QueryParameterGuard(['uiLocale', 'lang-code']);

        foreach ([OperationKind::FetchResource, OperationKind::DeleteResource, null] as $kind) {
            $errors = self::errorList();
            $guard->check(
                ['uiLocale' => ['uk'], 'lang-code' => '', 'locale' => 'uk'],
                self::route(ParameterFamilies::processedBy($kind, null), []),
                $errors,
            );

            self::assertSame(
                [['unsupported_query_parameter', 'locale', ['name' => 'locale']]],
                CollectedProblems::of($errors),
            );
        }

        $errors = self::errorList();
        $guard->check(['uiLocale' => 'uk'], self::route([], ['createdAfter']), $errors);

        self::assertSame([], CollectedProblems::of($errors));
    }

    public function testARequestWithoutQueryParametersIsRefusedNothing(): void
    {
        $errors = self::errorList();
        self::check([], OperationKind::DeleteResource, null, $errors);

        self::assertSame([], CollectedProblems::of($errors));
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function check(
        array $query,
        ?OperationKind $kind,
        ?Cardinality $cardinality,
        ErrorList $errors,
    ): void {
        (new QueryParameterGuard([]))->check(
            $query,
            self::route(ParameterFamilies::processedBy($kind, $cardinality), []),
            $errors,
        );
    }

    /**
     * @param list<ParameterFamily> $families
     * @param list<string>          $declared the names of optional parameters
     */
    private static function route(array $families, array $declared): RouteQuery
    {
        return new RouteQuery($families, array_map(static function (string $name): QueryParameterMetadata {
            return new QueryParameterMetadata($name, new StringCodec(), false);
        }, $declared));
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }
}
