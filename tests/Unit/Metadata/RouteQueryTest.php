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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\ParameterFamilies;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteQuery::class)]
final class RouteQueryTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteQuery::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testARouteOfAKindReadsItsFamiliesAndWhatItsTypeDeclaresForTheKind(): void
    {
        $route = RouteQuery::ofKind(OperationKind::FetchCollection, 'pets', null, self::registry());

        self::assertSame(ParameterFamilies::processedBy(OperationKind::FetchCollection, null), $route->families);
        self::assertSame(['nearBy'], $route->declaredNames());
    }

    public function testARouteOfARelationshipReadsTheFamiliesOfItsCardinalityAndWhatTheRelationshipDeclares(): void
    {
        $route = RouteQuery::ofKind(OperationKind::FetchRelationship, 'pets', 'orders', self::registry());

        self::assertSame([ParameterFamily::Page, ParameterFamily::Include, ParameterFamily::Fields], $route->families);
        self::assertSame(['placedSince'], $route->declaredNames());
    }

    public function testAnEndpointReadsTheFamiliesOfItsResponsesAndItsOwnParameters(): void
    {
        $endpoint = Samples::endpoint(
            queryParameters: [self::optional('adoptionNote')],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
        );
        $route = RouteQuery::ofEndpoint($endpoint);

        self::assertSame(ParameterFamilies::processedByEndpoint($endpoint), $route->families);
        self::assertSame($endpoint->queryParameters, $route->declared);
    }

    public function testAnAtomicRouteReadsTheFieldsAlone(): void
    {
        $route = RouteQuery::ofAtomicRoute();

        self::assertSame([ParameterFamily::Fields], $route->families);
        self::assertSame([], $route->declared);
    }

    public function testTheQueryOfARouteOfTheTableIsThatOfItsKindItsEndpointOrAnAtomicRoute(): void
    {
        $registry = self::registry();
        $endpoint = Samples::endpoint(queryParameters: [self::optional('adoptionNote')]);

        self::assertEquals(
            RouteQuery::ofKind(OperationKind::FetchCollection, 'pets', null, $registry),
            RouteQuery::ofRoute(
                new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets']),
                $registry,
            ),
        );
        self::assertEquals(
            RouteQuery::ofEndpoint($endpoint),
            RouteQuery::ofRoute(new EndpointRoute($endpoint), $registry),
        );
        self::assertEquals(
            RouteQuery::ofAtomicRoute(),
            RouteQuery::ofRoute(new AtomicRoute(new AtomicRouteMetadata(
                'atomicOperations',
                PathTemplate::written('/operations', [], 'the atomic route'),
                ['pets'],
                null,
                null,
            )), $registry),
        );
    }

    public function testReadsTheFamiliesItProcessesAndTheNamesItDeclares(): void
    {
        $route = new RouteQuery([ParameterFamily::Include], [self::optional('nearBy')]);

        self::assertTrue($route->reads('include'));
        self::assertTrue($route->reads('nearBy'));
        self::assertFalse($route->reads('fields'));
        self::assertFalse($route->reads('uiLocale'));
        self::assertFalse($route->reads('_method'));
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [Samples::toMany('orders', 'orders', queryParameters: [
                    OperationKind::FetchRelationship->value => [self::optional('placedSince')],
                ])],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
                queryParameters: [
                    OperationKind::FetchCollection->value => [self::optional('nearBy')],
                    OperationKind::FetchResource->value => [self::optional('previewMode')],
                ],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);
    }

    private static function optional(string $name): QueryParameterMetadata
    {
        return new QueryParameterMetadata($name, new StringCodec(), false);
    }
}
