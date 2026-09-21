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

use Opis\JsonSchema\CompliantValidator;
use Opis\JsonSchema\Errors\ErrorFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\OpenApi\AtomicSchemaBuilder;
use Polunich\WpJsonApi\OpenApi\DocumentSchemaBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\SchemaMembers;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(AtomicSchemaBuilder::class)]
final class AtomicSchemaBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(AtomicSchemaBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testEachAtomicRouteWritesItsOwnSchemas(): void
    {
        self::assertSame([], (new AtomicSchemaBuilder(self::petstore()))->schemas());
        self::assertSame(
            [
                'atomicOperations.operations',
                'atomicOperations.operation',
                'atomicOperations.ref',
                'atomicOperations.refAdd',
                'atomicOperations.refUpdate',
                'atomicOperations.refRemove',
                'atomicOperations.results',
                'atomicOperations.result',
                'petOperations.operations',
                'petOperations.operation',
                'petOperations.ref',
                'petOperations.refAdd',
                'petOperations.refUpdate',
                'petOperations.refRemove',
                'petOperations.results',
                'petOperations.result',
            ],
            array_keys((new AtomicSchemaBuilder(self::api(self::resources(), [
                Samples::atomicRoute(['pets', 'customers', 'orders']),
                Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
            ])))->schemas()),
        );
    }

    public function testTheDocumentsOfARouteAreNamedAfterIt(): void
    {
        $route = Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations');

        self::assertSame('petOperations.operations', AtomicSchemaBuilder::operationsName($route));
        self::assertSame('petOperations.results', AtomicSchemaBuilder::resultsName($route));
    }

    /**
     * a route of `pets` names only what an operation on a pet reads and responds with,
     * and a route of `customers`, which enables no write, names no data at all
     */
    public function testTheSchemasOfARouteAreNarrowedToItsTypes(): void
    {
        $schemas = (new AtomicSchemaBuilder(self::api(self::resources(), [
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
            Samples::atomicRoute(['customers'], 'customerOperations', '/customers/operations'),
        ])))->schemas();

        $ref = SchemaMembers::object($schemas, 'petOperations.ref');

        self::assertSame(
            ['pets'],
            SchemaMembers::object(get_object_vars(SchemaMembers::object($ref, 'properties')), 'type')->enum,
        );
        self::assertEquals(
            [
                (object) ['$ref' => '#/components/schemas/pets.create.atomic'],
                (object) ['$ref' => '#/components/schemas/pets.update.atomic'],
                (object) ['$ref' => '#/components/schemas/pets.owner.linkage.atomic'],
                (object) ['$ref' => '#/components/schemas/pets.orders.linkage.atomic'],
            ],
            self::listOf(SchemaMembers::object(get_object_vars(SchemaMembers::object(
                SchemaMembers::object($schemas, 'petOperations.operation'),
                'properties',
            )), 'data'), 'anyOf'),
        );
        self::assertCount(1, self::listOf(SchemaMembers::object($schemas, 'petOperations.refAdd'), 'allOf'));
        self::assertObjectNotHasProperty(
            'data',
            SchemaMembers::object(SchemaMembers::object($schemas, 'customerOperations.operation'), 'properties'),
        );
        // `data` is the one member a result object may carry, so the result of no write has none
        self::assertObjectNotHasProperty('properties', SchemaMembers::object($schemas, 'customerOperations.result'));
    }

    /**
     * only `pets` enables a write, so only its shapes appear
     */
    public function testAnAtomicOperationNamesThePrimaryDataOfEveryWriteTheApiOffers(): void
    {
        $api = self::atomicPetstore();
        $schemas = (new AtomicSchemaBuilder($api))->schemas();
        $data = SchemaMembers::object(SchemaMembers::object($schemas, 'atomicOperations.operation'), 'properties');
        $shapes = [
            (object) ['$ref' => '#/components/schemas/pets.create.atomic'],
            (object) ['$ref' => '#/components/schemas/pets.update.atomic'],
            (object) ['$ref' => '#/components/schemas/pets.owner.linkage.atomic'],
            (object) ['$ref' => '#/components/schemas/pets.orders.linkage.atomic'],
        ];

        self::assertEquals($shapes, self::listOf(SchemaMembers::object(get_object_vars($data), 'data'), 'anyOf'));
        self::assertEquals(
            [(object) ['$ref' => '#/components/schemas/pets.document/properties/data']],
            self::listOf(
                SchemaMembers::object(
                    get_object_vars(SchemaMembers::object(
                        SchemaMembers::object($schemas, 'atomicOperations.result'),
                        'properties',
                    )),
                    'data',
                ),
                'anyOf',
            ),
        );

        $documents = (new DocumentSchemaBuilder($api))->schemas();

        foreach ($shapes as $shape) {
            self::assertArrayHasKey(substr($shape->{'$ref'}, strlen('#/components/schemas/')), $documents);
        }
    }

    public function testTheAtomicDocumentNamesTheMembersTheParserIgnores(): void
    {
        $schemas = (new AtomicSchemaBuilder(self::atomicPetstore()))->schemas();
        $document = SchemaMembers::object($schemas, 'atomicOperations.operations');

        self::assertSame(
            ['atomic:operations', 'meta', 'jsonapi', 'links'],
            array_keys(get_object_vars(SchemaMembers::object($document, 'properties'))),
        );
        self::assertSame(['atomic:operations'], $document->required);
        self::assertFalse($document->additionalProperties);
        self::assertEquals((object) ['^@' => true], $document->patternProperties);
    }

    public function testTheTargetOfAnAtomicOperationIsWrittenOnce(): void
    {
        $schemas = (new AtomicSchemaBuilder(self::atomicPetstore()))->schemas();
        $operation = SchemaMembers::object($schemas, 'atomicOperations.operation');

        self::assertSame(
            ['op', 'ref', 'data', 'meta'],
            array_keys(get_object_vars(SchemaMembers::object($operation, 'properties'))),
        );
        self::assertObjectNotHasProperty('not', $operation);
        self::assertEquals(
            [
                (object) [
                    'if' => (object) [
                        'properties' => (object) ['op' => (object) ['const' => 'remove']],
                        'required' => ['op'],
                    ],
                    'then' => (object) ['required' => ['ref']],
                ],
                self::refCondition('add', 'atomicOperations.refAdd'),
                self::refCondition('update', 'atomicOperations.refUpdate'),
                self::refCondition('remove', 'atomicOperations.refRemove'),
            ],
            $operation->allOf,
        );

        $ref = SchemaMembers::object($schemas, 'atomicOperations.ref');

        self::assertEquals([(object) ['required' => ['id']], (object) ['required' => ['lid']]], $ref->oneOf);
        self::assertSame(['type'], $ref->required);
        self::assertSame(
            ['pets', 'customers', 'orders'],
            SchemaMembers::object(get_object_vars(SchemaMembers::object($ref, 'properties')), 'type')->enum,
        );
    }

    /**
     * `pets` declares the replacement of `owner` and the addition and removal of `orders`;
     * `customers` and `orders` declare no relationship
     *
     * @param array<string, list<string>> $expected the relationship names per type,
     *                                              empty where the ref may name none
     */
    #[DataProvider('atomicRefs')]
    public function testTheRefOfAnOpCodeNamesTheRelationshipsThatCodeMayWrite(
        string $component,
        string $code,
        bool $namesARelationship,
        array $expected,
    ): void {
        $ref = SchemaMembers::object((new AtomicSchemaBuilder(self::atomicPetstore()))->schemas(), $component);
        $branches = [];

        foreach ($expected as $type => $names) {
            $branches[] = (object) [
                'if' => (object) [
                    'properties' => (object) ['type' => (object) ['const' => $type]],
                    'required' => ['type'],
                ],
                'then' => $names === []
                    ? (object) ['not' => (object) ['required' => ['relationship']]]
                    : (object) ['properties' => (object) ['relationship' => (object) ['enum' => $names]]],
            ];
        }

        self::assertEquals($branches, self::listOf($ref, 'allOf'));
        self::assertSame($namesARelationship, ($ref->required ?? null) === ['relationship']);
        self::assertStringContainsString(
            '"' . $code . '" operation that carries a ref',
            SchemaMembers::text($ref, 'description'),
        );
    }

    /**
     * @return iterable<string, array{string, string, bool, array<string, list<string>>}>
     */
    public static function atomicRefs(): iterable
    {
        yield 'an "add" targets a relationship that accepts an addition' => [
            'atomicOperations.refAdd',
            'add',
            true,
            ['pets' => ['orders'], 'customers' => [], 'orders' => []],
        ];

        yield 'an "update" targets a relationship that accepts a replacement' => [
            'atomicOperations.refUpdate',
            'update',
            false,
            ['pets' => ['owner'], 'customers' => [], 'orders' => []],
        ];

        yield 'a "remove" targets a relationship that accepts a removal' => [
            'atomicOperations.refRemove',
            'remove',
            false,
            ['pets' => ['orders'], 'customers' => [], 'orders' => []],
        ];
    }

    /**
     * the documents carry no `data`, which an operation object does not require,
     * so each stands or falls by its `ref`
     */
    #[DataProvider('atomicOperations')]
    public function testTheContractAdmitsTheRefsTheServerPerforms(string $operation, bool $valid): void
    {
        $api = self::atomicPetstore();
        $schemas = array_merge(
            (new DocumentSchemaBuilder($api))->schemas(),
            (new AtomicSchemaBuilder($api))->schemas(),
        );
        $validator = new CompliantValidator();
        $validator->parser()->setOption('allowFormats', false);

        $result = $validator->validate(
            json_decode($operation, false, 512, JSON_THROW_ON_ERROR),
            (object) [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                '$ref' => '#/components/schemas/atomicOperations.operation',
                'components' => (object) ['schemas' => (object) $schemas],
            ],
        );
        $error = $result->error();

        self::assertSame(
            $valid,
            $result->isValid(),
            $error === null
                ? $operation
                : (string) json_encode(
                    (new ErrorFormatter())->format($error, true),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
        );
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function atomicOperations(): iterable
    {
        yield 'an addition to a to-many relationship that declares add' => [
            '{"op":"add","ref":{"type":"pets","id":"1","relationship":"orders"}}',
            true,
        ];

        yield 'an addition to a to-one relationship, which declares replace alone' => [
            '{"op":"add","ref":{"type":"pets","id":"1","relationship":"owner"}}',
            false,
        ];

        yield 'an addition naming a relationship the type does not declare' => [
            '{"op":"add","ref":{"type":"pets","id":"1","relationship":"tags"}}',
            false,
        ];

        yield 'an addition on a type that declares no relationship' => [
            '{"op":"add","ref":{"type":"customers","id":"1","relationship":"pets"}}',
            false,
        ];

        yield 'an addition whose ref names no relationship' => ['{"op":"add","ref":{"type":"pets","id":"1"}}', false];

        yield 'an addition without a ref, which creates a resource' => ['{"op":"add"}', true];

        yield 'a replacement of a to-one relationship' => [
            '{"op":"update","ref":{"type":"pets","id":"1","relationship":"owner"}}',
            true,
        ];

        yield 'a replacement of a to-many relationship that declares add and remove alone' => [
            '{"op":"update","ref":{"type":"pets","lid":"a1","relationship":"orders"}}',
            false,
        ];

        yield 'an update of a resource, whose ref names no relationship' => [
            '{"op":"update","ref":{"type":"customers","id":"1"}}',
            true,
        ];

        yield 'an update naming a relationship on a type that declares none' => [
            '{"op":"update","ref":{"type":"customers","id":"1","relationship":"pets"}}',
            false,
        ];

        yield 'a removal from a to-many relationship that declares remove' => [
            '{"op":"remove","ref":{"type":"pets","id":"1","relationship":"orders"}}',
            true,
        ];

        yield 'a removal from a to-one relationship, which declares replace alone' => [
            '{"op":"remove","ref":{"type":"pets","id":"1","relationship":"owner"}}',
            false,
        ];

        yield 'a delete of a resource a prior operation created' => [
            '{"op":"remove","ref":{"type":"pets","lid":"a1"}}',
            true,
        ];

        yield 'a remove without a ref, which targets nothing' => ['{"op":"remove"}', false];

        yield 'a ref that carries an id and a lid' => [
            '{"op":"remove","ref":{"type":"pets","id":"1","lid":"a1"}}',
            false,
        ];

        yield 'a ref of a type the API does not declare' => ['{"op":"remove","ref":{"type":"tags","id":"1"}}', false];
    }

    private static function refCondition(string $code, string $component): stdClass
    {
        return (object) [
            'if' => (object) [
                'properties' => (object) ['op' => (object) ['const' => $code]],
                'required' => ['op', 'ref'],
            ],
            'then' => (object) [
                'properties' => (object) ['ref' => (object) ['$ref' => '#/components/schemas/' . $component]],
            ],
        ];
    }

    private static function petstore(): ApiMetadata
    {
        return self::api(self::resources());
    }

    /**
     * the atomic route `/operations`, which takes every type
     */
    private static function atomicPetstore(): ApiMetadata
    {
        return self::api(self::resources(), [Samples::atomicRoute(['pets', 'customers', 'orders'])]);
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

    /**
     * @param list<ResourceMetadata>    $resources
     * @param list<AtomicRouteMetadata> $atomicRoutes given any, the API declares a boundary
     */
    private static function api(array $resources, array $atomicRoutes = []): ApiMetadata
    {
        return new ApiMetadata(
            'petstore',
            '1.0',
            'acme/v1',
            new ResourceRegistry($resources),
            [],
            $atomicRoutes,
            Samples::operators(),
            [],
            $atomicRoutes === [] ? null : ImplementationReference::service('transactions'),
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
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
}
