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
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\FilterMember;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\SortPath;
use Polunich\WpJsonApi\Operation\AddToRelationship;
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
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ResourceRegistry::class)]
final class ResourceRegistryTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResourceRegistry::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * @param list<array{string, string}> $parameters
     */
    #[DataProvider('signatures')]
    public function testTheLookupsAndThePoliciesOfTheRegistryKeepTheirSignatures(
        string $method,
        array $parameters,
        string $returnType,
    ): void {
        $reflection = new ReflectionMethod(ResourceRegistry::class, $method);

        self::assertTrue($reflection->isPublic());
        self::assertSame(
            $parameters,
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $reflection->getParameters(),
            ),
        );
        self::assertSame($returnType, (string) $reflection->getReturnType());
    }

    /**
     * @return iterable<string, array{string, list<array{string, string}>, string}>
     */
    public static function signatures(): iterable
    {
        yield 'the constructor' => ['__construct', [['resources', 'array']], ''];
        yield 'has' => ['has', [['type', 'string']], 'bool'];
        yield 'get' => ['get', [['type', 'string']], ResourceMetadata::class];
        yield 'all' => ['all', [], 'array'];
        yield 'paginationPolicy' => [
            'paginationPolicy',
            [['operation', Operation::class]],
            '?' . PaginationPolicy::class,
        ];
        yield 'readingSort' => [
            'readingSort',
            [['operation', FetchCollection::class . '|' . FetchRelated::class . '|' . FetchRelationship::class]],
            Sort::class,
        ];
        yield 'endpointPaginationPolicy' => [
            'endpointPaginationPolicy',
            [['endpoint', EndpointMetadata::class]],
            PaginationPolicy::class,
        ];
        yield 'endpointDefaultSort' => [
            'endpointDefaultSort',
            [['endpoint', EndpointMetadata::class]],
            '?' . Sort::class,
        ];
        yield 'checkDefaultSort' => [
            'checkDefaultSort',
            [['sort', Sort::class], ['type', 'string'], ['owner', 'string']],
            'void',
        ];
        yield 'relationshipPaginationPolicy' => [
            'relationshipPaginationPolicy',
            [['type', 'string'], ['relationship', 'string']],
            PaginationPolicy::class,
        ];
        yield 'relationshipReadingSort' => [
            'relationshipReadingSort',
            [['type', 'string'], ['relationship', 'string']],
            Sort::class,
        ];
        yield 'relationshipOf' => [
            'relationshipOf',
            [['type', 'string'], ['name', 'string']],
            RelationshipMetadata::class,
        ];
        yield 'sortPath' => ['sortPath', [['type', 'string'], ['path', FieldPath::class]], SortPath::class];
        yield 'sortPaths' => ['sortPaths', [['type', 'string']], 'array'];
        yield 'isIncludable' => [
            'isIncludable',
            [['type', 'string'], ['relationship', '?string'], ['path', IncludePath::class]],
            'bool',
        ];
        yield 'includePaths' => ['includePaths', [['type', 'string'], ['relationship', '?string']], 'array'];
        yield 'includedTypes' => ['includedTypes', [['type', 'string'], ['relationship', '?string']], 'array'];
        yield 'filterMember' => [
            'filterMember',
            [['type', 'string'], ['name', 'string']],
            '?' . FilterMember::class,
        ];
        yield 'filterMembers' => ['filterMembers', [['type', 'string']], 'array'];
    }

    public function testKeepsTheResourcesInDeclarationOrder(): void
    {
        $customers = Samples::resource('customers');
        $pets = Samples::resource('pets');
        $zero = Samples::resource('0');
        $registry = new ResourceRegistry([$customers, $pets, $zero]);

        self::assertSame([$customers, $pets, $zero], $registry->all());
        self::assertTrue($registry->has('pets'));
        self::assertTrue($registry->has('0'));
        self::assertFalse($registry->has('orders'));
        self::assertSame($pets, $registry->get('pets'));
        self::assertSame($zero, $registry->get('0'));
    }

    public function testAnApiMayDeclareNoResource(): void
    {
        $registry = new ResourceRegistry([]);

        self::assertSame([], $registry->all());
        self::assertFalse($registry->has('pets'));
    }

    public function testRefusesToGetATypeThatIsNotDeclared(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "orders\n" is not declared');

        (new ResourceRegistry([Samples::resource('pets')]))->get("orders\n");
    }

    public function testRefusesATypeDeclaredTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" is declared twice, and each type is declared once');

        new ResourceRegistry([Samples::resource('pets'), Samples::resource('customers'), Samples::resource('pets')]);
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    #[DataProvider('undeclaredReferences')]
    public function testRefusesARelatedOrMonitorTypeThatIsNotDeclared(array $resources, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ResourceRegistry($resources);
    }

    /**
     * @return iterable<string, array{list<ResourceMetadata>, string}>
     */
    public static function undeclaredReferences(): iterable
    {
        yield 'a related type' => [
            [Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers')])],
            'relationship "owner" of type "pets" points at type "customers", which is not declared',
        ];
        yield 'a related type declared under another case' => [
            [
                Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers')]),
                Samples::resource('Customers'),
            ],
            'relationship "owner" of type "pets" points at type "customers", which is not declared',
        ];
        yield 'a monitor type' => [
            [Samples::resource(
                'pets',
                capabilities: [Capability::ResourceCreator],
                monitorTypes: ['create_resource' => 'jobs'],
            )],
            'type "pets" responds to create_resource with 202 and the monitor type "jobs", which is not declared',
        ];

        $pets = Samples::resource(
            'pets',
            relationships: [Samples::toMany(
                'orders',
                'orders',
                includable: false,
                defaultSort: Sort::fromString('customer.name'),
            )],
        );
        $orders = Samples::resource(
            'orders',
            relationships: [Samples::toOne('customer', 'customers', nullable: false)],
            capabilities: [Capability::CollectionReader],
        );

        yield 'a related type the default sort of a to-many relationship reaches, declared before the type that'
            . ' points at it' => [
                [$pets, $orders],
                'relationship "customer" of type "orders" points at type "customers", which is not declared',
            ];
        yield 'a related type the default sort of a to-many relationship reaches, declared after the type that'
            . ' points at it' => [
                [$orders, $pets],
                'relationship "customer" of type "orders" points at type "customers", which is not declared',
            ];
    }

    public function testAcceptsReferencesToAnyDeclaredType(): void
    {
        $registry = new ResourceRegistry([
            Samples::resource(
                'customers',
                relationships: [Samples::toOne('manager', 'customers'), Samples::toOne('trainer', 'trainers')],
                capabilities: [Capability::ResourceUpdater],
                monitorTypes: ['update_resource' => 'jobs'],
            ),
            Samples::resource('trainers'),
            Samples::resource('jobs'),
        ]);

        self::assertCount(3, $registry->all());
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    #[DataProvider('missingCapabilities')]
    public function testRefusesAMissingCapabilityARelationshipRequires(array $resources, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ResourceRegistry($resources);
    }

    /**
     * @return iterable<string, array{list<ResourceMetadata>, string}>
     */
    public static function missingCapabilities(): iterable
    {
        yield 'a to-many relationship whose related type binds no collection reader' => [
            [
                Samples::resource('pets', relationships: [Samples::toMany('orders', 'orders', includable: false)]),
                Samples::resource('orders'),
            ],
            'to-many relationship "orders" of type "pets" is read through the collection reader of type "orders",'
            . ' which binds none',
        ];
        yield 'a to-many relationship whose related type binds no collection reader, beside a related loader' => [
            [
                Samples::resource(
                    'pets',
                    relationships: [Samples::toMany('orders', 'orders')],
                    capabilities: [Capability::RelatedLoader],
                ),
                Samples::resource('orders'),
            ],
            'to-many relationship "orders" of type "pets" is read through the collection reader of type "orders",'
            . ' which binds none',
        ];
        yield 'a collection reader bound on the declaring type instead of the related type' => [
            [
                Samples::resource(
                    'pets',
                    relationships: [Samples::toMany('orders', 'orders', includable: false)],
                    capabilities: [Capability::CollectionReader],
                ),
                Samples::resource('orders'),
            ],
            'to-many relationship "orders" of type "pets" is read through the collection reader of type "orders",'
            . ' which binds none',
        ];
        yield 'a to-one relationship without accessor and no related loader' => [
            [
                Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers', readsRelatedId: false)]),
                Samples::resource('customers'),
            ],
            'to-one relationship "owner" of type "pets" has no related id accessor, so its linkage is loaded'
            . ' through a related loader, and the type binds none',
        ];
        yield 'a related loader bound on the related type instead of the declaring type' => [
            [
                Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers', readsRelatedId: false)]),
                Samples::resource('customers', capabilities: [Capability::RelatedLoader]),
            ],
            'to-one relationship "owner" of type "pets" has no related id accessor',
        ];
        yield 'an includable to-many relationship and no related loader' => [
            [
                Samples::resource('pets', relationships: [Samples::toMany('orders', 'orders')]),
                Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            ],
            'to-many relationship "orders" of type "pets" is includable or always linked, so its related resources'
            . ' are loaded through a related loader, and the type binds none',
        ];
        yield 'an always linked to-many relationship and no related loader' => [
            [
                Samples::resource(
                    'pets',
                    relationships: [Samples::toMany('orders', 'orders', includable: false, linkageAlwaysPresent: true)],
                ),
                Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            ],
            'to-many relationship "orders" of type "pets" is includable or always linked',
        ];
    }

    /**
     * @param list<ResourceMetadata> $resources
     */
    #[DataProvider('sufficientCapabilities')]
    public function testAcceptsTheCapabilitiesTheRelationshipsRequire(array $resources): void
    {
        self::assertCount(count($resources), (new ResourceRegistry($resources))->all());
    }

    /**
     * @return iterable<string, array{list<ResourceMetadata>}>
     */
    public static function sufficientCapabilities(): iterable
    {
        yield 'a to-one relationship with accessor' => [[
            Samples::resource('pets', relationships: [Samples::toOne('owner', 'customers')]),
            Samples::resource('customers'),
        ]];
        yield 'a to-many relationship neither includable nor always linked' => [[
            Samples::resource('pets', relationships: [Samples::toMany('orders', 'orders', includable: false)]),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]];
        yield 'a related loader for every relationship that needs one' => [[
            Samples::resource(
                'pets',
                relationships: [
                    Samples::toOne('owner', 'customers', readsRelatedId: false),
                    Samples::toMany('orders', 'orders'),
                    Samples::toMany('tags', 'tags', includable: false, linkageAlwaysPresent: true),
                ],
                capabilities: [Capability::RelatedLoader],
            ),
            Samples::resource('customers'),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource('tags', capabilities: [Capability::CollectionReader]),
        ]];
    }

    public function testRefusesACollectionReaderThatServesNoRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "orders" binds a collection reader and excludes its collection route,'
            . ' and no to-many relationship points at it, so nothing reads through the'
            . ' reader');

        new ResourceRegistry([
            Samples::resource(
                'orders',
                capabilities: [Capability::CollectionReader],
                excluded: self::except(OperationKind::FetchCollection),
            ),
        ]);
    }

    public function testAcceptsACollectionReaderThatServesAToManyRelationshipBesideAnExcludedCollectionRoute(): void
    {
        $registry = new ResourceRegistry([
            Samples::resource('pets', relationships: [Samples::toMany('orders', 'orders', includable: false)]),
            Samples::resource(
                'orders',
                capabilities: [Capability::CollectionReader],
                excluded: self::except(OperationKind::FetchCollection),
            ),
        ]);

        self::assertFalse($registry->get('orders')->enables(OperationKind::FetchCollection));
    }

    #[DataProvider('validDefaultSorts')]
    public function testAcceptsADeclaredDefaultSortOfTheResource(string $sort): void
    {
        $registry = self::petsRegistry(petsSort: Sort::fromString($sort));

        self::assertSame($sort, $registry->get('pets')->defaultSort?->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validDefaultSorts(): iterable
    {
        yield 'id alone' => ['id'];
        yield 'id descending' => ['-id'];
        yield 'a sortable attribute' => ['name'];
        yield 'a sortable attribute and id' => ['-name,id'];
        yield 'a path through a to-one relationship that is not nullable' => ['owner.name'];
        yield 'the id through a to-one relationship that is not nullable' => ['-owner.id'];
        yield 'two paths' => ['owner.name,-name'];
    }

    #[DataProvider('invalidDefaultSorts')]
    public function testRefusesADeclaredDefaultSortOfTheResourceWithAPathItCannotOrderBy(
        string $sort,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::petsRegistry(petsSort: Sort::fromString($sort));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidDefaultSorts(): iterable
    {
        yield 'an attribute that is not declared' => [
            'rating',
            'the default sort of type "pets": sort path "rating" ends at "rating", which is no sortable attribute'
            . ' of type "pets"',
        ];
        yield 'an attribute that is not sortable' => [
            'description',
            'the default sort of type "pets": sort path "description" ends at "description", which is no sortable'
            . ' attribute of type "pets"',
        ];
        yield 'a relationship as the last segment' => [
            'owner',
            'the default sort of type "pets": sort path "owner" ends at "owner", which is no sortable attribute of'
            . ' type "pets"',
        ];
        yield 'a nullable attribute' => [
            'breed',
            'the default sort of type "pets" orders by "breed", and attribute "breed" of type "pets" may be null',
        ];
        yield 'a nullable attribute behind a sortable one' => [
            'name,breed',
            'orders by "breed", and attribute "breed" of type "pets" may be null',
        ];
        yield 'a nullable attribute through a to-one relationship' => [
            'owner.nickname',
            'orders by "owner.nickname", and attribute "nickname" of type "customers" may be null',
        ];
        yield 'a path through a to-many relationship' => [
            'orders.placedAt',
            'sort path "orders.placedAt" runs through "orders", which is no to-one relationship of type "pets"',
        ];
        yield 'a path through a relationship that is not declared' => [
            'supplier.name',
            'sort path "supplier.name" runs through "supplier", which is no to-one relationship of type "pets"',
        ];
        yield 'a path through a nullable to-one relationship' => [
            'breeder.name',
            'orders by "breeder.name" through relationship "breeder" of type "pets", which may be empty',
        ];
        yield 'the id through a nullable to-one relationship' => [
            'breeder.id',
            'orders by "breeder.id" through relationship "breeder" of type "pets", which may be empty',
        ];
        yield 'an attribute of the related type on the resource' => [
            'nickname',
            'sort path "nickname" ends at "nickname", which is no sortable attribute of type "pets"',
        ];
        yield 'two relationship segments, every one a to-one that is not nullable' => [
            'owner.trainer.name',
            'the default sort of type "pets": sort path "owner.trainer.name" runs through 2 relationships, and a'
            . ' sort path runs through 1 at most',
        ];
        yield 'three relationship segments' => [
            'owner.trainer.trainer.name',
            'sort path "owner.trainer.trainer.name" runs through 3 relationships',
        ];
        yield 'two relationship segments behind a path that is ordered by' => [
            'name,owner.trainer.name',
            'sort path "owner.trainer.name" runs through 2 relationships',
        ];
    }

    #[DataProvider('relationshipDefaultSorts')]
    public function testChecksTheDefaultSortOfAToManyRelationshipAgainstItsRelatedType(
        string $sort,
        ?string $message,
    ): void {
        if ($message !== null) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage($message);
        }

        $registry = self::petsRegistry(ordersSort: Sort::fromString($sort));

        self::assertSame($sort, $registry->get('pets')->relationships['orders']->defaultSort?->toString());
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function relationshipDefaultSorts(): iterable
    {
        yield 'an attribute of the related type' => ['-placedAt', null];
        yield 'id' => ['id', null];
        yield 'an attribute of the declaring type' => [
            'name',
            'the default sort of relationship "orders" of type "pets": sort path "name" ends at "name", which is'
            . ' no sortable attribute of type "orders"',
        ];
        yield 'a path through a nullable to-one of the related type' => [
            'pet.name',
            'the default sort of relationship "orders" of type "pets" orders by "pet.name" through relationship'
            . ' "pet" of type "orders", which may be empty',
        ];
        yield 'a path through a to-one of the related type that is not nullable' => ['customer.name', null];
        yield 'the id of a resource the related type points at' => ['customer.id', null];
        yield 'two relationship segments of the related type' => [
            'customer.trainer.name',
            'the default sort of relationship "orders" of type "pets": sort path "customer.trainer.name" runs'
            . ' through 2 relationships, and a sort path runs through 1 at most',
        ];
    }

    #[DataProvider('readingsWithPolicy')]
    public function testReturnsThePaginationPolicyOfAReading(Operation $operation, ?PaginationPolicy $expected): void
    {
        self::assertEquals($expected, self::petsRegistry()->paginationPolicy($operation));
    }

    /**
     * @return iterable<string, array{Operation, ?PaginationPolicy}>
     */
    public static function readingsWithPolicy(): iterable
    {
        yield 'the collection of a resource with its own policy' => [
            self::fetchCollection('orders'),
            new PaginationPolicy(15, 30),
        ];
        yield 'the collection of a resource with the library default' => [
            self::fetchCollection('pets'),
            PaginationPolicy::libraryDefault(),
        ];
        yield 'the related route of a to-many relationship with its own policy' => [
            self::fetchRelated('pets', 'orders'),
            new PaginationPolicy(5, 20),
        ];
        yield 'the relationship route of a to-many relationship with its own policy' => [
            self::fetchRelationship('pets', 'orders'),
            new PaginationPolicy(5, 20),
        ];
        yield 'the related route of a to-many relationship takes the policy of its related type' => [
            self::fetchRelated('pets', 'tags'),
            new PaginationPolicy(8, 40),
        ];
        yield 'the relationship route of a to-many relationship takes the policy of its related type' => [
            self::fetchRelationship('pets', 'tags'),
            new PaginationPolicy(8, 40),
        ];
        yield 'the related route of a to-one relationship' => [self::fetchRelated('pets', 'owner'), null];
        yield 'the relationship route of a to-one relationship' => [self::fetchRelationship('pets', 'owner'), null];
    }

    public function testResolvesAnUndeclaredRelationshipPolicyToThePolicyOfTheRelatedType(): void
    {
        $registry = self::petsRegistry();

        self::assertNull($registry->get('pets')->relationships['tags']->paginationPolicy);
        self::assertSame(
            $registry->get('tags')->paginationPolicy,
            $registry->paginationPolicy(self::fetchRelated('pets', 'tags')),
        );
        self::assertSame(
            $registry->get('pets')->relationships['orders']->paginationPolicy,
            $registry->paginationPolicy(self::fetchRelationship('pets', 'orders')),
        );
    }

    #[DataProvider('unpaginatedOperations')]
    public function testRefusesThePaginationPolicyOfAnOperationThatReadsNoPage(Operation $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('a %s operation reads no page', $operation->kind()->value));

        self::petsRegistry()->paginationPolicy($operation);
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function unpaginatedOperations(): iterable
    {
        $parent = new Identifier('pets', '1');
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);

        yield OperationKind::FetchResource->value => [new FetchResource(
            $parent,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::CreateResource->value => [new CreateResource(
            'pets',
            null,
            new Values([]),
            new Values([]),
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::UpdateResource->value => [new UpdateResource(
            $parent,
            new Values([]),
            new Values([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::DeleteResource->value => [new DeleteResource($parent, null, $languages, new Values([]))];
        yield OperationKind::ReplaceRelationship->value => [new ReplaceRelationship(
            $parent,
            'owner',
            Linkage::toOne(null),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::AddToRelationship->value => [new AddToRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
        yield OperationKind::RemoveFromRelationship->value => [new RemoveFromRelationship(
            $parent,
            'orders',
            Linkage::toMany([]),
            null,
            null,
            $fields,
            $languages,
            new Values([]),
        )];
    }

    #[DataProvider('readingsWithSort')]
    public function testReturnsTheSortAReadingRunsIn(
        FetchCollection|FetchRelated|FetchRelationship $operation,
        string $expected,
    ): void {
        $registry = self::petsRegistry(petsSort: Sort::fromString('-name'), ordersSort: Sort::fromString('-placedAt'));

        self::assertSame($expected, $registry->readingSort($operation)->toString());
    }

    /**
     * @return iterable<string, array{FetchCollection|FetchRelated|FetchRelationship, string}>
     */
    public static function readingsWithSort(): iterable
    {
        yield 'a collection with a declared sort' => [self::fetchCollection('pets'), '-name,id'];
        yield 'a collection without one' => [self::fetchCollection('customers'), 'id'];
        yield 'a collection with the sort of the request' => [self::fetchCollection('pets', 'name'), 'name,id'];
        yield 'a collection with a sort of the request that orders by id' => [
            self::fetchCollection('pets', '-id,name'),
            '-id,name',
        ];
        yield 'the related route of a to-many relationship with a declared sort' => [
            self::fetchRelated('pets', 'orders'),
            '-placedAt,id',
        ];
        yield 'the related route of a to-many relationship with the sort of the request' => [
            self::fetchRelated('pets', 'orders', 'placedAt'),
            'placedAt,id',
        ];
        yield 'the relationship route of a to-many relationship with a declared sort' => [
            self::fetchRelationship('pets', 'orders'),
            '-placedAt,id',
        ];
        yield 'the related route of a to-many relationship without one' => [self::fetchRelated('pets', 'tags'), 'id'];
        yield 'the relationship route of a to-many relationship without one' => [
            self::fetchRelationship('pets', 'tags'),
            'id',
        ];
    }

    public function testReturnsASortThatOrdersByIdItselfAndIdAscendingWithoutOne(): void
    {
        $declaredId = Sort::fromString('id');
        $registry = self::petsRegistry(ordersSort: $declaredId);

        self::assertSame($declaredId, $registry->readingSort(self::fetchRelated('pets', 'orders')));
        self::assertSame($declaredId, $registry->readingSort(self::fetchRelationship('pets', 'orders')));
        self::assertSame($declaredId, $registry->relationshipReadingSort('pets', 'orders'));
        self::assertEquals(Sort::byId(), $registry->readingSort(self::fetchCollection('customers')));
        self::assertEquals(
            $registry->readingSort(self::fetchRelated('pets', 'tags')),
            $registry->readingSort(self::fetchRelated('pets', 'orders')),
        );
    }

    #[DataProvider('toOneReadings')]
    public function testRefusesTheReadingSortOfAToOneRelationship(FetchRelated|FetchRelationship $operation): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" of type "pets" is to-one, and no route sorts a to-one'
            . ' relationship');

        self::petsRegistry()->readingSort($operation);
    }

    /**
     * @return iterable<string, array{FetchRelated|FetchRelationship}>
     */
    public static function toOneReadings(): iterable
    {
        yield 'the related route' => [self::fetchRelated('pets', 'owner')];
        yield 'the relationship route' => [self::fetchRelationship('pets', 'owner')];
    }

    #[DataProvider('undeclaredReadings')]
    public function testRefusesAReadingOfATypeOrRelationshipThatIsNotDeclared(
        FetchCollection|FetchRelated|FetchRelationship $operation,
        string $message,
    ): void {
        $registry = self::petsRegistry();

        try {
            $registry->paginationPolicy($operation);
            self::fail('paginationPolicy() returned for an undeclared reading');
        } catch (OutOfBoundsException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage($message);

        $registry->readingSort($operation);
    }

    /**
     * @return iterable<string, array{FetchCollection|FetchRelated|FetchRelationship, string}>
     */
    public static function undeclaredReadings(): iterable
    {
        yield 'the collection of an undeclared type' => [
            self::fetchCollection('videos'),
            'type "videos" is not declared',
        ];
        yield 'the related route of an undeclared type' => [
            self::fetchRelated('videos', 'orders'),
            'type "videos" is not declared',
        ];
        yield 'the related route of an undeclared relationship' => [
            self::fetchRelated('pets', 'likes'),
            'type "pets" declares no relationship "likes"',
        ];
        yield 'the relationship route of an undeclared relationship' => [
            self::fetchRelationship('pets', "likes\t"),
            'type "pets" declares no relationship "likes\t"',
        ];
    }

    /**
     * @param ?string $default the declared default sort
     */
    #[DataProvider('readingSorts')]
    public function testTheReadingSortIsTheSortOfTheRequestElseTheDefaultElseTheId(
        string $sort,
        ?string $default,
        string $expected,
    ): void {
        $reading = ResourceRegistry::readingSortOf(
            $sort === '' ? new Sort([]) : Sort::fromString($sort),
            $default === null ? null : Sort::fromString($default),
        );

        self::assertSame($expected, $reading->toString());
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function readingSorts(): iterable
    {
        yield 'the sort of the request over the default' => ['-name', 'breed', '-name,id'];
        yield 'the default without a sort' => ['', '-name', '-name,id'];
        yield 'the id without either' => ['', null, 'id'];
        yield 'a sort that orders by the id itself' => ['-id,name', null, '-id,name'];
    }

    public function testThePageOfAnEndpointTakesItsOwnPolicyAndDefaultSort(): void
    {
        $registry = self::petsRegistry(petsSort: Sort::fromString('name'));
        $endpoint = Samples::pageEndpoint(
            defaultSort: Sort::fromString('-name'),
            paginationPolicy: new PaginationPolicy(3, 30),
        );

        self::assertEquals(new PaginationPolicy(3, 30), $registry->endpointPaginationPolicy($endpoint));
        self::assertSame('-name', $registry->endpointDefaultSort($endpoint)?->toString());
    }

    public function testThePageOfAnEndpointFallsBackToThePolicyAndTheDefaultSortOfItsType(): void
    {
        $registry = self::petsRegistry(petsSort: Sort::fromString('name'));
        $endpoint = Samples::pageEndpoint();

        self::assertSame($registry->get('pets')->paginationPolicy, $registry->endpointPaginationPolicy($endpoint));
        self::assertSame($registry->get('pets')->defaultSort, $registry->endpointDefaultSort($endpoint));
        self::assertNull(self::petsRegistry()->endpointDefaultSort($endpoint));
    }

    public function testAnEndpointWithoutAPageHasNoPolicyOfAPage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" responds with no page, and a pagination policy and a'
            . ' default sort serve a page');

        self::petsRegistry()->endpointPaginationPolicy(Samples::endpoint());
    }

    public function testReturnsThePaginationPolicyOfAToManyRelationship(): void
    {
        $registry = self::petsRegistry();

        self::assertEquals(new PaginationPolicy(5, 20), $registry->relationshipPaginationPolicy('pets', 'orders'));
        self::assertSame(
            $registry->get('pets')->relationships['orders']->paginationPolicy,
            $registry->relationshipPaginationPolicy('pets', 'orders'),
        );
        self::assertEquals(new PaginationPolicy(8, 40), $registry->relationshipPaginationPolicy('pets', 'tags'));
        self::assertSame(
            $registry->get('tags')->paginationPolicy,
            $registry->relationshipPaginationPolicy('pets', 'tags'),
        );
    }

    public function testReturnsTheReadingSortOfAToManyRelationship(): void
    {
        $registry = self::petsRegistry(ordersSort: Sort::fromString('-placedAt'));

        self::assertEquals(Sort::fromString('-placedAt,id'), $registry->relationshipReadingSort('pets', 'orders'));
        self::assertEquals(Sort::byId(), $registry->relationshipReadingSort('pets', 'tags'));
    }

    public function testRefusesThePaginationPolicyOfAToOneRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" of type "pets" is to-one, and no route paginates a'
            . ' to-one relationship');

        self::petsRegistry()->relationshipPaginationPolicy('pets', 'owner');
    }

    public function testRefusesTheReadingSortOfAToOneRelationshipWithoutAnOperation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" of type "pets" is to-one, and no route sorts a to-one'
            . ' relationship');

        self::petsRegistry()->relationshipReadingSort('pets', 'owner');
    }

    #[DataProvider('undeclaredRelationships')]
    public function testRefusesARelationshipLookupOfATypeOrRelationshipThatIsNotDeclared(
        string $type,
        string $relationship,
        string $message,
    ): void {
        $registry = self::petsRegistry();

        foreach ([
            static function () use ($registry, $type, $relationship): mixed {
                return $registry->relationshipPaginationPolicy($type, $relationship);
            },
            static function () use ($registry, $type, $relationship): mixed {
                return $registry->relationshipReadingSort($type, $relationship);
            },
            static function () use ($registry, $type, $relationship): mixed {
                return $registry->relationshipOf($type, $relationship);
            },
        ] as $lookup) {
            try {
                $lookup();
                self::fail('a lookup returned for an undeclared relationship');
            } catch (OutOfBoundsException $exception) {
                self::assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testReturnsTheRelationshipATypeDeclaresUnderAName(): void
    {
        $registry = self::petsRegistry();

        self::assertSame($registry->get('pets')->relationships['orders'], $registry->relationshipOf('pets', 'orders'));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function undeclaredRelationships(): iterable
    {
        yield 'an undeclared type' => ['videos', 'orders', 'type "videos" is not declared'];
        yield 'an undeclared type with a control character' => [
            "videos\n",
            'orders',
            'type "videos\n" is not declared',
        ];
        yield 'an undeclared relationship' => ['pets', 'likes', 'type "pets" declares no relationship "likes"'];
        yield 'an undeclared relationship with a control character' => [
            'pets',
            "likes\t",
            'type "pets" declares no relationship "likes\t"',
        ];
        yield 'a relationship of another type' => [
            'customers',
            'orders',
            'type "customers" declares no relationship "orders"',
        ];
    }

    #[DataProvider('toManyRelationships')]
    public function testTheReadingsOfARouteReturnWhatTheRelationshipLookupsReturn(string $relationship): void
    {
        $registry = self::petsRegistry(ordersSort: Sort::fromString('-placedAt'));
        $policy = $registry->relationshipPaginationPolicy('pets', $relationship);
        $sort = $registry->relationshipReadingSort('pets', $relationship);

        foreach ([
            self::fetchRelated('pets', $relationship),
            self::fetchRelationship('pets', $relationship),
        ] as $operation) {
            self::assertSame($policy, $registry->paginationPolicy($operation));
            self::assertEquals($sort, $registry->readingSort($operation));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function toManyRelationships(): iterable
    {
        yield 'with a declared policy and sort' => ['orders'];
        yield 'without either' => ['tags'];
    }

    /**
     * @param list<string> $relationships
     */
    #[DataProvider('resolvedSortPaths')]
    public function testResolvesASortPath(
        string $path,
        array $relationships,
        string $type,
        ?string $attribute,
        bool $nullable,
    ): void {
        $resolved = self::petsRegistry()->sortPath('pets', FieldPath::fromString($path));

        self::assertSame($path, $resolved->path->toString());
        self::assertSame(
            $relationships,
            array_map(
                static function (RelationshipMetadata $relationship): string {
                    return $relationship->name;
                },
                $resolved->relationships,
            ),
        );
        self::assertSame($type, $resolved->resource->type);
        self::assertSame($attribute, $resolved->attribute?->name);
        self::assertSame($nullable, $resolved->nullable);
    }

    /**
     * @return iterable<string, array{string, list<string>, string, ?string, bool}>
     */
    public static function resolvedSortPaths(): iterable
    {
        yield 'id' => ['id', [], 'pets', null, false];
        yield 'a sortable attribute' => ['name', [], 'pets', 'name', false];
        yield 'a nullable attribute' => ['breed', [], 'pets', 'breed', true];
        yield 'an attribute through a to-one relationship that is not nullable' => [
            'owner.name',
            ['owner'],
            'customers',
            'name',
            false,
        ];
        yield 'a nullable attribute through a to-one relationship' => [
            'owner.nickname',
            ['owner'],
            'customers',
            'nickname',
            true,
        ];
        yield 'an attribute through a nullable to-one relationship' => [
            'breeder.name',
            ['breeder'],
            'customers',
            'name',
            true,
        ];
        yield 'the id through a to-one relationship that is not nullable' => [
            'owner.id',
            ['owner'],
            'customers',
            null,
            false,
        ];
        yield 'the id through a nullable to-one relationship' => ['breeder.id', ['breeder'], 'customers', null, true];
    }

    #[DataProvider('unresolvedSortPaths')]
    public function testRefusesASortPathItDoesNotResolve(string $path, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::petsRegistry()->sortPath('pets', FieldPath::fromString($path));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unresolvedSortPaths(): iterable
    {
        yield 'an attribute that is not declared' => [
            'rating',
            'sort path "rating" ends at "rating", which is no sortable attribute of type "pets", and a sort path'
            . ' ends at a sortable attribute or at the id',
        ];
        yield 'an attribute that is not sortable' => [
            'description',
            'sort path "description" ends at "description", which is no sortable attribute of type "pets"',
        ];
        yield 'a relationship as the last segment' => [
            'owner',
            'sort path "owner" ends at "owner", which is no sortable attribute of type "pets"',
        ];
        yield 'the id through a to-many relationship' => [
            'orders.id',
            'sort path "orders.id" runs through "orders", which is no to-one relationship of type "pets"',
        ];
        yield 'the id two relationships away' => [
            'owner.trainer.id',
            'sort path "owner.trainer.id" runs through 2 relationships, and a sort path runs through 1 at most',
        ];
        yield 'a path through a to-many relationship' => [
            'orders.placedAt',
            'sort path "orders.placedAt" runs through "orders", which is no to-one relationship of type "pets",'
            . ' and a sort path runs through to-one relationships alone',
        ];
        yield 'a path through a relationship that is not declared' => [
            'supplier.name',
            'sort path "supplier.name" runs through "supplier", which is no to-one relationship of type "pets"',
        ];
        yield 'two relationship segments' => [
            'owner.trainer.name',
            'sort path "owner.trainer.name" runs through 2 relationships, and a sort path runs through 1 at most',
        ];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listedSortPaths')]
    public function testListsTheSortPathsOfAType(string $type, array $expected): void
    {
        self::assertSame(
            $expected,
            array_map(
                static function (SortPath $path): string {
                    return $path->path->toString();
                },
                self::petsRegistry()->sortPaths($type),
            ),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function listedSortPaths(): iterable
    {
        yield 'pets' => [
            'pets',
            [
                'id',
                'name',
                'breed',
                'owner.id',
                'owner.name',
                'owner.nickname',
                'breeder.id',
                'breeder.name',
                'breeder.nickname',
            ],
        ];
        yield 'customers, whose trainer is a customer again' => [
            'customers',
            ['id', 'name', 'nickname', 'trainer.id', 'trainer.name', 'trainer.nickname'],
        ];
        yield 'tags, which declare no sortable attribute' => ['tags', ['id']];
    }

    public function testEveryListedSortPathResolvesToWhatTheListCarries(): void
    {
        $registry = self::petsRegistry();

        foreach (['pets', 'customers', 'orders', 'tags'] as $type) {
            foreach ($registry->sortPaths($type) as $listed) {
                self::assertEquals($listed, $registry->sortPath($type, $listed->path));
            }
        }
    }

    #[DataProvider('includePathsOfARoute')]
    public function testTellsWhetherARouteIncludesAPath(?string $relationship, string $path, bool $expected): void
    {
        self::assertSame(
            $expected,
            self::shopRegistry()->isIncludable('pets', $relationship, new IncludePath(explode('.', $path))),
        );
    }

    /**
     * @return iterable<string, array{?string, string, bool}>
     */
    public static function includePathsOfARoute(): iterable
    {
        yield 'an includable relationship' => [null, 'owner', true];
        yield 'a path as deep as the include depth' => [null, 'owner.badge', true];
        yield 'a path deeper than the include depth' => [null, 'owner.trainer.badge', false];
        yield 'a relationship that forbids inclusion' => [null, 'tags', false];
        yield 'a relationship that is not declared' => [null, 'supplier', false];
        yield 'a path through a relationship the related type does not declare' => [null, 'orders.badge', false];
        yield 'the relationship of a relationship route' => ['owner', 'owner', true];
        yield 'a path through the relationship of the route, counted from the declaring type' => [
            'owner',
            'owner.trainer',
            true,
        ];
        yield 'a path that leaves out the relationship of the route' => ['owner', 'orders', false];
        yield 'a path too deep for the declaring type' => ['owner', 'owner.trainer.badge', false];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listedIncludePaths')]
    public function testListsTheIncludePathsOfARouteDepthFirst(?string $relationship, array $expected): void
    {
        self::assertSame(
            $expected,
            array_map(
                static function (IncludePath $path): string {
                    return $path->toString();
                },
                self::shopRegistry()->includePaths('pets', $relationship),
            ),
        );
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function listedIncludePaths(): iterable
    {
        yield 'a resource route' => [null, ['owner', 'owner.trainer', 'owner.badge', 'orders', 'orders.customer']];
        yield 'a relationship route' => ['owner', ['owner', 'owner.trainer', 'owner.badge']];
        yield 'the route of a relationship that forbids inclusion' => ['tags', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('includedTypesOfARoute')]
    public function testListsTheTypesARouteIncludesThoseOfTheShorterPathsFirst(
        ?string $relationship,
        array $expected,
    ): void {
        self::assertSame($expected, self::shopRegistry()->includedTypes('pets', $relationship));
    }

    /**
     * @return iterable<string, array{?string, list<string>}>
     */
    public static function includedTypesOfARoute(): iterable
    {
        yield 'a resource route, where orders is one relationship away and tags two' => [
            null,
            ['customers', 'orders', 'tags'],
        ];
        yield 'a relationship route' => ['owner', ['customers', 'tags']];
        yield 'the route of a relationship that forbids inclusion' => ['tags', []];
    }

    public function testEveryListedIncludePathIsIncludable(): void
    {
        $registry = self::shopRegistry();

        foreach ([null, 'owner', 'orders'] as $relationship) {
            foreach ($registry->includePaths('pets', $relationship) as $path) {
                self::assertTrue($registry->isIncludable('pets', $relationship, $path), $path->toString());
            }
        }
    }

    #[DataProvider('resolvedFilterMembers')]
    public function testResolvesAFilterMember(
        string $type,
        string $name,
        ?string $attribute,
        ?string $relationship,
    ): void {
        $resolved = self::shopRegistry()->filterMember($type, $name);

        self::assertNotNull($resolved);
        self::assertSame($name, $resolved->name);
        self::assertSame($type, $resolved->resource->type);
        self::assertSame($attribute, $resolved->attribute?->name);
        self::assertSame($relationship, $resolved->relationship?->name);
    }

    /**
     * @return iterable<string, array{string, string, ?string, ?string}>
     */
    public static function resolvedFilterMembers(): iterable
    {
        yield 'an attribute with operators' => ['pets', 'name', 'name', null];
        yield 'a to-one relationship' => ['pets', 'owner', null, 'owner'];
        yield 'a to-many relationship' => ['pets', 'orders', null, 'orders'];
        yield 'a relationship that forbids inclusion' => ['pets', 'tags', null, 'tags'];
        yield 'the id of a type that declares operators for it' => ['customers', 'id', null, null];
    }

    #[DataProvider('unresolvedFilterMembers')]
    public function testRefusesANameThatIsNoFilterMember(string $type, string $name): void
    {
        self::assertNull(self::shopRegistry()->filterMember($type, $name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unresolvedFilterMembers(): iterable
    {
        yield 'an attribute without operators' => ['pets', 'description'];
        yield 'a field that is not declared' => ['pets', 'rating'];
        yield 'the id of a type that declares no operator for it' => ['pets', 'id'];
        yield 'an attribute through a relationship, dotted' => ['pets', 'owner.name'];
        yield 'the id through a relationship, dotted' => ['pets', 'owner.id'];
        yield 'a relationship through a relationship, dotted' => ['pets', 'owner.trainer'];
        yield 'a logical operator' => ['pets', '@or'];
    }

    public function testListsTheFilterMembersOfATypeInDeclarationOrder(): void
    {
        $names = static function (string $type): array {
            return array_map(
                static function (FilterMember $member): string {
                    return $member->name;
                },
                self::shopRegistry()->filterMembers($type),
            );
        };

        self::assertSame(['name', 'owner', 'orders', 'tags'], $names('pets'));
        self::assertSame(['id', 'name', 'trainer', 'badge'], $names('customers'));
    }

    public function testEveryListedFilterMemberResolvesToWhatTheListCarries(): void
    {
        $registry = self::shopRegistry();

        foreach (['pets', 'customers', 'orders', 'tags'] as $type) {
            foreach ($registry->filterMembers($type) as $listed) {
                self::assertEquals($listed, $registry->filterMember($type, $listed->name));
            }
        }
    }

    private static function except(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Resource, [$kind], 'a type');
    }

    /**
     * the shape the tests rely on: `pets.owner` is not nullable and `pets.breeder` is;
     * the relationship `pets.orders` declares its own policy and `pets.tags` does not;
     * `customers.nickname` may be null, and `customers.trainer` points back at `customers`
     * and is not nullable, so `owner.trainer.name` fails on its depth alone;
     * `orders.pet` is nullable and `orders.customer` is not;
     * the types `orders` and `tags` declare their own policies
     */
    private static function petsRegistry(?Sort $petsSort = null, ?Sort $ordersSort = null): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [
                    Samples::attribute('name', sortable: true),
                    Samples::attribute('breed', new NullableCodec(new StringCodec()), sortable: true),
                    Samples::attribute('description'),
                ],
                relationships: [
                    Samples::toOne('owner', 'customers', nullable: false),
                    Samples::toOne('breeder', 'customers', updates: [RelationshipUpdate::Replace]),
                    Samples::toMany(
                        'orders',
                        'orders',
                        defaultSort: $ordersSort,
                        paginationPolicy: new PaginationPolicy(5, 20),
                        updates: [RelationshipUpdate::Add, RelationshipUpdate::Remove],
                    ),
                    Samples::toMany('tags', 'tags'),
                ],
                capabilities: [
                    Capability::CollectionReader,
                    Capability::RelatedLoader,
                    Capability::RelationshipReplacer,
                    Capability::RelationshipAdder,
                    Capability::RelationshipRemover,
                ],
                defaultSort: $petsSort,
            ),
            Samples::resource(
                'customers',
                attributes: [
                    Samples::attribute('name', sortable: true),
                    Samples::attribute('nickname', new NullableCodec(new StringCodec()), sortable: true),
                ],
                relationships: [Samples::toOne('trainer', 'customers', nullable: false)],
                capabilities: [Capability::CollectionReader],
            ),
            Samples::resource(
                'orders',
                attributes: [Samples::attribute('placedAt', sortable: true)],
                relationships: [
                    Samples::toOne('pet', 'pets'),
                    Samples::toOne('customer', 'customers', nullable: false),
                ],
                capabilities: [Capability::CollectionReader],
                paginationPolicy: new PaginationPolicy(15, 30),
            ),
            Samples::resource(
                'tags',
                capabilities: [Capability::CollectionReader],
                paginationPolicy: new PaginationPolicy(8, 40),
            ),
        ]);
    }

    /**
     * the shape the include and filter tests rely on: `pets` reaches two relationships deep;
     * `pets.tags` forbids inclusion; `customers.trainer` points back at `customers`,
     * and `customers.badge` reaches `tags`, two relationships away from `pets`;
     * `pets.description` declares no operator
     */
    private static function shopRegistry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name', operators: ['eq']), Samples::attribute('description')],
                relationships: [
                    Samples::toOne('owner', 'customers'),
                    Samples::toMany('orders', 'orders'),
                    Samples::toMany('tags', 'tags', includable: false),
                ],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
                maxIncludeDepth: 2,
            ),
            Samples::resource(
                'customers',
                attributes: [Samples::attribute('name', operators: ['eq'])],
                relationships: [Samples::toOne('trainer', 'customers'), Samples::toOne('badge', 'tags')],
                capabilities: [Capability::CollectionReader],
                id: Samples::id(['eq']),
            ),
            Samples::resource(
                'orders',
                attributes: [Samples::attribute('placedAt', operators: ['gt'])],
                relationships: [Samples::toOne('customer', 'customers')],
                capabilities: [Capability::CollectionReader],
            ),
            Samples::resource(
                'tags',
                attributes: [Samples::attribute('label', operators: ['eq'])],
                capabilities: [Capability::CollectionReader],
            ),
        ]);
    }

    private static function fetchCollection(string $type, ?string $sort = null): FetchCollection
    {
        return new FetchCollection(
            $type,
            null,
            self::requested($sort),
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    private static function fetchRelated(string $type, string $relationship, ?string $sort = null): FetchRelated
    {
        return new FetchRelated(
            new Identifier($type, '1'),
            $relationship,
            null,
            self::requested($sort),
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    private static function requested(?string $sort): Sort
    {
        return $sort === null ? new Sort([]) : Sort::fromString($sort);
    }

    private static function fetchRelationship(string $type, string $relationship): FetchRelationship
    {
        return new FetchRelationship(
            new Identifier($type, '1'),
            $relationship,
            null,
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    public function testQueryParametersOfReturnsTheParametersOfTheRouteOfAKind(): void
    {
        $nearBy = new QueryParameterMetadata('nearBy', new StringCodec(), false);
        $since = new QueryParameterMetadata('since', new StringCodec(), false);
        $registry = new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [Samples::toMany('tags', 'tags', queryParameters: [
                    OperationKind::FetchRelated->value => [$since],
                ])],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
                queryParameters: [OperationKind::FetchCollection->value => [$nearBy]],
            ),
            Samples::resource('tags', capabilities: [Capability::CollectionReader]),
        ]);

        self::assertSame([$nearBy], $registry->queryParametersOf(OperationKind::FetchCollection, 'pets', null));
        self::assertSame([$since], $registry->queryParametersOf(OperationKind::FetchRelated, 'pets', 'tags'));
        self::assertSame([], $registry->queryParametersOf(OperationKind::FetchRelationship, 'pets', 'tags'));
        self::assertSame([], $registry->queryParametersOf(OperationKind::FetchResource, 'tags', null));
    }

    public function testQueryParametersOfRefusesARelationshipThatDoesNotMatchTheKind(): void
    {
        $registry = new ResourceRegistry([Samples::resource('pets')]);

        try {
            $registry->queryParametersOf(OperationKind::FetchResource, 'pets', 'tags');
            self::fail('a resource route took a relationship');
        } catch (InvalidArgumentException $refusal) {
            self::assertSame(
                'the route of fetch_resource addresses no relationship, and got "tags"',
                $refusal->getMessage(),
            );
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the route of fetch_related addresses a relationship, and got none');

        $registry->queryParametersOf(OperationKind::FetchRelated, 'pets', null);
    }
}
