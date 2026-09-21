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
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\OpenApi\OpenApiBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use stdClass;

/**
 * `api()` is built so that every arm of the `Allow` rule shows in one table:
 * `pets.owner` declares replace, `pets.orders` add and remove but no replace,
 * `pets.tags` no update, and `customers` binds no capability beyond the resource loader,
 * so its collection path allows nothing
 *
 * @internal
 */
#[CoversClass(RouteTable::class)]
final class RouteTableTest extends TestCase
{
    private const REST_NAMESPACE = 'acme/v1';

    /**
     * `customers` enables neither a collection read nor a create,
     * so the contract has no entry for this path while the table still lists its routes
     */
    private const UNPUBLISHED_COLLECTION = '/customers';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteTable::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * the property list pins that the table keeps no reference to the registry
     */
    public function testTheConstructorAndTheMethodsKeepTheirParametersAndTypes(): void
    {
        $constructor = (new ReflectionMethod(RouteTable::class, '__construct'))->getParameters();
        $of = (new ReflectionMethod(RouteTable::class, 'of'))->getParameters();
        $enables = (new ReflectionMethod(RouteTable::class, 'enables'))->getParameters();
        $allowedMethods = (new ReflectionMethod(RouteTable::class, 'allowedMethods'))->getParameters();
        $fetches = (new ReflectionMethod(RouteTable::class, 'fetches'))->getParameters();
        $fetchRoute = (new ReflectionMethod(RouteTable::class, 'fetchRoute'))->getParameters();

        self::assertSame(['routes', 'resources'], ParameterNames::of($constructor));
        self::assertSame(['array', ResourceRegistry::class], self::types($constructor));
        self::assertSame(['resources', 'endpoints', 'atomicRoutes'], ParameterNames::of($of));
        self::assertSame([ResourceRegistry::class, 'array', 'array'], self::types($of));
        self::assertSame([], ParameterNames::of((new ReflectionMethod(RouteTable::class, 'all'))->getParameters()));
        self::assertSame(['route'], ParameterNames::of($enables));
        self::assertSame([Route::class], self::types($enables));
        self::assertSame(['route'], ParameterNames::of($allowedMethods));
        self::assertSame(['?' . Route::class], self::types($allowedMethods));
        self::assertSame(['kind', 'type', 'relationship'], ParameterNames::of($fetches));
        self::assertSame([RouteKind::class, 'string', '?string'], self::types($fetches));
        self::assertSame(['kind', 'type', 'relationship'], ParameterNames::of($fetchRoute));
        self::assertSame([RouteKind::class, 'string', '?string'], self::types($fetchRoute));

        self::assertSame(
            ['enabled', 'allowed', 'fetchRoutes', 'routes'],
            array_map(
                static function (ReflectionProperty $property): string {
                    return $property->getName();
                },
                (new ReflectionClass(RouteTable::class))->getProperties(),
            ),
        );
    }

    public function testTheApiMetadataCarriesTheTableOfItsDeclaration(): void
    {
        $api = self::api();

        self::assertEquals(RouteTable::of($api->resources, [], $api->atomicRoutes), $api->routes);
        self::assertEquals(RouteTable::of($api->resources, [], []), self::api(false)->routes);
    }

    public function testEveryDeclaredTypeGetsItsFiveRoutes(): void
    {
        $table = self::table();

        foreach (['pets', 'customers', 'orders', 'tags'] as $type) {
            self::assertSame(
                [
                    'fetch_collection /' . $type . ' GET,HEAD',
                    'create_resource /' . $type . ' POST',
                ],
                self::routesUnder($table, '/' . $type),
            );
            self::assertSame(
                [
                    'fetch_resource /' . $type . '/{id} GET,HEAD',
                    'update_resource /' . $type . '/{id} PATCH',
                    'delete_resource /' . $type . '/{id} DELETE',
                ],
                self::routesUnder($table, '/' . $type . '/{id}'),
            );
        }
    }

    public function testEveryDeclaredRelationshipGetsTheRelatedRouteAndTheRelationshipRoute(): void
    {
        $table = self::table();

        foreach (['owner', 'orders', 'tags'] as $relationship) {
            self::assertSame(
                ['fetch_related /pets/{id}/' . $relationship . ' GET,HEAD'],
                self::routesUnder($table, '/pets/{id}/' . $relationship),
            );
            self::assertSame(
                [
                    'fetch_relationship /pets/{id}/relationships/' . $relationship . ' GET,HEAD',
                    'replace_relationship /pets/{id}/relationships/' . $relationship . ' PATCH',
                ],
                array_slice(self::routesUnder($table, '/pets/{id}/relationships/' . $relationship), 0, 2),
            );
        }
    }

    /**
     * `tags` declares no update at all and still gets both routes
     */
    public function testAToManyRelationshipAlsoGetsPostAndDeleteOnItsRelationshipRoute(): void
    {
        foreach (['orders', 'tags'] as $relationship) {
            self::assertSame(
                [
                    'fetch_relationship /pets/{id}/relationships/' . $relationship . ' GET,HEAD',
                    'replace_relationship /pets/{id}/relationships/' . $relationship . ' PATCH',
                    'add_to_relationship /pets/{id}/relationships/' . $relationship . ' POST',
                    'remove_from_relationship /pets/{id}/relationships/' . $relationship . ' DELETE',
                ],
                self::routesUnder(self::table(), '/pets/{id}/relationships/' . $relationship),
            );
        }
    }

    public function testAToOneRelationshipGetsNeitherPostNorDelete(): void
    {
        self::assertSame(
            [
                'fetch_relationship /pets/{id}/relationships/owner GET,HEAD',
                'replace_relationship /pets/{id}/relationships/owner PATCH',
            ],
            self::routesUnder(self::table(), '/pets/{id}/relationships/owner'),
        );
    }

