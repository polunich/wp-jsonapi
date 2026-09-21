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

namespace Polunich\WpJsonApi\Tests\Unit\OpenApi;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\FormFieldMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\OpenApi\PathBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\SchemaMembers;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(PathBuilder::class)]
final class PathBuilderTest extends TestCase
{
    private const ATOMIC_MEDIA_TYPE = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    private const ACCEPT_LANGUAGE_REFERENCE = '#/components/parameters/Accept-Language.header';

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(PathBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * `customers` enables neither a collection reading nor a create, so it has no collection path
     */
    public function testWritesEveryRouteOfTheDeclarationInDeclarationOrder(): void
    {
        self::assertSame(
            [
                '/pets',
                '/pets/{id}',
                '/pets/{id}/owner',
                '/pets/{id}/relationships/owner',
                '/pets/{id}/orders',
                '/pets/{id}/relationships/orders',
                '/customers/{id}',
                '/orders',
                '/orders/{id}',
            ],
            array_keys((new PathBuilder(self::petstore()))->paths()),
        );
    }

    public function testEveryPathOfATypeLiesBelowThePathItDeclares(): void
    {
        $pets = Samples::resource(
            'pets',
            capabilities: [Capability::CollectionReader],
            idParameterName: 'pet_id',
            path: ['shop', 'pets'],
        );

        self::assertSame(['/shop/pets', '/shop/pets/{pet_id}'], array_keys(self::pathsOf(self::api([$pets]))));
    }

    /**
     * the `orders` relationship declares add and remove but no replace,
     * so its route carries no patch
     *
     * @param list<string> $expected
     */
    #[DataProvider('methods')]
    public function testWritesOnlyTheMethodsTheDeclarationEnables(string $path, array $expected): void
    {
        self::assertSame($expected, self::methodsOf(self::pathsOf(self::petstore()), $path));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function methods(): iterable
    {
        yield 'the collection route reads and creates' => ['/pets', ['get', 'post']];

        yield 'the resource route reads, updates and deletes' => ['/pets/{id}', ['get', 'patch', 'delete']];

        yield 'the related route reads' => ['/pets/{id}/owner', ['get']];

        yield 'a to-one relationship that declares replace' => ['/pets/{id}/relationships/owner', ['get', 'patch']];

        yield 'a to-many relationship that declares add and remove' => [
            '/pets/{id}/relationships/orders',
            ['get', 'post', 'delete'],
        ];

        yield 'a type that enables no write is read alone' => ['/customers/{id}', ['get']];

        yield 'a type with a reader and no writer' => ['/orders', ['get']];
    }

    /**
     * @param list<int> $expected
     */
    #[DataProvider('statuses')]
    public function testEachOperationRespondsWithTheStatusesItsRequestsReach(
        string $path,
        string $method,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            self::statusesOf(self::operationOf(self::pathsOf(self::petstore()), $path, $method)),
        );
    }

    /**
     * @return iterable<string, array{string, string, list<int>}>
     */
    public static function statuses(): iterable
    {
        yield 'a collection reading has no target to miss, so no 404' => [
            '/pets',
            'get',
            [200, 400, 401, 403, 406, 500],
        ];

        yield 'a create responds with 201 and the errors of a document' => [
            '/pets',
            'post',
            [201, 400, 401, 403, 404, 406, 409, 415, 422, 500],
        ];

        yield 'a resource reading' => ['/pets/{id}', 'get', [200, 400, 401, 403, 404, 406, 500]];

        yield 'an update is conditional and reads a document' => [
            '/pets/{id}',
            'patch',
            [200, 400, 401, 403, 404, 406, 409, 412, 415, 422, 500],
        ];

        yield 'a delete is conditional and reads no document' => [
            '/pets/{id}',
            'delete',
            [204, 400, 401, 403, 404, 406, 409, 412, 500],
        ];

        yield 'a related reading' => ['/pets/{id}/owner', 'get', [200, 400, 401, 403, 404, 406, 500]];

        yield 'a relationship reading' => [
            '/pets/{id}/relationships/owner',
            'get',
            [200, 400, 401, 403, 404, 406, 500],
        ];

        yield 'a relationship write responds with 200 with the linkage read back' => [
            '/pets/{id}/relationships/orders',
            'post',
            [200, 400, 401, 403, 404, 406, 409, 412, 415, 422, 500],
        ];
    }

    /**
     * a path parameter takes the name `idParameter()` gives it, here `pet_id`
     */
    public function testThePathsAndTheirParametersAreTheOnesOfTheRouteTable(): void
    {
        $resources = self::resources();
        $resources[0] = Samples::resource(
            'pets',
            [Samples::attribute('name')],
            [Samples::toMany('orders', 'orders')],
            [Capability::CollectionReader, Capability::RelatedLoader],
            idParameterName: 'pet_id',
        );
        $api = self::api($resources);
        $paths = self::pathsOf($api);
        $enabled = [];

        foreach ($api->routes->all() as $route) {
            if ($api->routes->enables($route)) {
                $enabled[$route->template->path] = true;
            }
        }

        self::assertSame(array_keys($enabled), array_keys($paths));
        self::assertSame('/pets/{pet_id}/relationships/orders', array_keys($paths)[3]);

        foreach (['/pets/{pet_id}', '/pets/{pet_id}/orders', '/pets/{pet_id}/relationships/orders'] as $path) {
            $parameters = self::listOf(SchemaMembers::object($paths, $path), 'parameters');

            self::assertCount(1, $parameters);
            self::assertIsArray($parameters[0]);
            self::assertSame('pet_id', $parameters[0]['name'] ?? null);
        }

        self::assertIsArray(self::listOf(SchemaMembers::object($paths, '/orders/{id}'), 'parameters')[0]);
        self::assertSame(
            'id',
            self::listOf(SchemaMembers::object($paths, '/orders/{id}'), 'parameters')[0]['name'] ?? null,
        );
    }

    public function testEveryRouteWithAnIdCarriesItAsAParameterOfThePathItem(): void
    {
        $paths = self::pathsOf(self::petstore());

        foreach (['/pets/{id}', '/pets/{id}/owner', '/pets/{id}/relationships/owner'] as $path) {
            $parameters = self::listOf(SchemaMembers::object($paths, $path), 'parameters');

            self::assertCount(1, $parameters);
            self::assertEquals(
                [
                    'name' => 'id',
                    'in' => 'path',
                    'required' => true,
                    'description' => 'the id of the resource of type "pets"',
                    'schema' => (object) ['type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]*)$'],
                ],
                $parameters[0],
            );
        }

        self::assertObjectNotHasProperty('parameters', SchemaMembers::object($paths, '/pets'));
    }

    public function testEveryOperationCarriesAUniqueIdThatNamesItsRoute(): void
    {
        $ids = [];

        foreach (self::pathsOf(self::petstore()) as $item) {
            foreach (get_object_vars($item) as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                self::assertInstanceOf(stdClass::class, $operation);
                $ids[] = SchemaMembers::text($operation, 'operationId');
            }
        }

        self::assertSame(array_unique($ids), $ids);
        self::assertContains('fetch_collection.pets', $ids);
        self::assertContains('remove_from_relationship.pets.orders', $ids);
        self::assertContains('fetch_related.pets.owner', $ids);
    }

    /**
     * @param array{string, string} $content the media type and the schema the response references
     */
    #[DataProvider('successfulResponses')]
    public function testASuccessfulResponseCarriesTheDocumentOfItsRoute(
        string $path,
        string $method,
        int $status,
        array $content,
    ): void {
        $response = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::petstore()), $path, $method),
                'responses',
            )),
            (string) $status,
        );

        self::assertEquals(
            [$content[0] => (object) ['schema' => (object) ['$ref' => '#/components/schemas/' . $content[1]]]],
            self::map($response, 'content'),
        );
    }

    /**
     * @return iterable<string, array{string, string, int, array{string, string}}>
     */
    public static function successfulResponses(): iterable
    {
        $jsonApi = 'application/vnd.api+json';

        yield 'a collection' => ['/pets', 'get', 200, [$jsonApi, 'pets.collection']];

        yield 'a created resource' => ['/pets', 'post', 201, [$jsonApi, 'pets.document']];

        yield 'one resource' => ['/pets/{id}', 'get', 200, [$jsonApi, 'pets.document']];

        yield 'an updated resource' => ['/pets/{id}', 'patch', 200, [$jsonApi, 'pets.document']];

        yield 'the related resources' => ['/pets/{id}/orders', 'get', 200, [$jsonApi, 'pets.orders.related']];

        yield 'the linkage of a relationship' => [
            '/pets/{id}/relationships/orders',
            'get',
            200,
            [$jsonApi, 'pets.orders.relationship'],
        ];

        yield 'the linkage a write left' => [
            '/pets/{id}/relationships/orders',
            'delete',
            200,
            [$jsonApi, 'pets.orders.relationship'],
        ];
    }

    /**
     * @param array{string, string} $content
     */
    #[DataProvider('requestBodies')]
    public function testEveryWriteReadsTheDocumentOfItsKind(string $path, string $method, array $content): void
    {
        $body = SchemaMembers::object(
            self::operationOf(self::pathsOf(self::petstore()), $path, $method),
            'requestBody',
        );

        self::assertTrue($body->required);
        self::assertEquals(
            [$content[0] => (object) ['schema' => (object) ['$ref' => '#/components/schemas/' . $content[1]]]],
            self::map($body, 'content'),
        );
    }

    /**
     * @return iterable<string, array{string, string, array{string, string}}>
     */
    public static function requestBodies(): iterable
    {
        $jsonApi = 'application/vnd.api+json';

        yield 'a create' => ['/pets', 'post', [$jsonApi, 'pets.create']];

        yield 'an update' => ['/pets/{id}', 'patch', [$jsonApi, 'pets.update']];

        yield 'a relationship replacement' => [
            '/pets/{id}/relationships/owner',
            'patch',
            [$jsonApi, 'pets.owner.update'],
        ];

        yield 'an addition to a relationship' => [
            '/pets/{id}/relationships/orders',
            'post',
            [$jsonApi, 'pets.orders.update'],
        ];
    }

    public function testADeleteReadsNoDocument(): void
    {
        $delete = self::operationOf(self::pathsOf(self::petstore()), '/pets/{id}', 'delete');

        self::assertObjectNotHasProperty('requestBody', $delete);
        self::assertNotContains(415, self::statusesOf($delete));
        self::assertNotContains(422, self::statusesOf($delete));
    }

    public function testAVersionedTypeReadsTheConditionalFieldsOfItsRoutes(): void
    {
        $paths = self::pathsOf(self::versionedPetstore(false));
        $reading = self::operationOf($paths, '/pets/{id}', 'get');

        self::assertContains('If-None-Match', self::parameterNames($reading));
        self::assertContains(304, self::statusesOf($reading));
        self::assertEquals(
            ['ETag', 'Vary'],
            array_keys(self::map(
                SchemaMembers::object(get_object_vars(SchemaMembers::object($reading, 'responses')), '304'),
                'headers',
            )),
        );

        $reading200 = SchemaMembers::object(get_object_vars(SchemaMembers::object($reading, 'responses')), '200');

        self::assertSame(
            ['Content-Language', 'Vary', 'ETag'],
            array_keys(self::map($reading200, 'headers')),
        );

        $write = self::operationOf($paths, '/pets/{id}', 'patch');

        self::assertContains('If-Match', self::parameterNames($write));
        self::assertNotContains(428, self::statusesOf($write));
    }

    public function testATypeThatRequiresPreconditionsRequiresIfMatch(): void
    {
        $write = self::operationOf(self::pathsOf(self::versionedPetstore(true)), '/pets/{id}', 'delete');
        $parameter = self::parameterOf($write, 'If-Match');

        self::assertTrue($parameter['required'] ?? null);
        self::assertContains(428, self::statusesOf($write));

        $unversioned = self::operationOf(self::pathsOf(self::petstore()), '/pets/{id}', 'delete');

        self::assertArrayNotHasKey('required', self::parameterOf($unversioned, 'If-Match'));
        self::assertNotContains(428, self::statusesOf($unversioned));
    }

    public function testAnUnversionedReadingDocumentsNoConditionalReading(): void
    {
        $reading = self::operationOf(self::pathsOf(self::petstore()), '/pets/{id}', 'get');

        self::assertNotContains('If-None-Match', self::parameterNames($reading));
        self::assertNotContains(304, self::statusesOf($reading));
    }

    public function testAMonitorTypeAddsTheAcceptedResponseOfItsKind(): void
    {
        $create = self::operationOf(self::pathsOf(self::acceptingPetstore()), '/pets', 'post');
        $accepted = SchemaMembers::object(get_object_vars(SchemaMembers::object($create, 'responses')), '202');

        self::assertContains(202, self::statusesOf($create));
        self::assertEquals(
            ['Content-Language', 'Vary', 'Content-Location'],
            array_keys(self::map($accepted, 'headers')),
        );
        self::assertEquals(
            ['application/vnd.api+json' => (object) ['schema' => (object) [
                '$ref' => '#/components/schemas/jobs.document',
            ]]],
            self::map($accepted, 'content'),
        );
        self::assertNotContains(
            202,
            self::statusesOf(self::operationOf(self::pathsOf(self::acceptingPetstore()), '/pets/{id}', 'patch')),
        );
    }

    public function testEvery401NamesTheChallengeFieldItCarries(): void
    {
        foreach (self::pathsOf(self::petstore()) as $item) {
            foreach (get_object_vars($item) as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                self::assertInstanceOf(stdClass::class, $operation);
                $unauthorized = SchemaMembers::object(
                    get_object_vars(SchemaMembers::object($operation, 'responses')),
                    '401',
                );

                self::assertSame(
                    ['Vary', 'WWW-Authenticate'],
                    array_keys(self::map($unauthorized, 'headers')),
                );
            }
        }
    }

    public function testAResponseWithoutContentNamesNoLanguage(): void
    {
        $delete = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::petstore()), '/pets/{id}', 'delete'),
                'responses',
            )),
            '204',
        );

        self::assertSame(['description'], array_keys((array) $delete));
    }

    public function testAnAtomicRouteIsWrittenWhereTheApiDeclaresOne(): void
    {
        self::assertArrayNotHasKey('/operations', self::pathsOf(self::petstore()));
        self::assertArrayHasKey('/operations', self::pathsOf(self::atomicPetstore()));
    }

    /**
     * a route of several types takes the tag of the extension, one of a type the tag of its type,
     * and a declared tag or description replaces the default
     */
    public function testEachAtomicRouteIsItsOwnOperation(): void
    {
        $paths = self::pathsOf(self::routedPetstore([
            Samples::atomicRoute(['pets', 'customers', 'orders']),
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
            Samples::atomicRoute(['orders'], 'orderOperations', '/orders/operations', 'orders', 'on orders'),
        ]));
        $all = self::operationOf($paths, '/operations', 'post');
        $pets = self::operationOf($paths, '/pets/operations', 'post');
        $orders = self::operationOf($paths, '/orders/operations', 'post');

        self::assertSame('atomicOperations', SchemaMembers::text($all, 'operationId'));
        self::assertSame(['atomic:operations'], self::listOf($all, 'tags'));
        self::assertSame('petOperations', SchemaMembers::text($pets, 'operationId'));
        self::assertSame(['pets'], self::listOf($pets, 'tags'));
        self::assertSame(SchemaMembers::text($all, 'description'), SchemaMembers::text($pets, 'description'));
        self::assertStringStartsWith(
            'performs the operations of the request in order and all or nothing',
            SchemaMembers::text($pets, 'description'),
        );
        self::assertSame(['orders'], self::listOf($orders, 'tags'));
        self::assertSame('on orders', SchemaMembers::text($orders, 'description'));
        self::assertEquals(
            [self::ATOMIC_MEDIA_TYPE => (object) ['schema' => (object) [
                '$ref' => '#/components/schemas/petOperations.operations',
            ]]],
            self::map(SchemaMembers::object($pets, 'requestBody'), 'content'),
        );
    }

    /**
     * an atomic operation carries no precondition,
     * so a route responds with 428 where a type it takes requires one
     */
    public function testAnAtomicRouteRespondsWithPreconditionRequiredForItsOwnTypes(): void
    {
        $resources = self::resources();
        $resources[0] = self::withVersionPolicy($resources[0], true);
        $paths = self::pathsOf(self::api($resources, atomicRoutes: [
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
            Samples::atomicRoute(['customers'], 'customerOperations', '/customers/operations'),
        ]));

        self::assertContains(428, self::statusesOf(self::operationOf($paths, '/pets/operations', 'post')));
        self::assertNotContains(428, self::statusesOf(self::operationOf($paths, '/customers/operations', 'post')));
    }

    public function testTheAtomicRouteReadsAndWritesTheExtendedMediaType(): void
    {
        $paths = self::pathsOf(self::atomicPetstore());

        self::assertSame(['post'], self::methodsOf($paths, '/operations'));

        $operation = self::operationOf($paths, '/operations', 'post');

        self::assertEquals(
            [self::ATOMIC_MEDIA_TYPE => (object) ['schema' => (object) [
                '$ref' => '#/components/schemas/atomicOperations.operations',
            ]]],
            self::map(SchemaMembers::object($operation, 'requestBody'), 'content'),
        );
        self::assertEquals(
            [self::ATOMIC_MEDIA_TYPE => (object) ['schema' => (object) [
                '$ref' => '#/components/schemas/atomicOperations.results',
            ]]],
            self::map(
                SchemaMembers::object(get_object_vars(SchemaMembers::object($operation, 'responses')), '200'),
                'content',
            ),
        );
        self::assertSame([200, 204, 400, 401, 403, 404, 406, 409, 415, 422, 500], self::statusesOf($operation));
    }

    public function testANameOutsideTheAlphabetOfAPathIsPercentEncoded(): void
    {
        $type = Samples::resource(
            'pet toys',
            [Samples::attribute('name')],
            [Samples::toMany('bought by', 'pet toys')],
            [Capability::CollectionReader, Capability::RelatedLoader],
        );

        self::assertSame(
            [
                '/pet%20toys',
                '/pet%20toys/{id}',
                '/pet%20toys/{id}/bought%20by',
                '/pet%20toys/{id}/relationships/bought%20by',
            ],
            array_keys((new PathBuilder(self::api([$type])))->paths()),
        );
    }

    public function testADeprecatedRelationshipDeprecatesItsRoutes(): void
    {
        $paths = self::pathsOf(self::api([
            Samples::resource(
                'pets',
                [Samples::attribute('name')],
                [new RelationshipMetadata(
                    'owner',
                    Cardinality::ToOne,
                    'pets',
                    null,
                    true,
                    false,
                    true,
                    [RelationshipUpdate::Replace],
                    RouteExclusion::none(RouteOwner::Relationship),
                    WriteRule::readOnly(),
                    null,
                    null,
                    null,
                    true,
                )],
                [Capability::RelatedLoader, Capability::RelationshipReplacer],
            ),
        ]));

        self::assertTrue(self::operationOf($paths, '/pets/{id}/owner', 'get')->deprecated);
        self::assertTrue(self::operationOf($paths, '/pets/{id}/relationships/owner', 'patch')->deprecated);
        self::assertObjectNotHasProperty('deprecated', self::operationOf($paths, '/pets/{id}', 'get'));
    }

    public function testEveryOperationReferencesTheLanguageSourcesInTheirOrderAfterItsOwnParameters(): void
    {
        $operations = 0;

        foreach (self::pathsOf(self::languagePetstore(self::uiLocaleThenField())) as $item) {
            foreach (get_object_vars($item) as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                self::assertInstanceOf(stdClass::class, $operation);
                self::assertSame(
                    ['#/components/parameters/uiLocale.query', self::ACCEPT_LANGUAGE_REFERENCE],
                    array_slice(self::parameterNames($operation), -2),
                );
                ++$operations;
            }
        }

        // the resource routes, the atomic route and the endpoint
        self::assertGreaterThan(10, $operations);
        self::assertSame(
            [
                'fields[pets]',
                'fields[customers]',
                'fields[orders]',
                '#/components/parameters/uiLocale.query',
                self::ACCEPT_LANGUAGE_REFERENCE,
            ],
            self::parameterNames(self::operationOf(
                self::pathsOf(self::languagePetstore(self::uiLocaleThenField())),
                '/operations',
                'post',
            )),
        );
    }

    public function testAnApiThatNegotiatesNoLanguageDescribesNoLanguageParameter(): void
    {
        $api = self::languagePetstore(new LanguageSources([]));

        self::assertSame(
            ['If-Match'],
            self::parameterNames(self::operationOf(self::pathsOf($api), '/pets/{id}', 'delete')),
        );
    }

    /**
     * the endpoint reads no form, so its 400 names no file of a form that arrived cut
     */
    public function testThe400OfEveryOperationNamesABadValueOfTheLanguageParameter(): void
    {
        $paths = self::pathsOf(self::languagePetstore(self::uiLocaleThenField()));
        $fault = 'the query parameter uiLocale that decides its language holds no basic language range';

        foreach ([['/pets/{id}', 'get'], ['/operations', 'post']] as [$path, $method]) {
            self::assertSame(
                'the request names a query parameter the route does not process, lacks one it requires, ' . $fault
                . ', or carries a value or a document it cannot read',
                SchemaMembers::text(
                    SchemaMembers::object(
                        get_object_vars(SchemaMembers::object(self::operationOf($paths, $path, $method), 'responses')),
                        '400',
                    ),
                    'description',
                ),
            );
        }

        self::assertSame(
            'the request names a query parameter the endpoint does not process, lacks one it requires, ' . $fault
            . ', or carries a value or a document it cannot read',
            SchemaMembers::text(
                SchemaMembers::object(
                    get_object_vars(SchemaMembers::object(
                        self::operationOf($paths, '/pets/{id}/adopt', 'post'),
                        'responses',
                    )),
                    '400',
                ),
                'description',
            ),
        );
    }

    public function testNoErrorResponseStatesALanguage(): void
    {
        foreach (self::pathsOf(self::languagePetstore(self::uiLocaleThenField())) as $item) {
            foreach (get_object_vars($item) as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }

                self::assertInstanceOf(stdClass::class, $operation);

                foreach (get_object_vars(SchemaMembers::object($operation, 'responses')) as $status => $response) {
                    self::assertInstanceOf(stdClass::class, $response);

                    if ($status >= 400) {
                        self::assertArrayNotHasKey('Content-Language', self::map($response, 'headers'));
                        self::assertArrayHasKey('Vary', self::map($response, 'headers'));
                    }
                }
            }
        }
    }

    public function testACreateNamesTheLocationOfTheResourceItMade(): void
    {
        $created = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::petstore()), '/pets', 'post'),
                'responses',
            )),
            '201',
        );

        self::assertSame(
            ['Content-Language', 'Vary', 'Location'],
            array_keys(self::map($created, 'headers')),
        );

        $versioned = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::versionedPetstore(false)), '/pets', 'post'),
                'responses',
            )),
            '201',
        );

        self::assertSame(
            ['Content-Language', 'Vary', 'Location', 'ETag'],
            array_keys(self::map($versioned, 'headers')),
        );
    }

    /**
     * the created resource of a type that excludes the fetch of its resources carries no self link,
     * which `Location` would repeat
     */
    public function testACreateOfATypeThatExcludesTheFetchOfItsResourcesNamesNoLocation(): void
    {
        $created = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::excludingPetstore()), '/pets', 'post'),
                'responses',
            )),
            '201',
        );

        self::assertSame(['Content-Language', 'Vary'], array_keys(self::map($created, 'headers')));
    }

    /**
     * an excluded route responds with 403 to every client,
     * so it has no successful response to publish;
     * a path whose every route is excluded is left out
     */
    public function testAnExcludedRouteIsLeftOutOfItsPath(): void
    {
        $paths = self::pathsOf(self::excludingPetstore());

        self::assertSame(['post'], self::methodsOf($paths, '/pets'));
        self::assertSame(['patch'], self::methodsOf($paths, '/pets/{id}'));
        self::assertSame(['get'], self::methodsOf($paths, '/pets/{id}/relationships/owner'));
        self::assertArrayNotHasKey('/pets/{id}/owner', $paths);
    }

    /**
     * an endpoint is the operation of its method on its own path, with its name as the operationId;
     * its tag is its own, else the type of its first bound parameter
     */
    public function testAnEndpointIsTheOperationOfItsMethodNamedByItsName(): void
    {
        $api = self::endpointPetstore([Samples::endpoint(), self::statsEndpoint()]);
        $paths = self::pathsOf($api);
        $adopt = self::operationOf($paths, '/pets/{id}/adopt', 'post');

        self::assertSame(['post'], self::methodsOf($paths, '/pets/{id}/adopt'));
        self::assertSame(['get'], self::methodsOf($paths, '/stats'));
        self::assertSame('adoptPet', SchemaMembers::text($adopt, 'operationId'));
        self::assertSame(['pets'], self::listOf($adopt, 'tags'));
        self::assertSame(['stats'], self::listOf(self::operationOf($paths, '/stats', 'get'), 'tags'));
    }

    public function testAnEndpointWithoutATagOrABoundParameterIsUntagged(): void
    {
        $paths = self::pathsOf(self::endpointPetstore([
            Samples::endpoint('stats', 'GET', '/stats', [], responses: self::statsEndpoint()->responses),
        ]));

        self::assertObjectNotHasProperty('tags', self::operationOf($paths, '/stats', 'get'));
    }

    /**
     * the operation carries the path parameters, which override those of the path item
     * when it shares one with a route of another codec (OpenAPI 3.1.2, Operation Object)
     */
    public function testAnEndpointCarriesThePathParametersInItsOperation(): void
    {
        $paths = self::pathsOf(self::endpointPetstore([
            Samples::endpoint(),
            Samples::endpoint(
                'yearReport',
                'GET',
                '/reports/{year}',
                [],
                ['year' => new DigitsCodec()],
                responses: self::statsEndpoint()->responses,
            ),
        ]));
        $adopt = self::operationOf($paths, '/pets/{id}/adopt', 'post');
        $report = self::operationOf($paths, '/reports/{year}', 'get');

        self::assertObjectNotHasProperty('parameters', SchemaMembers::object($paths, '/pets/{id}/adopt'));
        self::assertSame(
            ['id', 'include', 'fields[pets]', 'fields[customers]', 'fields[orders]', self::ACCEPT_LANGUAGE_REFERENCE],
            self::parameterNames($adopt),
        );
        self::assertSame('path', self::parameterOf($adopt, 'id')['in']);
        self::assertTrue(self::parameterOf($adopt, 'id')['required']);
        self::assertSame(
            'the id of the resource of type "pets" the endpoint loads; a missing one gets 404',
            self::parameterOf($adopt, 'id')['description'],
        );
        self::assertSame(
            'the parameter "year" of the path of the endpoint "yearReport"',
            self::parameterOf($report, 'year')['description'],
        );
    }

    /**
     * @param array<int, ResponseMetadata> $declared
     * @param list<string>               $expected
     */
    #[DataProvider('responseHeaders')]
    public function testEachResponseOfAnEndpointNamesTheHeaderFieldsItWrites(
        string $method,
        array $declared,
        int $status,
        array $expected,
    ): void {
        $api = self::endpointPetstore(
            [Samples::endpoint(method: $method, responses: $declared, preconditionParameter: 'id')],
            versioned: true,
        );
        $response = SchemaMembers::object(
            SchemaMembers::object(
                self::operationOf(self::pathsOf($api), '/pets/{id}/adopt', strtolower($method)),
                'responses',
            ),
            (string) $status,
        );

        self::assertSame($expected, array_keys(self::map($response, 'headers')));
    }

    /**
     * @return iterable<string, array{string, array<int, ResponseMetadata>, int, list<string>}>
     */
    public static function responseHeaders(): iterable
    {
        $record = [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)];
        $created = [201 => new ResponseMetadata(ResponseShapeKind::Created, 'pets', null, null, null, null)];

        yield 'the precondition record' => ['POST', $record, 200, ['Content-Language', 'Vary', 'ETag']];
        yield 'the precondition record in response to PUT' => ['PUT', $record, 200, ['Content-Language', 'Vary']];
        yield 'the created resource of a versioned type' => [
            'POST',
            $created,
            201,
            ['Content-Language', 'Vary', 'ETag', 'Location'],
        ];
        yield 'the created resource in response to PUT' => [
            'PUT',
            $created,
            201,
            ['Content-Language', 'Vary', 'Location'],
        ];
        yield 'a monitor' => [
            'POST',
            [202 => new ResponseMetadata(ResponseShapeKind::Accepted, 'jobs', null, null, null, null)],
            202,
            ['Content-Language', 'Vary', 'Content-Location'],
        ];
    }

    public function testNoContentOfAnEndpointHasNeitherContentNorHeaderFields(): void
    {
        $api = self::endpointPetstore([Samples::endpoint(responses: [204 => new ResponseMetadata(
            ResponseShapeKind::NoContent,
            null,
            null,
            null,
            null,
            null,
        )])]);
        $response = SchemaMembers::object(
            SchemaMembers::object(self::operationOf(self::pathsOf($api), '/pets/{id}/adopt', 'post'), 'responses'),
            '204',
        );

        self::assertSame(['description'], array_keys(get_object_vars($response)));
    }

    /**
     * a GET reads the precondition record,
     * so it is conditional on If-None-Match and can respond with 304
     */
    public function testASafeEndpointWithAPreconditionRecordReadsIfNoneMatch(): void
    {
        $api = self::endpointPetstore(
            [Samples::endpoint('petCard', 'GET', '/pets/{id}/card', preconditionParameter: 'id')],
            versioned: true,
            preconditionsRequired: true,
        );
        $operation = self::operationOf(self::pathsOf($api), '/pets/{id}/card', 'get');

        self::assertSame(
            [
                'id',
                'include',
                'fields[pets]',
                'fields[customers]',
                'fields[orders]',
                'If-None-Match',
                self::ACCEPT_LANGUAGE_REFERENCE,
            ],
            self::parameterNames($operation),
        );
        self::assertSame([200, 304, 400, 401, 403, 404, 406, 415, 500], self::statusesOf($operation));
    }

    /**
     * @param list<int> $statuses
     */
    #[DataProvider('conditionalWrites')]
    public function testAnUnsafeEndpointWithAPreconditionRecordReadsIfMatch(
        bool $leavesUnchanged,
        bool $required,
        array $statuses,
    ): void {
        $api = self::endpointPetstore(
            [Samples::endpoint(preconditionParameter: 'id', leavesUnchanged: $leavesUnchanged)],
            versioned: true,
            preconditionsRequired: true,
        );
        $operation = self::operationOf(self::pathsOf($api), '/pets/{id}/adopt', 'post');

        self::assertSame($required, self::parameterOf($operation, 'If-Match')['required'] ?? false);
        self::assertSame($statuses, self::statusesOf($operation));
    }

    /**
     * @return iterable<string, array{bool, bool, list<int>}>
     */
    public static function conditionalWrites(): iterable
    {
        yield 'a write of a resource changed only by a conditional request' => [
            false,
            true,
            [200, 400, 401, 403, 404, 406, 412, 415, 428, 500],
        ];
        yield 'a request that leaves the record unchanged' => [
            true,
            false,
            [200, 400, 401, 403, 404, 406, 412, 415, 500],
        ];
    }

    /**
     * 404 where the path has a parameter, 409 where the body names a type or an id,
     * 422 where there is a body
     *
     * @param list<int> $statuses
     */
    #[DataProvider('pipelines')]
    public function testTheErrorsOfAnEndpointAreThoseItsPipelineCanGive(
        EndpointMetadata $endpoint,
        string $path,
        array $statuses,
    ): void {
        $operation = self::operationOf(
            self::pathsOf(self::endpointPetstore([$endpoint])),
            $path,
            strtolower($endpoint->method),
        );

        self::assertSame($statuses, self::statusesOf($operation));
    }

    /**
     * @return iterable<string, array{EndpointMetadata, string, list<int>}>
     */
    public static function pipelines(): iterable
    {
        yield 'no parameter and no body' => [self::statsEndpoint(), '/stats', [200, 400, 401, 403, 406, 415, 500]];
        yield 'a body of linkage' => [
            Samples::endpoint(body: new BodyMetadata(BodyShapeKind::ToOne, 'customers', null, null)),
            '/pets/{id}/adopt',
            [200, 400, 401, 403, 404, 406, 409, 415, 422, 500],
        ];
        yield 'a body of meta' => [
            Samples::endpoint(body: new BodyMetadata(BodyShapeKind::Meta, null, null, new StringCodec())),
            '/pets/{id}/adopt',
            [200, 400, 401, 403, 404, 406, 415, 422, 500],
        ];
    }

    public function testTheBodyOfAnEndpointIsARequiredJsonApiDocument(): void
    {
        $paths = self::pathsOf(self::endpointPetstore([
            Samples::endpoint(body: new BodyMetadata(BodyShapeKind::ToOne, 'customers', null, null)),
            self::statsEndpoint(),
        ]));
        $body = SchemaMembers::object(self::operationOf($paths, '/pets/{id}/adopt', 'post'), 'requestBody');

        self::assertTrue($body->required);
        self::assertSame(
            'the body the endpoint "adoptPet" reads, picked by the Content-Type of the request',
            SchemaMembers::text($body, 'description'),
        );
        self::assertSame([MediaType::JSON_API], array_keys(self::map($body, 'content')));
        self::assertObjectNotHasProperty('requestBody', self::operationOf($paths, '/stats', 'get'));
    }

    /**
     * a JSON body by its codec, a form as an object of its fields and files,
     * bytes as an empty media type object
     */
    public function testTheBodiesInOtherMediaTypesAreDescribedByTheirKind(): void
    {
        $endpoint = Samples::endpoint(
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            mediaBodies: [
                new MediaBodyMetadata(self::mediaType('multipart/form-data'), MediaBodyShapeKind::Form, null, [
                    new FormFieldMetadata('caption', new StringCodec(), true),
                    new FormFieldMetadata('tags', new ListCodec(new StringCodec()), false),
                ], ['photo' => true]),
                new MediaBodyMetadata(
                    self::mediaType('application/json'),
                    MediaBodyShapeKind::Json,
                    new IntegerCodec(),
                ),
                new MediaBodyMetadata(self::mediaType('image/png'), MediaBodyShapeKind::Bytes),
            ],
        );
        $operation = self::operationOf(self::pathsOf(self::endpointPetstore([$endpoint])), '/pets/{id}/adopt', 'post');
        $body = SchemaMembers::object($operation, 'requestBody');
        $content = self::map($body, 'content');

        self::assertTrue($body->required);
        self::assertSame(['multipart/form-data', 'application/json', 'image/png'], array_keys($content));
        self::assertEquals(
            (object) [
                'schema' => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        'caption' => (new StringCodec())->schema()->toObject(),
                        'tags' => (new ListCodec(new StringCodec()))->schema()->toObject(),
                        'photo' => (object) ['description' => 'a file of the form'],
                    ],
                    'required' => ['caption', 'photo'],
                    'additionalProperties' => false,
                ],
                'encoding' => (object) ['tags' => (object) ['style' => 'deepObject', 'explode' => true]],
            ],
            $content['multipart/form-data'],
        );
        self::assertEquals(
            (object) ['schema' => (new IntegerCodec())->schema()->toObject()],
            $content['application/json'],
        );
        self::assertEquals(new stdClass(), $content['image/png']);
        $responses = get_object_vars(SchemaMembers::object($operation, 'responses'));

        self::assertSame([204, 400, 401, 403, 404, 406, 413, 415, 422, 500], array_keys($responses));
        self::assertArrayHasKey('Accept', self::map(SchemaMembers::object($responses, '415'), 'headers'));
    }

    public function testThe415OfAWriteNamesTheMediaTypesItReadsInAccept(): void
    {
        $create = self::operationOf(self::pathsOf(self::petstore()), '/pets', 'post');

        self::assertSame(
            ['Vary', 'Accept'],
            array_keys(self::map(
                SchemaMembers::object(get_object_vars(SchemaMembers::object($create, 'responses')), '415'),
                'headers',
            )),
        );
    }

    public function testAFormThatRequiresNothingNeedsNoContent(): void
    {
        $endpoint = Samples::endpoint(
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            mediaBodies: [
                new MediaBodyMetadata(
                    self::mediaType('application/x-www-form-urlencoded'),
                    MediaBodyShapeKind::Form,
                    null,
                    [
                        new FormFieldMetadata('note', new StringCodec(), false),
                    ],
                ),
            ],
        );
        $body = SchemaMembers::object(
            self::operationOf(self::pathsOf(self::endpointPetstore([$endpoint])), '/pets/{id}/adopt', 'post'),
            'requestBody',
        );

        self::assertObjectNotHasProperty('required', $body);
    }

    /**
     * each representation of 200 is its own entry of the content map:
     * text as a string, JSON by its codec, anything else as an empty media type object
     */
    public function testTheMediaResponsesOfAnEndpointStandBesideItsJsonApiResponse(): void
    {
        $endpoint = Samples::endpoint(
            'petPhoto',
            'GET',
            '/pets/{id}/photo',
            mediaResponses: [
                new MediaResponseMetadata(self::mediaType('image/png'), null),
                new MediaResponseMetadata(self::mediaType('text/csv'), null),
                new MediaResponseMetadata(self::mediaType('application/json'), new IntegerCodec()),
            ],
        );
        $response = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::endpointPetstore([$endpoint])), '/pets/{id}/photo', 'get'),
                'responses',
            )),
            '200',
        );
        $content = self::map($response, 'content');

        self::assertSame([MediaType::JSON_API, 'image/png', 'text/csv', 'application/json'], array_keys($content));
        self::assertEquals(new stdClass(), $content['image/png']);
        self::assertEquals((object) ['schema' => (object) ['type' => 'string']], $content['text/csv']);
        self::assertEquals(
            (object) ['schema' => (new IntegerCodec())->schema()->toObject()],
            $content['application/json'],
        );
        self::assertSame(
            ['Content-Language', 'Vary', 'Content-Disposition', 'Content-Length'],
            array_keys(self::map($response, 'headers')),
        );
    }

    /**
     * a JSON body and a form field are read from requests,
     * and a JSON media response is written,
     * so each carries the members of its direction alone
     */
    public function testTheMediaBodiesAndResponsesDescribeTheMembersOfTheirDirection(): void
    {
        $codec = new ObjectCodec(
            ['version' => new StringCodec(), 'secret' => new StringCodec()],
            readOnly: ['version'],
            writeOnly: ['secret'],
        );
        $endpoint = Samples::endpoint(
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            mediaBodies: [
                new MediaBodyMetadata(self::mediaType('application/json'), MediaBodyShapeKind::Json, $codec),
                new MediaBodyMetadata(
                    self::mediaType('application/x-www-form-urlencoded'),
                    MediaBodyShapeKind::Form,
                    null,
                    [new FormFieldMetadata('schedule', $codec, true)],
                ),
            ],
        );
        $photo = Samples::endpoint(
            'petPhoto',
            'GET',
            '/pets/{id}/photo',
            mediaResponses: [new MediaResponseMetadata(self::mediaType('application/json'), $codec)],
        );
        $paths = self::pathsOf(self::endpointPetstore([$endpoint, $photo]));
        $bodies = self::map(
            SchemaMembers::object(self::operationOf($paths, '/pets/{id}/adopt', 'post'), 'requestBody'),
            'content',
        );
        $responses = SchemaMembers::object(self::operationOf($paths, '/pets/{id}/photo', 'get'), 'responses');
        $contents = self::map(SchemaMembers::object(get_object_vars($responses), '200'), 'content');
        $form = SchemaMembers::object(SchemaMembers::object($bodies, 'application/x-www-form-urlencoded'), 'schema');
        $request = $codec->schema()->forRequest()->toObject();

        self::assertEquals((object) ['schema' => $request], $bodies['application/json']);
        self::assertEquals($request, SchemaMembers::object(SchemaMembers::object($form, 'properties'), 'schedule'));
        self::assertEquals(
            (object) ['schema' => $codec->schema()->forResponse()->toObject()],
            $contents['application/json'],
        );
    }

    public function testAnEndpointOfMediaResponsesAloneRespondsWith200WithThem(): void
    {
        $endpoint = Samples::endpoint(
            'exportOrders',
            'GET',
            '/orders/export',
            bindings: [],
            responses: [],
            mediaResponses: [new MediaResponseMetadata(self::mediaType('text/csv'), null)],
        );
        $response = SchemaMembers::object(
            get_object_vars(SchemaMembers::object(
                self::operationOf(self::pathsOf(self::endpointPetstore([$endpoint])), '/orders/export', 'get'),
                'responses',
            )),
            '200',
        );

        self::assertSame(
            'the media response of the endpoint "exportOrders"',
            SchemaMembers::text($response, 'description'),
        );
        self::assertSame(['text/csv'], array_keys(self::map($response, 'content')));
    }

    public function testAnEndpointInThePlaceOfAnExcludedReadIsWrittenUnderItsPath(): void
    {
        [$pets, $customers] = self::resources();
        $orders = Samples::resource(
            'orders',
            [Samples::attribute('address')],
            [],
            [Capability::CollectionReader],
            excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchCollection], 'type "orders"'),
        );
        $api = self::api([$pets, $customers, $orders], null, [Samples::endpoint(
            'listOrders',
            'GET',
            '/orders',
            [],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'orders', null, null, null, null)],
        )]);

        self::assertSame(
            'listOrders',
            SchemaMembers::text(self::operationOf(self::pathsOf($api), '/orders', 'get'), 'operationId'),
        );
    }

    public function testTheWritesBesideAnEndpointInThePlaceOfAnExcludedReadKeepThePathParameter(): void
    {
        $pets = Samples::resource(
            'pets',
            [Samples::attribute('name')],
            [],
            [Capability::ResourceLoader, Capability::ResourceUpdater, Capability::ResourceDeleter],
            excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
        );
        $paths = self::pathsOf(self::api([$pets], null, [Samples::endpoint('showPet', 'GET', '/pets/{id}')]));
        $item = SchemaMembers::object($paths, '/pets/{id}');

        self::assertSame(['parameters', 'get', 'patch', 'delete'], array_keys(get_object_vars($item)));
        self::assertSame(['id'], self::parameterNames($item));
        self::assertSame('path', self::parameterOf($item, 'id')['in']);
        self::assertSame('path', self::parameterOf(self::operationOf($paths, '/pets/{id}', 'get'), 'id')['in']);
    }

    public function testAnOperationThatDeclaresItsSchemesWritesItsOwnSecurityAndOthersInheritTheApis(): void
    {
        $nonce = SecurityScheme::restNonce();
        $api = self::api(
            [
                Samples::resource(
                    'pets',
                    relationships: [Samples::toOne(
                        'owner',
                        'pets',
                        securitySchemes: [OperationKind::FetchRelated->value => [$nonce]],
                    )],
                    securitySchemes: [OperationKind::FetchResource->value => []],
                ),
            ],
            endpoints: [Samples::endpoint(securitySchemes: [$nonce, SecurityScheme::applicationPasswords()])],
            atomicRoutes: [Samples::atomicRoute(securitySchemes: [$nonce])],
        );
        $paths = self::pathsOf($api);
        $security = static function (string $path, string $method) use ($paths): ?string {
            $operation = get_object_vars(self::operationOf($paths, $path, $method));

            return array_key_exists('security', $operation)
                ? json_encode($operation['security'], JSON_THROW_ON_ERROR)
                : null;
        };

        self::assertSame('[{}]', $security('/pets/{id}', 'get'));
        self::assertSame('[{"restNonce":[]},{}]', $security('/pets/{id}/owner', 'get'));
        self::assertNull($security('/pets/{id}/relationships/owner', 'get'));
        self::assertSame('[{"restNonce":[]},{"applicationPasswords":[]},{}]', $security('/pets/{id}/adopt', 'post'));
        self::assertSame('[{"restNonce":[]},{}]', $security('/operations', 'post'));
    }

    /**
     * @return array<string, stdClass>
     */
    private static function pathsOf(ApiMetadata $api): array
    {
        return (new PathBuilder($api))->paths();
    }

    private static function petstore(): ApiMetadata
    {
        return self::api(self::resources());
    }

    /**
     * `pets` keeps its create and its update, and of `owner` the relationship route alone
     */
    private static function excludingPetstore(): ApiMetadata
    {
        return self::api([
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
                [Capability::ResourceLoader, Capability::ResourceCreator, Capability::ResourceUpdater],
                excluded: RouteExclusion::only(
                    RouteOwner::Resource,
                    [OperationKind::CreateResource, OperationKind::UpdateResource],
                    'type "pets"',
                ),
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
        ]);
    }

    private static function versionedPetstore(bool $preconditionsRequired): ApiMetadata
    {
        $resources = self::resources();
        $resources[0] = self::withVersionPolicy($resources[0], $preconditionsRequired);

        return self::api($resources);
    }

    private static function acceptingPetstore(): ApiMetadata
    {
        $resources = self::resources(['create_resource' => 'jobs']);
        $resources[] = Samples::resource('jobs', [Samples::attribute('state')]);

        return self::api($resources);
    }

    private static function atomicPetstore(): ApiMetadata
    {
        return self::api(self::resources(), ImplementationReference::service('transactions'));
    }

    /**
     * @param list<AtomicRouteMetadata> $atomicRoutes
     */
    private static function routedPetstore(array $atomicRoutes): ApiMetadata
    {
        return self::api(self::resources(), atomicRoutes: $atomicRoutes);
    }

    /**
     * `pets` enables every write, `customers` declares no capability and is read one at a time,
     * `orders` is read and never written
     *
     * @param array<value-of<OperationKind>, string> $monitorTypes
     *
     * @return list<ResourceMetadata>
     */
    private static function resources(array $monitorTypes = []): array
    {
        return [
            Samples::resource(
                'pets',
                [Samples::attribute('name', null, true, ['@eq'])],
                [
                    Samples::toOne('owner', 'customers', true, false, [RelationshipUpdate::Replace]),
                    Samples::toMany(
                        'orders',
                        'orders',
                        true,
                        false,
                        [RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    ),
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
                monitorTypes: $monitorTypes,
            ),
            Samples::resource('customers', [Samples::attribute('name')]),
            Samples::resource('orders', [Samples::attribute('address')], [], [Capability::CollectionReader]),
        ];
    }

    private static function withVersionPolicy(ResourceMetadata $resource, bool $preconditionsRequired): ResourceMetadata
    {
        return new ResourceMetadata(
            $resource->type,
            $resource->id,
            $resource->idParameterName,
            $resource->attributes,
            $resource->relationships,
            $resource->capabilities,
            $resource->permissions,
            $resource->monitorTypes,
            $resource->excluded,
            $resource->clientIdPolicy,
            new VersionPolicy(static function (object $record): string {
                return 'v1';
            }, $preconditionsRequired),
            $resource->defaultSort,
            $resource->paginationPolicy,
            $resource->maxIncludeDepth,
            $resource->description,
        );
    }

    /**
     * the petstore with the atomic route and an endpoint,
     * whose language comes from `$languageSources`
     */
    private static function languagePetstore(LanguageSources $languageSources): ApiMetadata
    {
        return self::api(self::resources(), Samples::implementation(), [Samples::endpoint()], $languageSources);
    }

    private static function uiLocaleThenField(): LanguageSources
    {
        return new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]);
    }

    /**
     * @param list<EndpointMetadata> $endpoints
     */
    private static function endpointPetstore(
        array $endpoints,
        bool $versioned = false,
        bool $preconditionsRequired = false,
    ): ApiMetadata {
        $resources = self::resources();
        $resources[] = Samples::resource('jobs', [Samples::attribute('state')]);

        if ($versioned) {
            $resources[0] = self::withVersionPolicy($resources[0], $preconditionsRequired);
        }

        return self::api($resources, null, $endpoints);
    }

    private static function statsEndpoint(): EndpointMetadata
    {
        return Samples::endpoint(
            'stats',
            'GET',
            '/stats',
            [],
            responses: [200 => new ResponseMetadata(
                ResponseShapeKind::Meta,
                null,
                null,
                null,
                null,
                new ObjectCodec(['pets' => new IntegerCodec()], ['pets']),
            )],
            tag: 'stats',
        );
    }

    /**
     * routes of their own come with a boundary to run them;
     * without them a boundary declares `/operations` of every type
     *
     * @param list<ResourceMetadata>         $resources
     * @param list<EndpointMetadata>         $endpoints
     * @param ?list<AtomicRouteMetadata> $atomicRoutes
     */
    private static function api(
        array $resources,
        ?ImplementationReference $transactionBoundary = null,
        array $endpoints = [],
        ?LanguageSources $languageSources = null,
        ?array $atomicRoutes = null,
    ): ApiMetadata {
        $registry = new ResourceRegistry($resources);

        if ($atomicRoutes !== null) {
            $transactionBoundary ??= ImplementationReference::service('transactions');
        }

        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            $registry,
            $endpoints,
            $atomicRoutes ?? Samples::atomicRoutes($registry, $transactionBoundary),
            Samples::operators(),
            [],
            $transactionBoundary,
            $languageSources ?? Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    /**
     * @param array<string, stdClass> $paths
     *
     * @return list<string>
     */
    private static function methodsOf(array $paths, string $path): array
    {
        $methods = [];

        foreach (array_keys(get_object_vars(SchemaMembers::object($paths, $path))) as $member) {
            if ($member !== 'parameters') {
                $methods[] = $member;
            }
        }

        return $methods;
    }

    /**
     * @param array<string, stdClass> $paths
     */
    private static function operationOf(array $paths, string $path, string $method): stdClass
    {
        return SchemaMembers::object(get_object_vars(SchemaMembers::object($paths, $path)), $method);
    }

    /**
     * @return list<int>
     */
    private static function statusesOf(stdClass $operation): array
    {
        $statuses = [];

        foreach (array_keys(get_object_vars(SchemaMembers::object($operation, 'responses'))) as $status) {
            self::assertIsInt($status);
            $statuses[] = $status;
        }

        return $statuses;
    }

    /**
     * the name of each parameter, and the target of each reference to a component
     *
     * @return list<string>
     */
    private static function parameterNames(stdClass $operation): array
    {
        $names = [];

        foreach (self::listOf($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);

            if (array_key_exists('$ref', $parameter)) {
                self::assertIsString($parameter['$ref']);
                $names[] = $parameter['$ref'];

                continue;
            }

            self::assertArrayHasKey('name', $parameter);
            self::assertIsString($parameter['name']);
            $names[] = $parameter['name'];
        }

        return $names;
    }

    /**
     * @return array<string, mixed>
     */
    private static function parameterOf(stdClass $operation, string $name): array
    {
        foreach (self::listOf($operation, 'parameters') as $parameter) {
            self::assertIsArray($parameter);

            if (($parameter['name'] ?? null) === $name) {
                /** @var array<string, mixed> $parameter */
                return $parameter;
            }
        }

        self::fail(sprintf('the operation reads the parameter "%s"', $name));
    }

    /**
     * the maps `PathBuilder` keys itself stay PHP arrays
     *
     * @param array<array-key, mixed>|stdClass $holder
     *
     * @return array<array-key, mixed>
     */
    private static function map(array|stdClass $holder, string $name): array
    {
        $members = is_array($holder) ? $holder : get_object_vars($holder);

        self::assertArrayHasKey($name, $members);
        self::assertIsArray($members[$name]);

        return $members[$name];
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(stdClass $object, string $name): array
    {
        $members = get_object_vars($object);

        self::assertArrayHasKey($name, $members);
        self::assertIsList($members[$name]);

        return $members[$name];
    }

    private static function mediaType(string $text): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException($text);
    }
}
