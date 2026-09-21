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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\ParameterFamilies;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ParameterFamilies::class)]
final class ParameterFamiliesTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ParameterFamilies::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param list<ParameterFamily> $expected
     */
    #[DataProvider('routes')]
    public function testReturnsTheFamiliesOfEachRoute(
        ?OperationKind $kind,
        ?Cardinality $cardinality,
        array $expected,
    ): void {
        self::assertSame($expected, ParameterFamilies::processedBy($kind, $cardinality));
    }

    /**
     * @return iterable<string, array{?OperationKind, ?Cardinality, list<ParameterFamily>}>
     */
    public static function routes(): iterable
    {
        $all = [
            ParameterFamily::Filter,
            ParameterFamily::Sort,
            ParameterFamily::Page,
            ParameterFamily::Include,
            ParameterFamily::Fields,
        ];
        $document = [ParameterFamily::Include, ParameterFamily::Fields];

        yield 'the collection route' => [OperationKind::FetchCollection, null, $all];
        yield 'the resource route' => [OperationKind::FetchResource, null, $document];
        yield 'the related route of a to-many relationship' => [OperationKind::FetchRelated, Cardinality::ToMany, $all];
        yield 'the related route of a to-one relationship' => [
            OperationKind::FetchRelated,
            Cardinality::ToOne,
            $document,
        ];
        yield 'the relationship route of a to-many relationship' => [
            OperationKind::FetchRelationship,
            Cardinality::ToMany,
            [ParameterFamily::Page, ...$document],
        ];
        yield 'the relationship route of a to-one relationship' => [
            OperationKind::FetchRelationship,
            Cardinality::ToOne,
            $document,
        ];
        yield 'the create' => [OperationKind::CreateResource, null, $document];
        yield 'the update' => [OperationKind::UpdateResource, null, $document];
        yield 'the delete' => [OperationKind::DeleteResource, null, []];
        yield 'the replacement of a relationship' => [
            OperationKind::ReplaceRelationship,
            Cardinality::ToOne,
            $document,
        ];
        yield 'the addition to a relationship' => [OperationKind::AddToRelationship, Cardinality::ToMany, $document];
        yield 'the removal from a relationship' => [
            OperationKind::RemoveFromRelationship,
            Cardinality::ToMany,
            $document,
        ];
        yield 'the atomic route' => [null, null, [ParameterFamily::Fields]];
    }

    #[DataProvider('mismatchedCardinalities')]
    public function testRefusesACardinalityThatDoesNotMatchTheKind(
        ?OperationKind $kind,
        ?Cardinality $cardinality,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        ParameterFamilies::processedBy($kind, $cardinality);
    }

    /**
     * @return iterable<string, array{?OperationKind, ?Cardinality, string}>
     */
    public static function mismatchedCardinalities(): iterable
    {
        yield 'a relationship route without one' => [
            OperationKind::FetchRelated,
            null,
            'the fetch_related route addresses a relationship',
        ];
        yield 'a resource route with one' => [
            OperationKind::FetchCollection,
            Cardinality::ToMany,
            'the fetch_collection route addresses no relationship',
        ];
        yield 'the atomic route with one' => [
            null,
            Cardinality::ToOne,
            'the atomic route addresses no relationship',
        ];
    }

    /**
     * @param array<int, ResponseMetadata> $responses
     * @param list<ParameterFamily>        $expected
     */
    #[DataProvider('endpoints')]
    public function testAnEndpointProcessesIncludeAndFieldsWhereItsResponsesCarryResources(
        array $responses,
        array $expected,
    ): void {
        self::assertSame($expected, ParameterFamilies::processedByEndpoint(Samples::endpoint(responses: $responses)));
    }

    public function testAPageProcessesFilterSortAndPageBesideIncludeAndFields(): void
    {
        self::assertSame(ParameterFamily::cases(), ParameterFamilies::processedByEndpoint(Samples::pageEndpoint()));
    }

    /**
     * @return iterable<string, array{array<int, ResponseMetadata>, list<ParameterFamily>}>
     */
    public static function endpoints(): iterable
    {
        yield 'a resource' => [
            [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
            [ParameterFamily::Include, ParameterFamily::Fields],
        ];
        yield 'a monitor' => [
            [202 => new ResponseMetadata(ResponseShapeKind::Accepted, 'jobs', null, null, null, null)],
            [ParameterFamily::Fields],
        ];
        yield 'meta' => [
            [200 => new ResponseMetadata(ResponseShapeKind::Meta, null, null, null, null, new IntegerCodec())],
            [],
        ];
        yield 'no content' => [
            [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            [],
        ];
    }
}