    public function testARouteIsListedForAKindTheDeclarationDoesNotEnable(): void
    {
        $customers = self::api()->resources->get('customers');

        self::assertFalse($customers->enables(OperationKind::CreateResource));
        self::assertFalse($customers->enables(OperationKind::UpdateResource));
        self::assertFalse($customers->enables(OperationKind::DeleteResource));

        self::assertSame(
            ['create_resource /customers POST'],
            array_slice(self::routesUnder(self::table(), '/customers'), 1),
        );
        self::assertSame(
            ['update_resource /customers/{id} PATCH', 'delete_resource /customers/{id} DELETE'],
            array_slice(self::routesUnder(self::table(), '/customers/{id}'), 1),
        );
    }

    public function testARouteIsListedForARelationshipUpdateThatIsNotDeclared(): void
    {
        self::assertSame([], self::relationshipOf('pets', 'tags')->updates);
        self::assertSame(
            [
                'replace_relationship /pets/{id}/relationships/tags PATCH',
                'add_to_relationship /pets/{id}/relationships/tags POST',
                'remove_from_relationship /pets/{id}/relationships/tags DELETE',
            ],
            array_slice(self::routesUnder(self::table(), '/pets/{id}/relationships/tags'), 1),
        );
    }

    public function testAnAtomicRouteIsListedExactlyWhereTheApiDeclaresOne(): void
    {
        self::assertSame(['atomic operations /operations POST'], self::routesUnder(self::table(), '/operations'));
        self::assertSame([], self::routesUnder(self::api(false)->routes, '/operations'));
    }

    public function testTheAtomicRouteIsListedLast(): void
    {
        $routes = self::table()->all();
        $last = $routes[count($routes) - 1];

        self::assertInstanceOf(AtomicRoute::class, $last);
        self::assertSame('/operations', $last->template->path);
    }

    public function testTheAtomicRoutesAreListedLastInDeclarationOrder(): void
    {
        $all = Samples::atomicRoute();
        $pets = Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations');
        $routes = self::apiOf(self::excluding(null, null), null, [], [$all, $pets])->routes->all();
        $last = array_slice($routes, -2);

        self::assertSame(['/operations', '/pets/operations'], array_map(static function (Route $route): string {
            return $route->template->path;
        }, $last));
        self::assertInstanceOf(AtomicRoute::class, $last[0]);
        self::assertInstanceOf(AtomicRoute::class, $last[1]);
        self::assertSame($all, $last[0]->metadata);
        self::assertSame($pets, $last[1]->metadata);
    }

    /**
     * the resource route responds to GET, HEAD, PATCH and DELETE,
     * and the atomic route to POST alone,
     * so the two share a path the id codec admits without sharing a method
     */
    public function testAnAtomicRouteBesideTheResourceRouteOfItsTypeIsNoCollision(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name')],
            [],
            [Capability::CollectionReader, Capability::ResourceLoader],
            id: Samples::id(codec: self::wordCodec()),
        );
        $table = self::apiOf([$pets], null, [], [
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
        ])->routes;

