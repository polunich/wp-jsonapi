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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\ConditionalWrite;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * the contract every operation class keeps, checked over one operation of each kind.
 *
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target.
 * each operation class has its own test
 *
 * @internal
 */
#[CoversNothing]
final class OperationTest extends TestCase
{
    /**
     * @var array<value-of<OperationKind>, ?string>
     */
    private const RELATIONSHIPS = [
        'fetch_collection' => null,
        'fetch_resource' => null,
        'fetch_related' => 'orders',
        'fetch_relationship' => 'orders',
        'create_resource' => null,
        'update_resource' => null,
        'delete_resource' => null,
        'replace_relationship' => 'owner',
        'add_to_relationship' => 'tags',
        'remove_from_relationship' => 'tags',
    ];

    public function testTheInterfaceDeclaresTheKindTheTypeAndTheRelationship(): void
    {
        $interface = new ReflectionClass(Operation::class);
        $signatures = [];

        foreach ($interface->getMethods() as $method) {
            $signatures[$method->getName()] = [
                array_map(
                    static function (ReflectionParameter $parameter): string {
                        return $parameter->getName() . ':' . $parameter->getType();
                    },
                    $method->getParameters(),
                ),
                (string) $method->getReturnType(),
            ];
        }

        self::assertSame(
            [
                'kind' => [[], OperationKind::class],
                'type' => [[], 'string'],
                'relationship' => [[], '?string'],
            ],
            $signatures,
        );
    }

    public function testEveryKindBelongsToExactlyOneOperation(): void
    {
        $kinds = array_map(
            static function (array $case): string {
                return $case[0]->kind()->value;
            },
            iterator_to_array(self::operations()),
        );

        self::assertSame(
            array_map(static function (OperationKind $kind): string {
                return $kind->value;
            }, OperationKind::cases()),
            array_values($kinds),
        );
    }

    #[DataProvider('operations')]
    public function testTheTypeIsExposedAsAPropertyAndByTheInterface(Operation $operation): void
    {
        $property = new ReflectionProperty($operation, 'type');

        self::assertTrue($property->isPublic());
        self::assertTrue($property->isReadOnly());
        self::assertSame('string', (string) $property->getType());
        self::assertSame('pets', $operation->type());
        self::assertSame($operation->type(), $property->getValue($operation));
    }

    #[DataProvider('operations')]
    public function testTheRelationshipIsNamedExactlyWhereTheKindTargetsOne(Operation $operation): void
    {
        $method = new ReflectionMethod($operation, 'relationship');

        self::assertSame(self::RELATIONSHIPS[$operation->kind()->value], $operation->relationship());

        if (!$operation->kind()->targetsRelationship()) {
            self::assertNull($operation->relationship());
            self::assertSame('?string', (string) $method->getReturnType());
            self::assertFalse(property_exists($operation, 'relationship'));

            return;
        }

        $property = new ReflectionProperty($operation, 'relationship');

        self::assertSame('string', (string) $method->getReturnType());
        self::assertTrue($property->isPublic());
        self::assertSame('string', (string) $property->getType());
        self::assertSame($operation->relationship(), $property->getValue($operation));
    }

    #[DataProvider('operations')]
    public function testTheResourceIsTheTargetOrTheParent(Operation $operation): void
    {
        $kind = $operation->kind();
        $first = (new ReflectionMethod($operation, '__construct'))->getParameters()[0] ?? null;

        self::assertNotNull($first);

        if ($kind->targetsRelationship()) {
            self::assertSame('parent', $first->getName());
            self::assertSame(Identifier::class, (string) $first->getType());
            self::assertFalse(property_exists($operation, 'target'));
        } elseif ($kind->actsOnExistingResource()) {
            self::assertSame('target', $first->getName());
            self::assertSame(Identifier::class, (string) $first->getType());
            self::assertFalse(property_exists($operation, 'parent'));
        } else {
            self::assertSame('type', $first->getName());
            self::assertSame('string', (string) $first->getType());
        }
    }

    public function testFiveOperationsNameTheParentAndThreeTheTarget(): void
    {
        $expected = [
            FetchCollection::class => 'type',
            FetchResource::class => 'target',
            FetchRelated::class => 'parent',
            FetchRelationship::class => 'parent',
            CreateResource::class => 'type',
            UpdateResource::class => 'target',
            DeleteResource::class => 'target',
            ReplaceRelationship::class => 'parent',
            AddToRelationship::class => 'parent',
            RemoveFromRelationship::class => 'parent',
        ];
        $actual = [];

        foreach (array_keys($expected) as $class) {
            $actual[$class] = ((new ReflectionMethod($class, '__construct'))->getParameters()[0] ?? null)?->getName();
        }

        self::assertSame($expected, $actual);
    }

