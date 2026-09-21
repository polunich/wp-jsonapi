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

namespace Polunich\WpJsonApi\OpenApi;

use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Document\OperationCode;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use stdClass;

/**
 * builds the schemas of each atomic route, narrowed to the types the route takes:
 * the document it reads, an operation and its `ref`, and the document it responds with.
 * a component of a route is keyed `<route name>.<part>`,
 * and no two-segment key of a type ends in one of these parts,
 * so a route named like a type takes no key of that type
 *
 * @internal
 */
final readonly class AtomicSchemaBuilder
{
    private const OPERATIONS = 'operations';

    private const OPERATION = 'operation';

    private const REF = 'ref';

    private const REF_ADD = 'refAdd';

    private const REF_UPDATE = 'refUpdate';

    private const REF_REMOVE = 'refRemove';

    private const RESULTS = 'results';

    private const RESULT = 'result';

    private const RELATIONSHIP_WRITES = [
        RelationshipUpdate::Add,
        RelationshipUpdate::Replace,
        RelationshipUpdate::Remove,
    ];

    public function __construct(private ApiMetadata $api) {}

    /**
     * the document the route reads
     */
    public static function operationsName(AtomicRouteMetadata $route): string
    {
        return DocumentSchemaBuilder::componentName($route->name, self::OPERATIONS);
    }

    /**
     * the document the route responds with
     */
    public static function resultsName(AtomicRouteMetadata $route): string
    {
        return DocumentSchemaBuilder::componentName($route->name, self::RESULTS);
    }

    /**
     * @return array<string, stdClass>
     */
    public function schemas(): array
    {
        $schemas = [];

        foreach ($this->api->atomicRoutes as $route) {
            $resources = array_map(function (string $type): ResourceMetadata {
                return $this->api->resources->get($type);
            }, $route->types);

            $schemas[self::operationsName($route)] = self::atomicOperationsDocument($route);
            $schemas[self::partName($route, self::OPERATION)] = self::atomicOperationObject($route, $resources);
            $schemas[self::partName($route, self::REF)] = self::atomicRefObject($resources);

            foreach (self::RELATIONSHIP_WRITES as $update) {
                $schemas[self::partName($route, self::refOf($update))] = self::atomicRefOf($update, $resources);
            }

            $schemas[self::resultsName($route)] = self::atomicResultsDocument($route);
            $schemas[self::partName($route, self::RESULT)] = self::atomicResultObject($resources);
        }

        return $schemas;
    }

    private static function partName(AtomicRouteMetadata $route, string $part): string
    {
        return DocumentSchemaBuilder::componentName($route->name, $part);
    }

    private static function atomicOperationsDocument(AtomicRouteMetadata $route): stdClass
    {
        return DocumentSchemaBuilder::objectSchema(
            [
                Member::ATOMIC_OPERATIONS => (object) [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => DocumentSchemaBuilder::reference(self::partName($route, self::OPERATION)),
                    'description' => 'the operations to perform, in the order they are performed [ext-atomic]',
                ],
                Member::META => DocumentSchemaBuilder::ignored('object', 'non-standard information of the document;'
                    . ' the library reads none of it [spec, Meta Information]'),
                Member::JSONAPI => DocumentSchemaBuilder::ignored('object', 'the object describing the implementation'
                    . ' of the client; the library reads none of it [spec, Top Level]'),
                Member::LINKS => DocumentSchemaBuilder::ignored('object', 'the links of the document; the library'
                    . ' reads none of them [spec, Top Level]'),
            ],
            [Member::ATOMIC_OPERATIONS],
            sprintf('the document the atomic route "%s" reads [ext-atomic]', $route->name),
        );
    }

    /**
     * `href` is not described: the server names a target by `ref` alone
     * and responds to `href` with 400.
     * what a `ref` may name depends on the op code, but a schema applied to `ref`
     * cannot see its sibling `op`, so conditions on the operation pick the ref schema per op code;
     * the first condition makes a remove carry a `ref`
     *
     * @param list<ResourceMetadata> $resources the types of the route
     */
    private static function atomicOperationObject(AtomicRouteMetadata $route, array $resources): stdClass
    {
        $properties = [
            Member::OP => (object) [
                'type' => 'string',
                'enum' => ['add', 'update', 'remove'],
                'description' => 'what the operation does: "add" creates a resource, or adds members to the'
                    . ' relationship its ref names; "update" updates a resource, or replaces the relationship'
                    . ' its ref names; "remove" deletes the resource its ref names, or removes members from'
                    . ' the relationship it names [ext-atomic]',
            ],
            Member::REF => DocumentSchemaBuilder::reference(self::partName($route, self::REF)),
        ];
        $data = self::operationData($resources);

        if ($data !== []) {
            $properties[Member::DATA] = self::alternatives(
                $data,
                'the primary data of the operation: the resource object a create or an update reads, or the'
                . ' linkage a relationship write reads [ext-atomic]',
            );
        }

        $properties[Member::META] = DocumentSchemaBuilder::ignored('object', 'non-standard information about the'
            . ' operation; the library reads none of it [ext-atomic]');

        return DocumentSchemaBuilder::objectSchema(
            $properties,
            [Member::OP],
            'one operation of an atomic request [ext-atomic]',
            constraints: ['allOf' => array_merge(
                [(object) [
                    'if' => (object) [
                        'properties' => (object) [Member::OP => (object) ['const' => OperationCode::Remove->value]],
                        'required' => [Member::OP],
                    ],
                    'then' => (object) ['required' => [Member::REF]],
                ]],
                self::refConditions($route),
            )],
        );
    }

    /**
     * a condition fires only where a `ref` is present: an "add" without one creates a resource,
     * and an "update" without one updates the resource its data names
     *
     * @return list<stdClass>
     */
    private static function refConditions(AtomicRouteMetadata $route): array
    {
        $conditions = [];

        foreach (self::RELATIONSHIP_WRITES as $update) {
            $conditions[] = (object) [
                'if' => (object) [
                    'properties' => (object) [Member::OP => (object) ['const' => self::codeOf($update)]],
                    'required' => [Member::OP, Member::REF],
                ],
                'then' => (object) [
                    'properties' => (object) [
                        Member::REF => DocumentSchemaBuilder::reference(self::partName($route, self::refOf($update))),
                    ],
                ],
            ];
        }

        return $conditions;
    }

    /**
     * @param list<ResourceMetadata> $resources
     *
     * @return list<stdClass>
     */
    private static function operationData(array $resources): array
    {
        $shapes = [];

        foreach ($resources as $resource) {
            if ($resource->enables(OperationKind::CreateResource)) {
                $shapes[] = DocumentSchemaBuilder::reference(DocumentSchemaBuilder::atomicCreateName($resource->type));
            }

            if ($resource->enables(OperationKind::UpdateResource)) {
                $shapes[] = DocumentSchemaBuilder::reference(DocumentSchemaBuilder::atomicUpdateName($resource->type));
            }

            foreach ($resource->relationships as $relationship) {
                if ($relationship->updates !== []) {
                    $shapes[] = DocumentSchemaBuilder::reference(
                        DocumentSchemaBuilder::atomicLinkageName($resource->type, $relationship->name),
                    );
                }
            }
        }

        return $shapes;
    }

    /**
     * `id` is a plain string: one ref names any type, while an id codec describes the ids of one
     *
     * @param list<ResourceMetadata> $resources
     */
    private static function atomicRefObject(array $resources): stdClass
    {
        $types = [];

        foreach ($resources as $resource) {
            $types[] = $resource->type;
        }

        return DocumentSchemaBuilder::objectSchema(
            [
                Member::TYPE => (object) [
                    'type' => 'string',
                    'enum' => $types,
                    'description' => 'the type of the resource the operation targets [ext-atomic]',
                ],
                Member::ID => (object) [
                    'type' => 'string',
                    'description' => 'the id of the resource the operation targets [ext-atomic]',
                ],
                Member::LID => (object) [
                    'type' => 'string',
                    'description' => 'the lid a prior operation of the same request assigned to the'
                        . ' resource [ext-atomic]',
                ],
                Member::RELATIONSHIP => (object) [
                    'type' => 'string',
                    'description' => 'the relationship of that resource the operation targets; without it the'
                        . ' operation targets the resource itself. which relationships it may name follows from'
                        . ' the type it stands beside and from the op code of the operation, which the ref schema'
                        . ' of that code states [ext-atomic]',
                ],
            ],
            [Member::TYPE],
            'the target of an operation: a resource of a type the route takes, and a relationship of it where'
            . ' the operation writes one [ext-atomic]',
            constraints: ['oneOf' => [(object) ['required' => [Member::ID]], (object) ['required' => [Member::LID]]]],
        );
    }

    /**
     * per type, the relationships that accept the update of this op code;
     * the server responds to any other with 403.
     * one `if`/`then` branch per type, each `if` requiring `type`:
     * `properties` alone passes on an absent member,
     * so a ref without a type would fire every branch.
     * a type without such a relationship forbids `relationship`,
     * which leaves an "add" of that type no valid ref, as the server has none either.
     * a route takes a type at least, so `allOf` is never empty
     *
     * @param list<ResourceMetadata> $resources
     */
    private static function atomicRefOf(RelationshipUpdate $update, array $resources): stdClass
    {
        $branches = [];

        foreach ($resources as $resource) {
            $names = [];

            foreach ($resource->relationships as $relationship) {
                if ($relationship->accepts($update)) {
                    $names[] = $relationship->name;
                }
            }

            $branches[] = (object) [
                'if' => (object) [
                    'properties' => (object) [Member::TYPE => (object) ['const' => $resource->type]],
                    'required' => [Member::TYPE],
                ],
                'then' => $names === []
                    ? (object) ['not' => (object) ['required' => [Member::RELATIONSHIP]]]
                    : (object) ['properties' => (object) [Member::RELATIONSHIP => (object) ['enum' => $names]]],
            ];
        }

        $ref = [];

        if ($update === RelationshipUpdate::Add) {
            // a ref on an "add" always targets a relationship
            $ref['required'] = [Member::RELATIONSHIP];
        }

        $ref['allOf'] = $branches;

        $ref['description'] = self::refText($update);

        return (object) $ref;
    }

    private static function refText(RelationshipUpdate $update): string
    {
        return match ($update) {
            RelationshipUpdate::Add => 'the target of an "add" operation that carries a ref: the relationship'
                . ' it adds members to, which the type it names declares and which accepts an addition',
            RelationshipUpdate::Replace => 'the target of an "update" operation that carries a ref: the resource'
                . ' it updates, or the relationship it replaces, which the type it names declares and which'
                . ' accepts a replacement',
            RelationshipUpdate::Remove => 'the target of a "remove" operation that carries a ref: the resource it'
                . ' deletes, or the relationship it removes members from, which the type it names declares and'
                . ' which accepts a removal',
        };
    }

    private static function codeOf(RelationshipUpdate $update): string
    {
        return match ($update) {
            RelationshipUpdate::Add => OperationCode::Add->value,
            RelationshipUpdate::Replace => OperationCode::Update->value,
            RelationshipUpdate::Remove => OperationCode::Remove->value,
        };
    }

    private static function refOf(RelationshipUpdate $update): string
    {
        return match ($update) {
            RelationshipUpdate::Add => self::REF_ADD,
            RelationshipUpdate::Replace => self::REF_UPDATE,
            RelationshipUpdate::Remove => self::REF_REMOVE,
        };
    }

    private static function atomicResultsDocument(AtomicRouteMetadata $route): stdClass
    {
        return DocumentSchemaBuilder::objectSchema(
            [
                Member::JSONAPI => DocumentSchemaBuilder::reference(DocumentSchemaBuilder::jsonApiName()),
                Member::ATOMIC_RESULTS => (object) [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => DocumentSchemaBuilder::reference(self::partName($route, self::RESULT)),
                    'description' => 'one result per operation, in the order of the operations [ext-atomic]',
                ],
            ],
            [Member::JSONAPI, Member::ATOMIC_RESULTS],
            sprintf('the document the atomic route "%s" responds with [ext-atomic]', $route->name),
        );
    }

    /**
     * `data` is optional: an operation that returns no data gets the empty result object
     *
     * @param list<ResourceMetadata> $resources
     */
    private static function atomicResultObject(array $resources): stdClass
    {
        $shapes = [];

        foreach ($resources as $resource) {
            if (
                $resource->enables(OperationKind::CreateResource)
                || $resource->enables(OperationKind::UpdateResource)
            ) {
                $shapes[] = self::primaryDataOf(DocumentSchemaBuilder::documentName($resource->type));
            }
        }

        $properties = [];

        if ($shapes !== []) {
            $properties[Member::DATA] = self::alternatives(
                $shapes,
                'the resource the operation created or updated; a remove and a relationship write return no'
                . ' data [ext-atomic]',
            );
        }

        return DocumentSchemaBuilder::objectSchema(
            $properties,
            [],
            'the result of one operation of an atomic request [ext-atomic]',
        );
    }

    /**
     * `anyOf`, not `oneOf`: one value may match several shapes,
     * such as the create and the update resource object of one type, and `oneOf` would refuse it
     *
     * @param non-empty-list<stdClass> $shapes
     */
    private static function alternatives(array $shapes, string $description): stdClass
    {
        return (object) ['anyOf' => $shapes, 'description' => $description];
    }

    /**
     * the `data` of a document schema: a result object carries the resource object itself,
     * and pointing into the document schema reuses it instead of writing a second copy
     */
    private static function primaryDataOf(string $document): stdClass
    {
        return DocumentSchemaBuilder::memberReference($document, Member::DATA);
    }
}