        self::assertSame(['POST'], $table->allowedMethods(self::routeFor($table, '/pets/operations')));
        self::assertSame(['GET', 'HEAD'], $table->allowedMethods(self::routeFor($table, '/pets/{id}')));
    }

    public function testAnAtomicRouteAtTheCollectionOfATypeThatCreatesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the create_resource route of type "pets" on "/pets" and the atomic route "petOperations" on "/pets"'
            . ' both respond to POST',
        );

        self::apiOf(
            [Samples::resource('pets', [Samples::attribute('name')], [], [Capability::ResourceCreator])],
            null,
            [],
            [Samples::atomicRoute(['pets'], 'petOperations', '/pets')],
        );
    }

    public function testAnEndpointThatRespondsToPostOnAPathTheAtomicRouteMatchesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the route of the endpoint "adoptPet" on "/pets/{id}" and the atomic route "petOperations" on'
            . ' "/pets/operations" both respond to POST',
        );

        self::apiOf(
            self::excluding(null, null),
            null,
            [Samples::endpoint(path: '/pets/{id}', codecs: ['id' => self::wordCodec()])],
            [Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations')],
        );
    }

    public function testTheRoutesFollowTheDeclarationOrderOfTheTypesAndOfTheRelationships(): void
    {
        self::assertSame(
            [
                '/pets',
                '/pets/{id}',
                '/pets/{id}/owner',
                '/pets/{id}/relationships/owner',
                '/pets/{id}/orders',
                '/pets/{id}/relationships/orders',
                '/pets/{id}/tags',
                '/pets/{id}/relationships/tags',
                '/customers',
                '/customers/{id}',
                '/orders',
                '/orders/{id}',
                '/tags',
                '/tags/{id}',
                '/operations',
            ],
            self::paths(self::table()),
        );
    }

    public function testTheRelatedRouteOfARelationshipIsListedBeforeItsRelationshipRoute(): void
    {
        $ofTheRelationship = array_values(array_filter(
            self::paths(self::table()),
            static function (string $path): bool {
                return str_starts_with($path, '/pets/{id}/');
            },
        ));

        self::assertSame(
            ['/pets/{id}/owner', '/pets/{id}/relationships/owner'],
            array_slice($ofTheRelationship, 0, 2),
        );
        self::assertSame(
            ['/pets/{id}/orders', '/pets/{id}/relationships/orders'],
            array_slice($ofTheRelationship, 2, 2),
        );
    }

    public function testTheMethodsOfOnePathAreListedInOneFixedOrder(): void
    {
        $table = self::table();

        self::assertSame(['GET', 'HEAD', 'POST'], self::methodsUnder($table, '/pets'));
        self::assertSame(['GET', 'HEAD', 'PATCH', 'DELETE'], self::methodsUnder($table, '/pets/{id}'));
        self::assertSame(['GET', 'HEAD'], self::methodsUnder($table, '/pets/{id}/orders'));
        self::assertSame(
            ['GET', 'HEAD', 'PATCH', 'POST', 'DELETE'],
            self::methodsUnder($table, '/pets/{id}/relationships/orders'),
        );
    }

    /**
     * a type with r relationships, m of them to-many, yields 5 + 3r + 2m routes;
     * an atomic route adds one route
     */
    public function testTheTableListsNoOtherRoute(): void
    {
        $api = self::apiOf([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toMany('orders', 'orders', true, false, [RelationshipUpdate::Add])],
                [Capability::RelatedLoader, Capability::RelationshipAdder],
            ),
            Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
        ], null);

        self::assertSame(
            [
                'fetch_collection /pets GET,HEAD',
                'create_resource /pets POST',
                'fetch_resource /pets/{id} GET,HEAD',
                'update_resource /pets/{id} PATCH',
                'delete_resource /pets/{id} DELETE',
                'fetch_related /pets/{id}/orders GET,HEAD',
                'fetch_relationship /pets/{id}/relationships/orders GET,HEAD',
                'replace_relationship /pets/{id}/relationships/orders PATCH',
                'add_to_relationship /pets/{id}/relationships/orders POST',
                'remove_from_relationship /pets/{id}/relationships/orders DELETE',
                'fetch_collection /orders GET,HEAD',
                'create_resource /orders POST',
                'fetch_resource /orders/{id} GET,HEAD',
                'update_resource /orders/{id} PATCH',
                'delete_resource /orders/{id} DELETE',
            ],
            self::routes($api->routes),
        );

        // 5 + 3 * 1 + 2 * 1 for pets, 5 for orders, no atomic route
        self::assertCount(15, $api->routes->all());
        // 5 + 3 * 3 + 2 * 2 for pets, 5 for each other type, 1 for the atomic route
        self::assertCount(18 + 5 + 5 + 5 + 1, self::table()->all());
    }

    public function testThePathsAreThePathsOfThePublishedContract(): void
    {
        $published = array_keys((array) (new OpenApiBuilder(self::api()))->document()->paths);
        $listed = self::paths(self::table());

        self::assertSame([self::UNPUBLISHED_COLLECTION], array_values(array_diff($listed, $published)));
        self::assertSame($published, array_values(array_diff($listed, [self::UNPUBLISHED_COLLECTION])));
    }

    /**
     * an adapter groups the routes of one template into one route with several handlers
     */
    public function testTheRoutesOfOnePathShareOneTemplateAndItsIdParameter(): void
    {
        $routes = self::routesOf(self::table(), '/pets/{id}');

        self::assertCount(3, $routes);

        foreach ($routes as $route) {
            self::assertInstanceOf(OperationRoute::class, $route);
            self::assertEquals($routes[0]->template, $route->template);
            self::assertEquals(new PathParameter('id', new DigitsCodec()), $route->idParameter);
        }
    }

    public function testEveryRouteOfATypeNamesItsIdAsTheTypeDoes(): void
    {
        $table = self::apiOf([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toMany('orders', 'orders')],
                [Capability::RelatedLoader],
                idParameterName: 'pet_id',
            ),
            Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
        ], null)->routes;

        self::assertSame(
            [
                '/pets',
                '/pets/{pet_id}',
                '/pets/{pet_id}/orders',
                '/pets/{pet_id}/relationships/orders',
                '/orders',
                '/orders/{id}',
            ],
            self::paths($table),
        );
    }

    public function testEveryRouteOfATypeLiesBelowThePathItDeclares(): void
    {
        $table = self::apiOf([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [Samples::toMany('orders', 'orders')],
                [Capability::RelatedLoader],
                idParameterName: 'pet_id',
                path: ['shop', 'pets'],
            ),
            Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
        ], null)->routes;

        self::assertSame(
            [
                '/shop/pets',
                '/shop/pets/{pet_id}',
                '/shop/pets/{pet_id}/orders',
                '/shop/pets/{pet_id}/relationships/orders',
                '/orders',
                '/orders/{id}',
            ],
            self::paths($table),
        );
    }

    public function testATypeAtThePathOfTheCollectionOfAnotherTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the fetch_collection route of type "orders" on "/orders" and the fetch_collection route of type "pets" on'
            . ' "/orders" both respond to GET',
        );

        self::apiOf([
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource('pets', capabilities: [Capability::CollectionReader], path: ['orders']),
        ], null);
    }

    /**
     * the id codec of `database` admits digits alone, so no path matches both routes of GET
     */
    public function testALiteralOfATypePathBesideTheIdOfAnotherTypeIsNoCollision(): void
    {
        $table = self::apiOf([
            Samples::resource('database'),
            Samples::resource('tables', capabilities: [Capability::CollectionReader], path: ['database', 'tables']),
        ], null)->routes;

        self::assertSame(
            ['/database', '/database/{id}', '/database/tables', '/database/tables/{id}'],
            self::paths($table),
        );
    }

    public function testAnEndpointTakesThePlaceOfAnExcludedReadAtThePathOfTheType(): void
    {
        $endpoint = Samples::endpoint(
            'shopPet',
            'GET',
            '/shop/pets/{id}',
            ['id' => 'pets'],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
        );
        $routes = self::apiOf([
            Samples::resource(
                'pets',
                capabilities: [Capability::ResourceLoader],
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
                path: ['shop', 'pets'],
            ),
        ], null, [$endpoint])->routes;

        $fetchRoute = $routes->fetchRoute(RouteKind::Resource, 'pets', null);

        self::assertInstanceOf(EndpointRoute::class, $fetchRoute);
        self::assertSame($endpoint, $fetchRoute->endpoint);
    }

    public function testNoPathCarriesTheRestNamespace(): void
    {
        self::assertSame(self::REST_NAMESPACE, self::api()->restNamespace);

        foreach (self::table()->all() as $route) {
            self::assertStringStartsNotWith('/' . self::REST_NAMESPACE, $route->template->path);
            self::assertStringNotContainsString('acme', $route->template->path);
        }
    }

    /**
     * the same instances, not equal copies rebuilt per call
     */
    public function testAllReturnsTheSameListOnEveryCall(): void
    {
        $table = self::table();

        self::assertSame($table->all(), $table->all());
    }

    public function testAllowedMethodsListEveryMethodOfThatPathOnceInTableOrder(): void
    {
        $table = self::table();

        self::assertSame(['GET', 'HEAD', 'POST'], $table->allowedMethods(self::routeFor($table, '/pets')));
        self::assertSame(
            ['GET', 'HEAD', 'PATCH', 'DELETE'],
            $table->allowedMethods(self::routeFor($table, '/pets/{id}')),
        );
        self::assertSame(
            ['GET', 'HEAD', 'PATCH'],
            $table->allowedMethods(self::routeFor($table, '/pets/{id}/relationships/owner')),
        );
    }

    public function testAllowedMethodsOfAReadingNameHeadBesideGet(): void
    {
        $table = self::table();

        self::assertSame(
            ['GET', 'HEAD'],
            $table->allowedMethods(self::routeFor($table, '/pets/{id}/orders')),
        );
    }

    public function testAllowedMethodsOfTheAtomicRouteArePostAlone(): void
    {
        $table = self::table();

        self::assertSame(
            ['POST'],
            $table->allowedMethods(self::routeFor($table, '/operations')),
        );
    }

    public function testAllowedMethodsAreEmptyForNoRoute(): void
    {
        self::assertSame([], self::table()->allowedMethods(null));
    }

    public function testAllowedMethodsAreEmptyForAPathTheTableDoesNotList(): void
    {
        $foreign = new OperationRoute(
            OperationKind::FetchResource,
            'ghosts',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['ghosts'],
        );

        self::assertSame([], self::table()->allowedMethods($foreign));
    }

    public function testAllowedMethodsAreNarrowedByNoPermission(): void
    {
        $table = self::table();
        $route = self::routeFor($table, '/pets/{id}');
        $others = self::apiOf(
            array_map(self::withOtherPermissions(...), self::api()->resources->all()),
            ImplementationReference::service('implementation'),
        )->routes;

        self::assertSame($table->allowedMethods($route), $others->allowedMethods($route));
        self::assertSame(['GET', 'HEAD', 'PATCH', 'DELETE'], $others->allowedMethods($route));
    }

    public function testAllowedMethodsAreTheSameForAnyRouteOfOnePath(): void
    {
        $table = self::table();

        foreach (self::routesOf($table, '/pets/{id}') as $route) {
            self::assertSame(['GET', 'HEAD', 'PATCH', 'DELETE'], $table->allowedMethods($route));
        }
    }

    public function testAllowedMethodsLeaveOutAKindTheDeclarationDoesNotEnable(): void
    {
        $table = self::table();

        self::assertSame(
            ['GET', 'HEAD'],
            $table->allowedMethods(self::routeFor($table, '/customers/{id}')),
        );
        self::assertSame(
            ['GET', 'HEAD'],
            $table->allowedMethods(self::routeFor($table, '/orders')),
        );
    }

    /**
     * `pets` enables `ReplaceRelationship` through `owner`,
     * so only the relationship-level check drops the PATCH of `orders`
     */
    public function testAllowedMethodsLeaveOutARelationshipUpdateThatIsNotDeclared(): void
    {
        $table = self::table();

        self::assertTrue(self::api()->resources->get('pets')->enables(OperationKind::ReplaceRelationship));
        self::assertSame(
            ['GET', 'HEAD', 'POST', 'DELETE'],
            $table->allowedMethods(self::routeFor($table, '/pets/{id}/relationships/orders')),
        );
        self::assertSame(
            ['GET', 'HEAD'],
            $table->allowedMethods(self::routeFor($table, '/pets/{id}/relationships/tags')),
        );
    }

    public function testAllowedMethodsAreEmptyForAPathWhoseEveryMethodTheDeclarationLeavesUnsupported(): void
    {
        $table = self::table();

        self::assertSame([], $table->allowedMethods(self::routeFor($table, self::UNPUBLISHED_COLLECTION)));
    }

    public function testAllReturnsTheRouteAllowedMethodsLeavesOut(): void
    {
        $table = self::table();

        self::assertSame(
            ['fetch_collection /customers GET,HEAD', 'create_resource /customers POST'],
            self::routesUnder($table, '/customers'),
        );
        self::assertSame([], $table->allowedMethods(self::routeFor($table, '/customers')));
    }

    public function testNoTwoRoutesOfOnePathRespondToTheSameMethod(): void
    {
        foreach (self::paths(self::table()) as $path) {
            $methods = self::methodsUnder(self::table(), $path);

            self::assertSame(array_values(array_unique($methods)), $methods, 'the path ' . $path);
        }
    }

    public function testTwoRoutesOfOnePathAndOneMethodAreRefusedWhenTheApiIsBuilt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the create_resource route of type "operations" on "/operations" and the atomic route'
            . ' "atomicOperations" on "/operations" both respond to POST, and a path can match both, so no registered'
            . ' route and no published contract could tell them apart',
        );

        self::apiOf(
            [Samples::resource('operations', [Samples::attribute('name')], [], [Capability::CollectionReader])],
            ImplementationReference::service('boundary'),
        );
    }

    /**
     * the refusal is about the collision, not the name
     */
    public function testATypeNamedOperationsIsRoutedWhereNoAtomicRouteLiesAtItsPath(): void
    {
        $table = self::apiOf(
            [Samples::resource('operations', [Samples::attribute('name')], [], [Capability::CollectionReader])],
            null,
        )->routes;

        $first = $table->all()[0];

        self::assertInstanceOf(OperationRoute::class, $first);
        self::assertSame('/operations', $first->template->path);
        self::assertSame(OperationKind::FetchCollection, $first->operationKind);
    }

    /**
     * no declaration lists one route twice, so the table is built from routes given one by one
     */
    public function testTwoRoutesOfOneTemplateThatRespondToOneMethodAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the fetch_resource route of type "pets" on "/pets/{id}" and the fetch_resource route of type "pets" on'
            . ' "/pets/{id}" both respond to GET',
        );

        new RouteTable(
            [
                self::resourceRoute(OperationKind::FetchResource, 'id'),
                self::resourceRoute(OperationKind::FetchResource, 'id'),
            ],
            self::api()->resources,
        );
    }

    /**
     * no declaration names two parameters for one type,
     * so the table is built from routes given one by one
     */
    public function testTwoTemplatesOfOneHierarchyThatNameTheirParametersApartAreRefusedWhateverTheirMethods(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the fetch_resource route of type "pets" on "/pets/{id}" and the delete_resource route of type "pets" on'
            . ' "/pets/{pet_id}" lie on one path hierarchy and name its parameters differently, and OpenAPI says'
            . ' "Templated paths with the same hierarchy but different templated names MUST NOT exist as they'
            . ' are identical": name them "id", as the first route does',
        );

        new RouteTable(
            [
                self::resourceRoute(OperationKind::FetchResource, 'id'),
                self::resourceRoute(OperationKind::DeleteResource, 'pet_id'),
            ],
            self::api()->resources,
        );
    }

    /**
     * @param list<array{OperationKind, string}> $routes kind and id parameter per route of pets
     */
    #[DataProvider('firstCollisions')]
    public function testTheFirstCollisionInTableOrderIsRefused(array $routes, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RouteTable(array_map(static function (array $route): OperationRoute {
            return self::resourceRoute($route[0], $route[1]);
        }, $routes), self::api()->resources);
    }

    /**
     * @return iterable<string, array{list<array{OperationKind, string}>, string}>
     */
    public static function firstCollisions(): iterable
    {
        $apart = 'the fetch_resource route of type "pets" on "/pets/{id}" and the delete_resource route of type'
            . ' "pets" on "/pets/{pet_id}" lie on one path hierarchy';
        $overlap = 'the fetch_resource route of type "pets" on "/pets/{id}" and the fetch_resource route of type'
            . ' "pets" on "/pets/{pet_id}" both respond to GET';

        yield 'parameters named apart before an overlap' => [
            [
                [OperationKind::FetchResource, 'id'],
                [OperationKind::DeleteResource, 'pet_id'],
                [OperationKind::FetchResource, 'pet_id'],
            ],
            $apart,
        ];
        yield 'an overlap before parameters named apart' => [
            [
                [OperationKind::FetchResource, 'id'],
                [OperationKind::FetchResource, 'pet_id'],
                [OperationKind::DeleteResource, 'pet_id'],
            ],
            $overlap,
        ];
        yield 'one pair that overlaps and names its parameters apart' => [
            [[OperationKind::FetchResource, 'id'], [OperationKind::FetchResource, 'pet_id']],
            $overlap,
        ];
        yield 'the least earlier route first, whatever the later one' => [
            [
                [OperationKind::FetchResource, 'id'],
                [OperationKind::UpdateResource, 'id'],
                [OperationKind::UpdateResource, 'id'],
                [OperationKind::DeleteResource, 'pet_id'],
            ],
            $apart,
        ];
    }

    /**
     * the pair of the relationship routes is met first, and the pair of the resource routes,
     * which starts earlier, is refused
     */
    public function testOfTwoHierarchiesThePairThatStartsFirstIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the fetch_resource route of type "pets" on "/pets/{id}" and the delete_resource route of type "pets" on'
            . ' "/pets/{pet_id}" lie on one path hierarchy',
        );

        new RouteTable(
            [
                self::resourceRoute(OperationKind::FetchResource, 'id'),
                new OperationRoute(
                    OperationKind::FetchRelationship,
                    'pets',
                    'owner',
                    new PathParameter('id', new DigitsCodec()),
                    ['pets'],
                ),
                new OperationRoute(
                    OperationKind::ReplaceRelationship,
                    'pets',
                    'owner',
                    new PathParameter('pet_id', new DigitsCodec()),
                    ['pets'],
                ),
                self::resourceRoute(OperationKind::DeleteResource, 'pet_id'),
            ],
            self::api()->resources,
        );
    }

    /**
     * the second endpoint stands as a route of its own, so the two collide in declaration order
     */
    public function testTwoEndpointsThatTakeThePlaceOfOneReadCollide(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the route of the endpoint "first" on "/pets/{id}" and the route of the endpoint "second" on'
            . ' "/pets/{id}" both respond to GET',
        );

        $responses = [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)];
        self::apiOf(self::excluding(OperationKind::FetchResource, null), null, [
            Samples::endpoint('first', 'GET', '/pets/{id}', ['id' => 'pets'], responses: $responses),
            Samples::endpoint('second', 'GET', '/pets/{id}', ['id' => 'pets'], responses: $responses),
        ]);
    }

    public function testTheRoutesOfOneHierarchyThatNameTheirParameterAlikeStandTogether(): void
    {
        $fetch = self::resourceRoute(OperationKind::FetchResource, 'pet_id');
        $delete = self::resourceRoute(OperationKind::DeleteResource, 'pet_id');

        $table = new RouteTable([$fetch, $delete], self::api()->resources);

        self::assertSame([$fetch, $delete], $table->all());
        self::assertSame(['GET', 'HEAD', 'DELETE'], $table->allowedMethods($delete));
    }

    public function testARouteOfATypeTheRegistryDoesNotDeclareIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);

        new RouteTable(
            [new OperationRoute(OperationKind::FetchCollection, 'ghosts', null, null, ['ghosts'])],
            self::api()->resources,
        );
    }

    public function testEnablesReturnsWhatTheDeclarationEnables(): void
    {
        $table = self::table();

        self::assertTrue($table->enables(self::routeOf($table, OperationKind::FetchCollection, 'pets')));
        self::assertTrue($table->enables(self::routeOf($table, OperationKind::ReplaceRelationship, 'pets', 'owner')));
        self::assertFalse($table->enables(self::routeOf($table, OperationKind::ReplaceRelationship, 'pets', 'orders')));
        self::assertFalse($table->enables(self::routeOf($table, OperationKind::CreateResource, 'customers')));
        self::assertTrue($table->enables(self::routeFor($table, '/operations')));
    }

    /**
     * a route is known by identity: an equal copy is no route of the table
     */
    public function testEnablesReturnsFalseForARouteTheTableDoesNotList(): void
    {
        $table = self::table();
        $listed = self::routeOf($table, OperationKind::FetchCollection, 'pets');

        self::assertFalse(
            $table->enables(new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets'])),
        );
        self::assertEquals($listed, new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets']));
    }

    public function testFetchesTellsWhetherTheRouteRespondsToAGetWithData(): void
    {
        $table = self::table();

        self::assertTrue($table->fetches(RouteKind::Collection, 'pets', null));
        self::assertTrue($table->fetches(RouteKind::Resource, 'pets', null));
        self::assertTrue($table->fetches(RouteKind::Related, 'pets', 'owner'));
        self::assertTrue($table->fetches(RouteKind::Relationship, 'pets', 'orders'));
        self::assertFalse($table->fetches(RouteKind::Collection, 'customers', null), 'no collection reader');
        self::assertFalse($table->fetches(RouteKind::Related, 'pets', 'vet'), 'no such relationship');
        self::assertFalse($table->fetches(RouteKind::Resource, 'ghosts', null), 'no such type');
        self::assertFalse($table->fetches(RouteKind::Related, 'pets', null), 'a related route names its relationship');
    }

    public function testTheFetchRouteIsTheGeneratedReadWhileTheDeclarationEnablesIt(): void
    {
        $table = self::table();

        self::assertEquals(
            new OperationRoute(OperationKind::FetchResource, 'pets', null, self::resourceRoute(
                OperationKind::FetchResource,
                'id',
            )->idParameter, ['pets']),
            $table->fetchRoute(RouteKind::Resource, 'pets', null),
        );
        self::assertSame(
            OperationKind::FetchRelationship,
            self::fetchKindOf($table->fetchRoute(RouteKind::Relationship, 'pets', 'orders')),
        );
        self::assertNull($table->fetchRoute(RouteKind::Collection, 'customers', null), 'no collection reader');
        self::assertNull($table->fetchRoute(RouteKind::Related, 'pets', 'vet'), 'no such relationship');
    }

    public function testTheFetchRouteOfAnExcludedReadIsTheEndpointInItsPlace(): void
    {
        $endpoint = Samples::endpoint(
            'replacement',
            'GET',
            '/pets/{id}',
            ['id' => 'pets'],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
        );
        $excluded = self::excluding(OperationKind::FetchResource, null);

        self::assertNull(self::apiOf($excluded, null)->routes->fetchRoute(RouteKind::Resource, 'pets', null));

        $fetchRoute = self::apiOf($excluded, null, [$endpoint])->routes->fetchRoute(RouteKind::Resource, 'pets', null);

        self::assertInstanceOf(EndpointRoute::class, $fetchRoute);
        self::assertSame($endpoint, $fetchRoute->endpoint);
    }

    public function testAReplacementRequiresNoQueryParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "replacement", which takes the place of the fetch_resource route of'
            . ' type "pets", requires the query parameter "previewMode" for fetch_resource, and a document links to'
            . ' that route with no query parameter of its own, so every such link would get 400');

        self::apiOf(self::excluding(OperationKind::FetchResource, null), null, [
            Samples::endpoint(
                'replacement',
                'GET',
                '/pets/{id}',
                ['id' => 'pets'],
                queryParameters: [new QueryParameterMetadata('previewMode', new StringCodec(), true)],
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
            ),
        ]);
    }

    public function testAnExcludedRouteIsListedAndNeitherEnabledNorFetched(): void
    {
        $table = self::apiOf(
            [
                Samples::resource(
                    'pets',
                    [Samples::attribute('name')],
                    [
                        Samples::toOne(
                            'owner',
                            'customers',
                            excluded: RouteExclusion::except(
                                RouteOwner::Relationship,
                                [OperationKind::FetchRelated],
                                'relationship "owner"',
                            ),
                        ),
                    ],
                    [Capability::CollectionReader, Capability::ResourceLoader, Capability::ResourceUpdater],
                    excluded: RouteExclusion::except(
                        RouteOwner::Resource,
                        [OperationKind::FetchResource],
                        'type "pets"',
                    ),
                ),
                Samples::resource('customers', [Samples::attribute('name')]),
            ],
            null,
        )->routes;

        $fetch = self::routeOf($table, OperationKind::FetchResource, 'pets');
        $related = self::routeOf($table, OperationKind::FetchRelated, 'pets', 'owner');

        self::assertFalse($table->enables($fetch));
        self::assertFalse($table->enables($related));
        self::assertTrue($table->enables(self::routeOf($table, OperationKind::FetchRelationship, 'pets', 'owner')));
        self::assertFalse($table->fetches(RouteKind::Resource, 'pets', null));
        self::assertFalse($table->fetches(RouteKind::Related, 'pets', 'owner'));
        self::assertTrue($table->fetches(RouteKind::Relationship, 'pets', 'owner'));
        self::assertSame(['PATCH'], $table->allowedMethods($fetch));
        self::assertSame([], $table->allowedMethods($related));
    }

    public function testAnEndpointFollowsTheRoutesOfTheTypes(): void
    {
        $endpoint = Samples::endpoint();
        $table = self::apiOf(
            self::excluding(null, null),
            ImplementationReference::service('boundary'),
            [$endpoint],
        )->routes;
        $routes = $table->all();
        $route = $routes[count($routes) - 2];

        self::assertInstanceOf(EndpointRoute::class, $route);
        self::assertSame($endpoint, $route->endpoint);
        self::assertNull($route->replaces);
        self::assertInstanceOf(AtomicRoute::class, $routes[count($routes) - 1]);
        self::assertTrue($table->enables($route));
        self::assertSame(['POST'], $table->allowedMethods($route));
    }

    /**
     * @param array<string, string> $bindings
     */
    #[DataProvider('replacements')]
    public function testAnEndpointTakesThePlaceOfAnExcludedRead(
        OperationKind $kind,
        ?string $relationship,
        string $path,
        array $bindings,
        ResponseMetadata $response,
        RouteKind $routeKind,
    ): void {
        $endpoint = Samples::endpoint('replacement', 'GET', $path, $bindings, responses: [200 => $response]);
        $excluded = $relationship === null
            ? self::excluding($kind, null)
            : self::excluding(null, $kind);
        $before = self::apiOf($excluded, null)->routes;
        $table = self::apiOf($excluded, null, [$endpoint])->routes;
        $position = array_search(self::routeOf($before, $kind, 'pets', $relationship), $before->all(), true);

        self::assertIsInt($position);
        self::assertArrayHasKey($position, $table->all());

        $route = $table->all()[$position];

        self::assertInstanceOf(EndpointRoute::class, $route);
        self::assertSame($endpoint, $route->endpoint);
        self::assertSame($kind, $route->replaces?->operationKind);
        self::assertCount(count($before->all()), $table->all());
        self::assertFalse($before->fetches($routeKind, 'pets', $relationship));
        self::assertTrue($table->fetches($routeKind, 'pets', $relationship));
    }

    /**
     * @return iterable<string, array{
     *     OperationKind,
     *     ?string,
     *     string,
     *     array<string, string>,
     *     ResponseMetadata,
     *     RouteKind,
     * }>
     */
    public static function replacements(): iterable
    {
        yield 'the collection' => [
            OperationKind::FetchCollection, null, '/pets', [],
            new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null), RouteKind::Collection,
        ];
        yield 'the collection, a page at a time' => [
            OperationKind::FetchCollection, null, '/pets', [],
            new ResponseMetadata(ResponseShapeKind::Page, 'pets', null, null, null, null), RouteKind::Collection,
        ];
        yield 'a resource' => [
            OperationKind::FetchResource, null, '/pets/{id}', ['id' => 'pets'],
            new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null), RouteKind::Resource,
        ];
        yield 'the related route' => [
            OperationKind::FetchRelated, 'owner', '/pets/{id}/owner', ['id' => 'pets'],
            new ResponseMetadata(
                ResponseShapeKind::Related,
                'customers',
                'id',
                'owner',
                Cardinality::ToOne,
                null,
            ), RouteKind::Related,
        ];
        yield 'the relationship route' => [
            OperationKind::FetchRelationship, 'owner', '/pets/{id}/relationships/owner', ['id' => 'pets'],
            new ResponseMetadata(
                ResponseShapeKind::Linkage,
                'customers',
                'id',
                'owner',
                Cardinality::ToOne,
                null,
            ), RouteKind::Relationship,
        ];
    }

    public function testAnEndpointTakesThePlaceOfAReadRouteTheDeclarationExcludesAlone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "replacement" responds to GET on "/pets/{id}", the path of the'
            . ' fetch_resource route of type "pets", which the declaration keeps: an'
            . ' endpoint takes the place of a read route once the declaration excludes it'
            . ' with except() or only()');

        self::apiOf(self::excluding(null, null), null, [
            Samples::endpoint(
                'replacement',
                'GET',
                '/pets/{id}',
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
            ),
        ]);
    }

    public function testAReplacementBindsTheIdParameterToTheTypeOfTheRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "replacement" takes the place of the fetch_resource route of'
            . ' type "pets", and so names the parameter "id" as that route does and binds it'
            . ' to type "pets": every link to the route carries an id of that type, and a'
            . ' request for a missing record gets 404 as it does on the route');

        self::apiOf(self::excluding(OperationKind::FetchResource, null), null, [
            Samples::endpoint(
                'replacement',
                'GET',
                '/pets/{id}',
                [],
                ['id' => new UuidCodec()],
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null)],
            ),
        ]);
    }

    public function testAReplacementRespondsAsTheRouteResponded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "replacement" takes the place of the fetch_resource route of'
            . ' type "pets", and so responds with 200 and ResponseShape::record("id"), as every'
            . ' link to the route promises');

        self::apiOf(self::excluding(OperationKind::FetchResource, null), null, [
            Samples::endpoint(
                'replacement',
                'GET',
                '/pets/{id}',
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null)],
            ),
        ]);
    }

    public function testAReplacementOfTheCollectionRespondsWithThatCollection(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "replacement" takes the place of the fetch_collection route of'
            . ' type "pets", and so responds with 200 and ResponseShape::page("pets") or'
            . ' ResponseShape::collection("pets"), as every link to the route promises');

        self::apiOf(self::excluding(OperationKind::FetchCollection, null), null, [
            Samples::endpoint(
                'replacement',
                'GET',
                '/pets',
                [],
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Page, 'orders', null, null, null, null)],
            ),
        ]);
    }

    public function testAnEndpointOnTheWriteOfAGeneratedRouteCollides(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the update_resource route of type "pets" on "/pets/{id}" and the route of the'
            . ' endpoint "replacement" on "/pets/{id}" both respond to PATCH');

        self::apiOf(self::excluding(null, null), null, [
            Samples::endpoint('replacement', 'PATCH', '/pets/{id}'),
        ]);
    }

    public function testAnEndpointThatNamesTheParameterOfATypeApartIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lie on one path hierarchy and name its parameters differently');

        self::apiOf(self::excluding(null, null), null, [
            Samples::endpoint(
                'replacement',
                'PUT',
                '/pets/{pet}',
                ['pet' => 'pets'],
                responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'pet', null, null, null)],
            ),
        ]);
    }

    /**
     * @return list<ResourceMetadata>
     */
    private static function excluding(?OperationKind $typeKind, ?OperationKind $ownerKind): array
    {
        return [
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [
                    Samples::toOne(
                        'owner',
                        'customers',
                        excluded: $ownerKind === null
                            ? RouteExclusion::none(RouteOwner::Relationship)
                            : RouteExclusion::except(RouteOwner::Relationship, [$ownerKind], 'relationship "owner"'),
                    ),
                ],
                // a reader beside an excluded collection route serves nothing here, and is refused
                $typeKind === OperationKind::FetchCollection
                    ? [Capability::ResourceLoader, Capability::ResourceUpdater]
                    : [Capability::CollectionReader, Capability::ResourceLoader, Capability::ResourceUpdater],
                excluded: $typeKind === null
                    ? RouteExclusion::none(RouteOwner::Resource)
                    : RouteExclusion::except(RouteOwner::Resource, [$typeKind], 'type "pets"'),
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
        ];
    }

    private static function api(bool $atomic = true): ApiMetadata
    {
        return self::apiOf(
            [
                Samples::resource(
                    'pets',
                    [Samples::attribute('name')],
                    [
                        Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                        Samples::toMany('orders', 'orders', true, false, [
                            RelationshipUpdate::Add,
                            RelationshipUpdate::Remove,
                        ]),
                        Samples::toMany('tags', 'tags'),
                    ],
                    [
                        Capability::CollectionReader,
                        Capability::ResourceCreator,
                        Capability::ResourceUpdater,
                        Capability::ResourceDeleter,
                        Capability::RelatedLoader,
                        Capability::RelationshipReplacer,
                        Capability::RelationshipAdder,
                        Capability::RelationshipRemover,
                    ],
                ),
                Samples::resource('customers', [Samples::attribute('name')]),
                Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
                Samples::resource('tags', [Samples::attribute('label')], [], [Capability::CollectionReader]),
            ],
            $atomic ? ImplementationReference::service('boundary') : null,
        );
    }

    /**
     * @throws \PHPUnit\Framework\AssertionFailedError when there is no such relationship
     */
    private static function relationshipOf(string $type, string $name): RelationshipMetadata
    {
        foreach (self::api()->resources->get($type)->relationships as $relationship) {
            if ($relationship->name === $name) {
                return $relationship;
            }
        }

        self::fail(sprintf('the declaration writes no relationship "%s" of type "%s"', $name, $type));
    }

    /**
     * an id codec that admits a word such as `operations`
     */
    private static function wordCodec(): DescribedCodec
    {
        return new DescribedCodec(new Schema(type: JsonType::String), '[a-z]+');
    }

    /**
     * an API given atomic routes declares a transaction boundary for them
     *
     * @param list<ResourceMetadata>     $resources
     * @param list<EndpointMetadata>     $endpoints
     * @param ?list<AtomicRouteMetadata> $atomicRoutes
     */
    private static function apiOf(
        array $resources,
        ?ImplementationReference $transactionBoundary,
        array $endpoints = [],
        ?array $atomicRoutes = null,
    ): ApiMetadata {
        $registry = new ResourceRegistry($resources);

        if ($atomicRoutes !== null) {
            $transactionBoundary ??= ImplementationReference::service('boundary');
        }

        return new ApiMetadata(
            'petstore',
            '1.0',
            self::REST_NAMESPACE,
            $registry,
            $endpoints,
            $atomicRoutes ?? Samples::atomicRoutes($registry, $transactionBoundary),
            Samples::operators(),
            [],
            $transactionBoundary,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    private static function fetchKindOf(?Route $route): ?OperationKind
    {
        return $route instanceof OperationRoute ? $route->operationKind : null;
    }

    private static function table(): RouteTable
    {
        return self::api()->routes;
    }

    private static function withOtherPermissions(ResourceMetadata $resource): ResourceMetadata
    {
        return new ResourceMetadata(
            $resource->type,
            $resource->id,
            $resource->idParameterName,
            $resource->attributes,
            $resource->relationships,
            $resource->capabilities,
            array_map(
                static function (ImplementationReference $reference): ImplementationReference {
                    return ImplementationReference::instance(new stdClass());
                },
                $resource->permissions,
            ),
            $resource->monitorTypes,
            $resource->excluded,
            $resource->clientIdPolicy,
            $resource->versionPolicy,
            $resource->defaultSort,
            $resource->paginationPolicy,
            $resource->maxIncludeDepth,
            $resource->description,
        );
    }

    /**
     * as `<kind> <path> <methods>`; a kindless atomic route as `atomic operations`
     *
     * @return list<string>
     */
    private static function routes(RouteTable $table): array
    {
        return array_map(
            static function (Route $route): string {
                return sprintf(
                    '%s %s %s',
                    $route instanceof OperationRoute ? $route->operationKind->value : 'atomic operations',
                    $route->template->path,
                    implode(',', $route->methods),
                );
            },
            $table->all(),
        );
    }

    /**
     * @return list<string>
     */
    private static function routesUnder(RouteTable $table, string $path): array
    {
        return array_values(array_filter(
            self::routes($table),
            static function (string $route) use ($path): bool {
                return str_contains($route, ' ' . $path . ' ');
            },
        ));
    }

    /**
     * @return list<string>
     */
    private static function methodsUnder(RouteTable $table, string $path): array
    {
        $methods = [];

        foreach (self::routesOf($table, $path) as $route) {
            $methods = [...$methods, ...$route->methods];
        }

        return $methods;
    }

    /**
     * @return list<Route>
     */
    private static function routesOf(RouteTable $table, string $path): array
    {
        return array_values(array_filter(
            $table->all(),
            static function (Route $route) use ($path): bool {
                return $route->template->path === $path;
            },
        ));
    }

    private static function routeFor(RouteTable $table, string $path): Route
    {
        $routes = self::routesOf($table, $path);

        self::assertNotSame([], $routes, 'the table lists no route of ' . $path);

        return $routes[0];
    }

    private static function routeOf(
        RouteTable $table,
        OperationKind $kind,
        string $type,
        ?string $relationship = null,
    ): OperationRoute {
        foreach ($table->all() as $route) {
            if (
                $route instanceof OperationRoute
                && $route->operationKind === $kind
                && $route->type === $type
                && $route->relationship === $relationship
            ) {
                return $route;
            }
        }

        self::fail(sprintf('the table lists no %s route of type "%s"', $kind->value, $type));
    }

    private static function resourceRoute(OperationKind $kind, string $parameter): OperationRoute
    {
        return new OperationRoute($kind, 'pets', null, new PathParameter($parameter, new DigitsCodec()), ['pets']);
    }

    /**
     * @return list<string>
     */
    private static function paths(RouteTable $table): array
    {
        return array_values(array_unique(array_map(
            static function (Route $route): string {
                return $route->template->path;
            },
            $table->all(),
        )));
    }

    /**
     * @param list<ReflectionParameter> $parameters
     *
     * @return list<string>
     */
    private static function types(array $parameters): array
    {
        return array_map(static function (ReflectionParameter $parameter): string {
            return (string) $parameter->getType();
        }, $parameters);
    }
}