    /**
     * parameter names are public API: a caller may pass the arguments by name
     *
     * @param class-string<Operation>     $class
     * @param list<array{string, string}> $parameters name and type of each parameter, in order
     */
    #[DataProvider('constructors')]
    public function testEveryConstructorDeclaresItsPublicParameters(string $class, array $parameters): void
    {
        self::assertSame(
            $parameters,
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                (new ReflectionMethod($class, '__construct'))->getParameters(),
            ),
        );
    }

    /**
     * @return iterable<string, array{class-string<Operation>, list<array{string, string}>}>
     */
    public static function constructors(): iterable
    {
        $include = ['include', '?' . IncludePaths::class];
        $fields = ['fields', SparseFieldsets::class];
        $languages = ['languages', LanguagePreferences::class];
        $queryParameters = ['queryParameters', Values::class];
        $precondition = ['precondition', '?' . Precondition::class];

        yield 'fetch collection' => [
            FetchCollection::class,
            [
                ['type', 'string'],
                ['filter', '?' . FilterNode::class],
                ['sort', Sort::class],
                ['page', PageRequest::class],
                $include,
                $fields,
                $languages,
                $queryParameters,
            ],
        ];
        yield 'fetch resource' => [
            FetchResource::class,
            [['target', Identifier::class], $include, $fields, $languages, $queryParameters],
        ];
        yield 'fetch related' => [
            FetchRelated::class,
            [
                ['parent', Identifier::class],
                ['relationship', 'string'],
                ['filter', '?' . FilterNode::class],
                ['sort', Sort::class],
                ['page', PageRequest::class],
                $include,
                $fields,
                $languages,
                $queryParameters,
            ],
        ];
        yield 'fetch relationship' => [
            FetchRelationship::class,
            [
                ['parent', Identifier::class],
                ['relationship', 'string'],
                ['filter', '?' . FilterNode::class],
                ['page', PageRequest::class],
                $include,
                $fields,
                $languages,
                $queryParameters,
            ],
        ];
        yield 'create resource' => [
            CreateResource::class,
            [
                ['type', 'string'],
                ['id', '?string'],
                ['attributes', Values::class],
                ['relationships', Values::class],
                $include,
                $fields,
                $languages,
                $queryParameters,
            ],
        ];
        yield 'update resource' => [
            UpdateResource::class,
            [
                ['target', Identifier::class],
                ['attributes', Values::class],
                ['relationships', Values::class],
                $precondition,
                $include,
                $fields,
                $languages,
                $queryParameters,
            ],
        ];
        yield 'delete resource' => [
            DeleteResource::class,
            [['target', Identifier::class], $precondition, $languages, $queryParameters],
        ];

        $relationshipWrites = [
            'replace relationship' => ReplaceRelationship::class,
            'add to relationship' => AddToRelationship::class,
            'remove from relationship' => RemoveFromRelationship::class,
        ];

        foreach ($relationshipWrites as $case => $class) {
            yield $case => [
                $class,
                [
                    ['parent', Identifier::class],
                    ['relationship', 'string'],
                    ['linkage', Linkage::class],
                    $precondition,
                    $include,
                    $fields,
                    $languages,
                    $queryParameters,
                ],
            ];
        }
    }

    #[DataProvider('operations')]
    public function testTheWritesOnAnExistingResourceAreConditional(Operation $operation): void
    {
        self::assertSame(
            !$operation->kind()->isFetch() && $operation->kind()->actsOnExistingResource(),
            $operation instanceof ConditionalWrite,
        );
    }

    public function testExactlyFiveOperationsAreConditionalWrites(): void
    {
        $conditional = array_values(array_filter(
            array_map(static function (array $case): Operation {
                return $case[0];
            }, iterator_to_array(self::operations())),
            static function (Operation $operation): bool {
                return $operation instanceof ConditionalWrite;
            },
        ));

        self::assertSame(
            [
                UpdateResource::class,
                DeleteResource::class,
                ReplaceRelationship::class,
                AddToRelationship::class,
                RemoveFromRelationship::class,
            ],
            array_map(static function (Operation $operation): string {
                return $operation::class;
            }, $conditional),
        );
    }

    #[DataProvider('operations')]
    public function testNoOperationCarriesALid(Operation $operation): void
    {
        $class = new ReflectionClass($operation);

        foreach ([...$class->getMethods(), ...$class->getProperties()] as $member) {
            self::assertStringNotContainsStringIgnoringCase('lid', $member->getName());
        }
    }

    #[DataProvider('operations')]
    public function testEveryOperationIsAFinalReadonlyClass(Operation $operation): void
    {
        $class = new ReflectionClass($operation);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    /**
     * one operation of each kind, in the order of `OperationKind::cases()`
     *
     * @return Generator<string, array{Operation}>
     */
    public static function operations(): Generator
    {
        $target = new Identifier('pets', '1');
        $languages = new LanguagePreferences([]);
        $fields = new SparseFieldsets([]);
        $precondition = Precondition::anyVersion();

        yield 'fetch collection' => [
            new FetchCollection(
                'pets',
                null,
                new Sort([]),
                PageRequest::pageNumber(),
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'fetch resource' => [new FetchResource($target, null, $fields, $languages, new Values([]))];
        yield 'fetch related' => [
            new FetchRelated(
                $target,
                'orders',
                null,
                new Sort([]),
                PageRequest::pageNumber(),
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'fetch relationship' => [
            new FetchRelationship(
                $target,
                'orders',
                null,
                PageRequest::pageNumber(),
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'create resource' => [
            new CreateResource('pets', null, new Values([]), new Values([]), null, $fields, $languages, new Values([])),
        ];
        yield 'update resource' => [
            new UpdateResource(
                $target,
                new Values([]),
                new Values([]),
                $precondition,
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'delete resource' => [new DeleteResource($target, $precondition, $languages, new Values([]))];
        yield 'replace relationship' => [
            new ReplaceRelationship(
                $target,
                'owner',
                Linkage::toOne(null),
                $precondition,
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'add to relationship' => [
            new AddToRelationship(
                $target,
                'tags',
                Linkage::toMany([]),
                $precondition,
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
        yield 'remove from relationship' => [
            new RemoveFromRelationship(
                $target,
                'tags',
                Linkage::toMany([]),
                $precondition,
                null,
                $fields,
                $languages,
                new Values([]),
            ),
        ];
    }
}
