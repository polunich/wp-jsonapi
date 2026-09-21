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
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionParameter;

/**
 * every permission map here is written by hand, so this test pins `enables()`
 * rather than trusting `Samples`, which derives the enabled kinds on its own
 *
 * @internal
 */
#[CoversClass(ResourceMetadata::class)]
final class ResourceMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResourceMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheMembersOfTheResourceAndItsReadersKeepTheirNamesAndTypes(): void
    {
        $class = new ReflectionClass(ResourceMetadata::class);

        self::assertSame(
            [
                ['type', 'string'],
                ['id', IdMetadata::class],
                ['idParameterName', 'string'],
                ['attributes', 'array'],
                ['relationships', 'array'],
                ['capabilities', 'array'],
                ['permissions', 'array'],
                ['monitorTypes', 'array'],
                ['excluded', RouteExclusion::class],
                ['clientIdPolicy', ClientIdPolicy::class],
                ['versionPolicy', '?' . VersionPolicy::class],
                ['defaultSort', '?' . Sort::class],
                ['paginationPolicy', PaginationPolicy::class],
                ['maxIncludeDepth', 'int'],
                ['description', '?string'],
                ['queryParameters', 'array'],
                ['securitySchemes', 'array'],
                ['path', '?array'],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertSame(
            [['kind', OperationKind::class]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod('enables')->getParameters(),
            ),
        );
        self::assertSame('bool', (string) $class->getMethod('enables')->getReturnType());
        self::assertSame([], $class->getMethod('idParameter')->getParameters());
        self::assertSame(PathParameter::class, (string) $class->getMethod('idParameter')->getReturnType());
    }

    public function testTheRoutesLieAtTheTypeUnlessAPathIsGiven(): void
    {
        self::assertSame(['pets'], Samples::resource('pets')->path);
        self::assertSame(['database', 'tables'], Samples::resource('pets', path: ['database', 'tables'])->path);
    }

    /**
     * @param list<string> $path
     */
    #[DataProvider('pathsWithoutASegment')]
    public function testRefusesAPathWithoutASegmentOrWithAnEmptyOne(array $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'type "pets" lies at a path without a segment or with an empty one, and a route has a segment at least and'
            . ' never an empty one',
        );

        Samples::resource('pets', path: $path);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function pathsWithoutASegment(): iterable
    {
        yield 'no segment' => [[]];
        yield 'an empty segment' => [['database', '']];
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $id = Samples::id();
        $name = Samples::attribute('name', sortable: true);
        $owner = Samples::toOne('owner', 'customers');
        $loader = ImplementationReference::service('loader');
        $reader = ImplementationReference::service('reader');
        $permission = ImplementationReference::service('permission');
        $version = new VersionPolicy(static function (Pet $pet): string {
            return $pet->version;
        }, true);
        $sort = Sort::fromString('-name');
        $policy = new PaginationPolicy(20, 50);
        $capabilities = ['resource_loader' => $loader, 'collection_reader' => $reader, 'resource_creator' => $loader];
        $permissions = [
            'fetch_resource' => $permission,
            'fetch_collection' => $permission,
            'fetch_related' => $permission,
            'fetch_relationship' => $permission,
            'create_resource' => $permission,
        ];

        $resource = new ResourceMetadata(
            'pets',
            $id,
            'pet_id',
            ['name' => $name],
            ['owner' => $owner],
            $capabilities,
            $permissions,
            ['create_resource' => 'jobs'],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            $version,
            $sort,
            $policy,
            2,
            'the pets of the shop',
        );

        self::assertSame('pets', $resource->type);
        self::assertSame($id, $resource->id);
        self::assertSame('pet_id', $resource->idParameterName);
        self::assertSame(['name' => $name], $resource->attributes);
        self::assertSame(['owner' => $owner], $resource->relationships);
        self::assertSame($capabilities, $resource->capabilities);
        self::assertSame($permissions, $resource->permissions);
        self::assertSame(['create_resource' => 'jobs'], $resource->monitorTypes);
        self::assertSame(ClientIdPolicy::Forbidden, $resource->clientIdPolicy);
        self::assertSame($version, $resource->versionPolicy);
        self::assertSame($sort, $resource->defaultSort);
        self::assertSame($policy, $resource->paginationPolicy);
        self::assertSame(2, $resource->maxIncludeDepth);
        self::assertSame('the pets of the shop', $resource->description);
    }

    #[DataProvider('versionPolicies')]
    public function testATypeRequiresPreconditionsWhereItsVersionPolicySaysSo(
        ?VersionPolicy $policy,
        bool $required,
    ): void {
        self::assertSame($required, Samples::resource('pets', versionPolicy: $policy)->requiresPreconditions());
    }

    /**
     * @return iterable<string, array{?VersionPolicy, bool}>
     */
    public static function versionPolicies(): iterable
    {
        $accessor = static function (Pet $pet): string {
            return $pet->version;
        };

        yield 'no version' => [null, false];
        yield 'a version, preconditions optional' => [new VersionPolicy($accessor, false), false];
        yield 'a version, preconditions required' => [new VersionPolicy($accessor, true), true];
    }

    #[DataProvider('impossibleTypes')]
    public function testRefusesATypeThatIsNoMemberName(string $type, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::resource(type: $type);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleTypes(): iterable
    {
        yield 'the empty type' => ['', 'type "" is no member name'];
        yield 'a slash' => ['shop/pets', 'type "shop/pets" is no member name'];
        yield 'a control character, escaped' => ["pets\x7f", 'type "pets\177" is no member name'];
    }

    public function testRefusesAnIdCodecWhoseSegmentPatternBreaksARule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the id of type "pets" is read by %s, whose segment pattern "[0-9]*" matches the empty segment',
            DescribedCodec::class,
        ));

        self::resource(id: new IdMetadata(
            new DescribedCodec(new Schema(type: JsonType::String), '[0-9]*'),
            static function (Pet $pet): string {
                return $pet->id;
            },
            false,
        ));
    }

    public function testRefusesARouteParameterThatIsNoGroupNameOnEveryPhp(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the id parameter of type "pets" is named "pet-id", and a path parameter is named by a letter or'
            . ' "_" and at most 31 more letters, digits or "_"',
        );

        self::resource(idParameterName: 'pet-id');
    }

    public function testTheIdParameterCarriesTheRouteParameterAndTheIdCodec(): void
    {
        $resource = self::resource(idParameterName: 'pet_id');
        $parameter = $resource->idParameter();

        self::assertSame('pet_id', $parameter->name);
        self::assertSame($resource->id->codec, $parameter->codec);
    }

    public function testHoldsAFieldOfDigitsUnderAnIntKey(): void
    {
        $attribute = Samples::attribute('2024');
        $relationship = Samples::toOne('0', 'customers');
        $resource = self::resource(
            attributes: [2024 => $attribute],
            relationships: [0 => $relationship],
            permissions: ['fetch_resource', 'fetch_related', 'fetch_relationship'],
        );

        self::assertSame($attribute, $resource->attributes['2024']);
        self::assertSame($relationship, $resource->relationships['0']);
    }

    /**
     * @param array<array-key, AttributeMetadata>    $attributes
     * @param array<array-key, RelationshipMetadata> $relationships
     * @param list<value-of<OperationKind>>          $permissions
     */
    #[DataProvider('misplacedFields')]
    public function testRefusesAFieldHeldUnderAnotherKey(
        array $attributes,
        array $relationships,
        array $permissions,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::resource(attributes: $attributes, relationships: $relationships, permissions: $permissions);
    }

    /**
     * @return iterable<string, array{
     *     array<array-key, AttributeMetadata>,
     *     array<array-key, RelationshipMetadata>,
     *     list<value-of<OperationKind>>,
     *     string,
     * }>
     */
    public static function misplacedFields(): iterable
    {
        yield 'an attribute in a list' => [
            [Samples::attribute('name')],
            [],
            ['fetch_resource'],
            'type "pets" holds attribute "name" under the key "0"',
        ];
        yield 'an attribute under another name' => [
            ['nickname' => Samples::attribute('name')],
            [],
            ['fetch_resource'],
            'type "pets" holds attribute "name" under the key "nickname"',
        ];
        yield 'a relationship under another name' => [
            [],
            ['seller' => Samples::toOne('owner', 'customers')],
            ['fetch_resource', 'fetch_related', 'fetch_relationship'],
            'type "pets" holds relationship "owner" under the key "seller"',
        ];
        yield 'an attribute under a key with a control character, escaped' => [
            ["name\n" => Samples::attribute('name')],
            [],
            ['fetch_resource'],
            'type "pets" holds attribute "name" under the key "name\n"',
        ];
        yield 'a relationship under a key with a control character, escaped' => [
            [],
            ["owner\t" => Samples::toOne('owner', 'customers')],
            ['fetch_resource', 'fetch_related', 'fetch_relationship'],
            'type "pets" holds relationship "owner" under the key "owner\t"',
        ];
    }

    public function testRefusesANameThatIsAnAttributeAndARelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares "owner" as an attribute and as a relationship');

        self::resource(
            attributes: ['owner' => Samples::attribute('owner')],
            relationships: ['owner' => Samples::toOne('owner', 'customers')],
            permissions: ['fetch_resource', 'fetch_related', 'fetch_relationship'],
        );
    }

    public function testLeavesTheResourceLoaderToTheApiMetadata(): void
    {
        $resource = self::resource(
            capabilities: ['collection_reader'],
            permissions: ['fetch_resource', 'fetch_collection'],
            withLoader: false,
        );

        self::assertArrayNotHasKey(Capability::ResourceLoader->value, $resource->capabilities);
    }

    /**
     * @param list<RelationshipUpdate>   $updates
     * @param list<value-of<Capability>> $capabilities
     */
    #[DataProvider('updatesWithoutPort')]
    public function testRefusesAnUpdateWithoutItsPort(array $updates, array $capabilities, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::resource(
            relationships: ['orders' => Samples::toMany('orders', 'orders', updates: $updates)],
            capabilities: $capabilities,
            permissions: [
                'fetch_resource',
                'fetch_related',
                'fetch_relationship',
                'replace_relationship',
                'add_to_relationship',
                'remove_from_relationship',
            ],
        );
    }

    /**
     * @return iterable<string, array{list<RelationshipUpdate>, list<value-of<Capability>>, string}>
     */
    public static function updatesWithoutPort(): iterable
    {
        yield 'replace without a replacer' => [
            [RelationshipUpdate::Replace],
            ['relationship_adder', 'relationship_remover'],
            'relationship "orders" of type "pets" declares the update replace, and the type binds no'
            . ' relationship_replacer',
        ];
        yield 'add without an adder' => [
            [RelationshipUpdate::Add],
            ['relationship_replacer', 'relationship_remover'],
            'relationship "orders" of type "pets" declares the update add, and the type binds no relationship_adder',
        ];
        yield 'remove without a remover' => [
            [RelationshipUpdate::Add, RelationshipUpdate::Remove],
            ['relationship_adder', 'relationship_replacer'],
            'relationship "orders" of type "pets" declares the update remove, and the type binds no'
            . ' relationship_remover',
        ];
    }

    /**
     * @param list<value-of<Capability>>             $capabilities
     * @param array<array-key, RelationshipMetadata> $relationships
     * @param list<value-of<OperationKind>>          $enabled
     */
    #[DataProvider('declarations')]
    public function testEnablesTheKindsItsDeclarationSupports(
        array $capabilities,
        array $relationships,
        array $enabled,
    ): void {
        $resource = self::resource(relationships: $relationships, capabilities: $capabilities, permissions: $enabled);

        foreach (OperationKind::cases() as $kind) {
            self::assertSame(in_array($kind->value, $enabled, true), $resource->enables($kind), $kind->value);
        }
    }

    /**
     * @return iterable<string, array{
     *     list<value-of<Capability>>,
     *     array<array-key, RelationshipMetadata>,
     *     list<value-of<OperationKind>>,
     * }>
     */
    public static function declarations(): iterable
    {
        yield 'a loader alone' => [[], [], ['fetch_resource']];
        yield 'a collection reader' => [['collection_reader'], [], ['fetch_resource', 'fetch_collection']];
        yield 'a related loader and no relationship' => [['related_loader'], [], ['fetch_resource']];
        yield 'a creator' => [['resource_creator'], [], ['fetch_resource', 'create_resource']];
        yield 'an updater' => [['resource_updater'], [], ['fetch_resource', 'update_resource']];
        yield 'a deleter' => [['resource_deleter'], [], ['fetch_resource', 'delete_resource']];
        yield 'a relationship without updates' => [
            [],
            ['owner' => Samples::toOne('owner', 'customers')],
            ['fetch_resource', 'fetch_related', 'fetch_relationship'],
        ];
        yield 'relationship ports without a declared update' => [
            ['relationship_replacer', 'relationship_adder', 'relationship_remover'],
            ['owner' => Samples::toOne('owner', 'customers')],
            ['fetch_resource', 'fetch_related', 'fetch_relationship'],
        ];
        yield 'a to-one relationship with replace' => [
            ['relationship_replacer'],
            ['owner' => Samples::toOne('owner', 'customers', updates: [RelationshipUpdate::Replace])],
            ['fetch_resource', 'fetch_related', 'fetch_relationship', 'replace_relationship'],
        ];
        yield 'a to-many relationship with add and remove' => [
            ['relationship_adder', 'relationship_remover'],
            ['orders' => Samples::toMany(
                'orders',
                'orders',
                updates: [RelationshipUpdate::Add, RelationshipUpdate::Remove],
            )],
            [
                'fetch_resource',
                'fetch_related',
                'fetch_relationship',
                'add_to_relationship',
                'remove_from_relationship',
            ],
        ];
        yield 'updates spread over two relationships' => [
            ['relationship_replacer', 'relationship_remover'],
            [
                'owner' => Samples::toOne('owner', 'customers', updates: [RelationshipUpdate::Replace]),
                'orders' => Samples::toMany('orders', 'orders', updates: [RelationshipUpdate::Remove]),
            ],
            [
                'fetch_resource',
                'fetch_related',
                'fetch_relationship',
                'replace_relationship',
                'remove_from_relationship',
            ],
        ];
        yield 'every capability' => [
            [
                'collection_reader',
                'related_loader',
                'resource_creator',
                'resource_updater',
                'resource_deleter',
                'relationship_replacer',
                'relationship_adder',
                'relationship_remover',
            ],
            ['orders' => Samples::toMany(
                'orders',
                'orders',
                updates: [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
            )],
            array_map(static function (OperationKind $kind): string {
                return $kind->value;
            }, OperationKind::cases()),
        ];
    }

    /**
     * @param list<value-of<Capability>>             $capabilities
     * @param array<array-key, RelationshipMetadata> $relationships
     * @param list<value-of<OperationKind>>          $enabled
     */
    #[DataProvider('exclusions')]
    public function testAnExcludedRouteIsNotEnabled(
        RouteExclusion $excluded,
        array $capabilities,
        array $relationships,
        array $enabled,
    ): void {
        $resource = self::resource(
            relationships: $relationships,
            capabilities: $capabilities,
            permissions: $enabled,
            excluded: $excluded,
        );

        foreach (OperationKind::cases() as $kind) {
            self::assertSame(in_array($kind->value, $enabled, true), $resource->enables($kind), $kind->value);
        }
    }

    /**
     * @return iterable<string, array{
     *     RouteExclusion,
     *     list<value-of<Capability>>,
     *     array<array-key, RelationshipMetadata>,
     *     list<value-of<OperationKind>>,
     * }>
     */
    public static function exclusions(): iterable
    {
        yield 'the fetch of the resource' => [self::except(OperationKind::FetchResource), [], [], []];
        yield 'the fetch of the collection beside its reader' => [
            self::except(OperationKind::FetchCollection),
            ['collection_reader'],
            [],
            ['fetch_resource'],
        ];
        yield 'a write whose port is not bound' => [
            self::except(OperationKind::CreateResource),
            [],
            [],
            ['fetch_resource'],
        ];
        yield 'only the collection routes' => [
            RouteExclusion::only(
                RouteOwner::Resource,
                [OperationKind::FetchCollection, OperationKind::CreateResource],
                'type "pets"',
            ),
            ['collection_reader', 'resource_creator'],
            [],
            ['fetch_collection', 'create_resource'],
        ];
        yield 'a related route another relationship keeps' => [
            RouteExclusion::none(RouteOwner::Resource),
            [],
            [
                'owner' => Samples::toOne(
                    'owner',
                    'customers',
                    excluded: self::exceptRead(OperationKind::FetchRelated),
                ),
                'vet' => Samples::toOne('vet', 'customers'),
            ],
            ['fetch_resource', 'fetch_related', 'fetch_relationship'],
        ];
        yield 'a related route every relationship excludes' => [
            RouteExclusion::none(RouteOwner::Resource),
            [],
            [
                'owner' => Samples::toOne(
                    'owner',
                    'customers',
                    excluded: self::exceptRead(OperationKind::FetchRelated),
                ),
                'vet' => Samples::toOne('vet', 'customers', excluded: self::exceptRead(OperationKind::FetchRelated)),
            ],
            ['fetch_resource', 'fetch_relationship'],
        ];
        yield 'both reads of the only relationship' => [
            RouteExclusion::none(RouteOwner::Resource),
            [],
            [
                'owner' => Samples::toOne(
                    'owner',
                    'customers',
                    excluded: RouteExclusion::except(
                        RouteOwner::Relationship,
                        [OperationKind::FetchRelated, OperationKind::FetchRelationship],
                        'relationship "owner"',
                    ),
                ),
            ],
            ['fetch_resource'],
        ];
    }

    /**
     * @param value-of<Capability> $port
     */
    #[DataProvider('excludedWrites')]
    public function testRefusesToExcludeAWriteWhosePortIsBound(OperationKind $kind, string $port): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" excludes %s and binds its %s, and binding the port of a write enables it: declare one of'
            . ' the two',
            $kind->value,
            $port,
        ));

        self::resource(capabilities: [$port], excluded: self::except($kind));
    }

    /**
     * @return iterable<string, array{OperationKind, value-of<Capability>}>
     */
    public static function excludedWrites(): iterable
    {
        yield 'a create' => [OperationKind::CreateResource, 'resource_creator'];
        yield 'an update' => [OperationKind::UpdateResource, 'resource_updater'];
        yield 'a delete' => [OperationKind::DeleteResource, 'resource_deleter'];
    }

    public function testRefusesTheExclusionOfARelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" takes the exclusion of a relationship, and a type excludes the'
            . ' routes of its resources');

        self::resource(excluded: RouteExclusion::none(RouteOwner::Relationship));
    }

    /**
     * @param list<value-of<Capability>> $capabilities
     */
    #[DataProvider('pagedLinkages')]
    public function testRefusesToExcludeTheRelationshipRouteOfAToManyLinkageADocumentCarries(
        RelationshipMetadata $orders,
        array $capabilities,
        string $carrier,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" declares the to-many relationship "orders", and %s: a document carries a to-many linkage in'
            . ' pages that link to the relationship route, so that route cannot be excluded',
            $carrier,
        ));

        self::resource(
            relationships: ['orders' => $orders],
            capabilities: $capabilities,
            permissions: ['fetch_resource', 'fetch_related', 'add_to_relationship'],
        );
    }

    /**
     * @return iterable<string, array{RelationshipMetadata, list<value-of<Capability>>, string}>
     */
    public static function pagedLinkages(): iterable
    {
        $excluded = self::exceptRead(OperationKind::FetchRelationship);

        yield 'an includable relationship' => [
            Samples::toMany('orders', 'orders', excluded: $excluded),
            [],
            'an include path may bring its linkage into a document',
        ];
        yield 'a linkage always present' => [
            Samples::toMany('orders', 'orders', includable: false, linkageAlwaysPresent: true, excluded: $excluded),
            [],
            'every document carries its linkage',
        ];
        yield 'a relationship without both reads' => [
            Samples::toMany(
                'orders',
                'orders',
                includable: false,
                linkageAlwaysPresent: true,
                excluded: RouteExclusion::except(
                    RouteOwner::Relationship,
                    [OperationKind::FetchRelated, OperationKind::FetchRelationship],
                    'relationship "orders"',
                ),
            ),
            [],
            'every document carries its linkage',
        ];
        yield 'a relationship that declares an update' => [
            Samples::toMany(
                'orders',
                'orders',
                includable: false,
                updates: [RelationshipUpdate::Add],
                excluded: $excluded,
            ),
            ['relationship_adder'],
            'each write of it gets its linkage with a 200',
        ];
    }

    public function testAToManyLinkageNoDocumentCarriesMayLeaveItsRelationshipRoute(): void
    {
        $resource = self::resource(
            relationships: [
                'orders' => Samples::toMany(
                    'orders',
                    'orders',
                    includable: false,
                    excluded: self::exceptRead(OperationKind::FetchRelationship),
                ),
            ],
            permissions: ['fetch_resource', 'fetch_related'],
        );

        self::assertFalse($resource->enables(OperationKind::FetchRelationship));
        self::assertTrue($resource->enables(OperationKind::FetchRelated));
    }

    /**
     * a to-one linkage has no pages, so it may be carried while its relationship route is excluded
     */
    public function testAToOneLinkageMayLeaveItsRelationshipRoute(): void
    {
        $resource = self::resource(
            relationships: ['owner' => Samples::toOne(
                'owner',
                'customers',
                excluded: self::exceptRead(OperationKind::FetchRelationship),
            )],
            permissions: ['fetch_resource', 'fetch_related'],
        );

        self::assertFalse($resource->enables(OperationKind::FetchRelationship));
    }

    /**
     * @param list<value-of<Capability>>    $capabilities
     * @param list<value-of<OperationKind>> $permissions
     */
    #[DataProvider('wrongPermissions')]
    public function testRefusesAPermissionMissingForAnEnabledKindOrPresentForAnother(
        array $capabilities,
        array $permissions,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::resource(capabilities: $capabilities, permissions: $permissions);
    }

    /**
     * @return iterable<string, array{
     *     list<value-of<Capability>>,
     *     list<value-of<OperationKind>>,
     *     string,
     * }>
     */
    public static function wrongPermissions(): iterable
    {
        yield 'none for fetch_resource, which is always enabled' => [
            [],
            [],
            'type "pets" enables fetch_resource and declares no permission for it',
        ];
        yield 'none for an enabled collection' => [
            ['collection_reader'],
            ['fetch_resource'],
            'type "pets" enables fetch_collection and declares no permission for it',
        ];
        yield 'none for an enabled delete' => [
            ['resource_deleter'],
            ['fetch_resource'],
            'type "pets" enables delete_resource and declares no permission for it',
        ];
        yield 'one for a collection without reader' => [
            [],
            ['fetch_resource', 'fetch_collection'],
            'type "pets" declares a permission for fetch_collection, which it does not enable',
        ];
        yield 'one for a create without creator' => [
            [],
            ['fetch_resource', 'create_resource'],
            'type "pets" declares a permission for create_resource, which it does not enable',
        ];
        yield 'one for fetch_related without relationships' => [
            [],
            ['fetch_resource', 'fetch_related'],
            'type "pets" declares a permission for fetch_related, which it does not enable',
        ];
        yield 'one for a relationship write without update' => [
            ['relationship_adder'],
            ['fetch_resource', 'add_to_relationship'],
            'type "pets" declares a permission for add_to_relationship, which it does not enable',
        ];
    }

    /**
     * @param list<value-of<Capability>>             $capabilities
     * @param list<value-of<OperationKind>>          $permissions
     * @param array<value-of<OperationKind>, string> $monitorTypes
     */
    #[DataProvider('wrongMonitorTypes')]
    public function testRefusesAMonitorTypeNoWriteOfTheResourceGets(
        array $capabilities,
        array $permissions,
        array $monitorTypes,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::resource(capabilities: $capabilities, permissions: $permissions, monitorTypes: $monitorTypes);
    }

    /**
     * @return iterable<string, array{
     *     list<value-of<Capability>>,
     *     list<value-of<OperationKind>>,
     *     array<value-of<OperationKind>, string>,
     *     string,
     * }>
     */
    public static function wrongMonitorTypes(): iterable
    {
        yield 'a read kind' => [
            ['collection_reader'],
            ['fetch_resource', 'fetch_collection'],
            ['fetch_collection' => 'jobs'],
            'type "pets" declares a monitor type for fetch_collection, and only a write gets 202',
        ];
        yield 'fetch_resource' => [
            [],
            ['fetch_resource'],
            ['fetch_resource' => 'jobs'],
            'type "pets" declares a monitor type for fetch_resource, and only a write gets 202',
        ];
        yield 'a write that is not enabled' => [
            ['resource_creator'],
            ['fetch_resource', 'create_resource'],
            ['update_resource' => 'jobs'],
            'type "pets" declares a monitor type for update_resource, which it does not enable',
        ];
        yield 'the empty type' => [
            ['resource_deleter'],
            ['fetch_resource', 'delete_resource'],
            ['delete_resource' => ''],
            'type "pets" declares the monitor type "" for delete_resource, which is no member name',
        ];
        yield 'a control character, escaped' => [
            ['resource_deleter'],
            ['fetch_resource', 'delete_resource'],
            ['delete_resource' => "jobs\n"],
            'type "pets" declares the monitor type "jobs\n" for delete_resource, which is no member name',
        ];
    }

    public function testAcceptsAMonitorTypeForEveryEnabledWrite(): void
    {
        $monitorTypes = [
            'create_resource' => 'jobs',
            'update_resource' => 'jobs',
            'delete_resource' => 'deletions',
            'replace_relationship' => 'jobs',
            'add_to_relationship' => 'jobs',
            'remove_from_relationship' => 'jobs',
        ];
        $resource = self::resource(
            relationships: ['orders' => Samples::toMany(
                'orders',
                'orders',
                updates: [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
            )],
            capabilities: [
                'resource_creator',
                'resource_updater',
                'resource_deleter',
                'relationship_replacer',
                'relationship_adder',
                'relationship_remover',
            ],
            permissions: [
                'fetch_resource',
                'fetch_related',
                'fetch_relationship',
                'create_resource',
                'update_resource',
                'delete_resource',
                'replace_relationship',
                'add_to_relationship',
                'remove_from_relationship',
            ],
            monitorTypes: $monitorTypes,
        );

        self::assertSame($monitorTypes, $resource->monitorTypes);
    }

    #[DataProvider('uniqueIds')]
    public function testAcceptsClientIdsForIdsThatAreGloballyUnique(ClientIdPolicy $policy, IdMetadata $id): void
    {
        self::assertSame($policy, self::resource(clientIdPolicy: $policy, id: $id)->clientIdPolicy);
    }

    /**
     * @return iterable<string, array{ClientIdPolicy, IdMetadata}>
     */
    public static function uniqueIds(): iterable
    {
        $uuid = new IdMetadata(new UuidCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false);
        $uuidFormat = new IdMetadata(
            new DescribedCodec(new Schema(type: JsonType::String, format: Format::Uuid)),
            static function (Pet $pet): string {
                return $pet->id;
            },
            false,
        );
        $declared = new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, true);
        $plain = new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false);

        yield 'forbidden with any id' => [ClientIdPolicy::Forbidden, $plain];
        yield 'allowed with a UUID codec' => [ClientIdPolicy::Allowed, $uuid];
        yield 'required with a UUID codec' => [ClientIdPolicy::Required, $uuid];
        yield 'allowed with another codec of the uuid format' => [ClientIdPolicy::Allowed, $uuidFormat];
        yield 'allowed with ids declared globally unique' => [ClientIdPolicy::Allowed, $declared];
        yield 'required with ids declared globally unique' => [ClientIdPolicy::Required, $declared];
    }

    #[DataProvider('policiesAcceptingIds')]
    public function testRefusesClientIdsForIdsThatAreNotGloballyUnique(ClientIdPolicy $policy, IdMetadata $id): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('type "pets" accepts client ids with the policy %s', $policy->value));

        self::resource(clientIdPolicy: $policy, id: $id);
    }

    /**
     * @return iterable<string, array{ClientIdPolicy, IdMetadata}>
     */
    public static function policiesAcceptingIds(): iterable
    {
        $plain = new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false);
        $dateTime = new IdMetadata(
            new DescribedCodec(new Schema(type: JsonType::String, format: Format::DateTime)),
            static function (Pet $pet): string {
                return $pet->id;
            },
            false,
        );

        yield 'allowed with numeric ids' => [ClientIdPolicy::Allowed, $plain];
        yield 'required with numeric ids' => [ClientIdPolicy::Required, $plain];
        yield 'allowed with another format' => [ClientIdPolicy::Allowed, $dateTime];
    }

    /**
     * a page endpoint may read the default sort of a type without a collection route,
     * so `ApiMetadata`, which sees the endpoints, judges whether anything reads it
     */
    public function testADefaultSortWithoutACollectionRouteIsLeftToTheApi(): void
    {
        $resource = self::resource(defaultSort: Sort::fromString('name'));

        self::assertSame('name', $resource->defaultSort?->toString());
    }

    public function testRefusesADefaultSortWithoutASortField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares a default sort without a sort field, and a default sort'
            . ' that is not declared is null');

        self::resource(
            capabilities: ['collection_reader'],
            permissions: ['fetch_resource', 'fetch_collection'],
            defaultSort: new Sort([]),
        );
    }

    #[DataProvider('defaultSorts')]
    public function testACollectionReaderMayComeWithOrWithoutADefaultSort(?Sort $sort): void
    {
        $resource = self::resource(
            capabilities: ['collection_reader'],
            permissions: ['fetch_resource', 'fetch_collection'],
            defaultSort: $sort,
        );

        self::assertSame($sort, $resource->defaultSort);
    }

    /**
     * @return iterable<string, array{?Sort}>
     */
    public static function defaultSorts(): iterable
    {
        yield 'no default sort' => [null];
        yield 'a declared default sort' => [Sort::fromString('-name')];
    }

    #[DataProvider('depths')]
    public function testRefusesAMaxIncludeDepthBelowOne(int $depth): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" declares the include depth %d, and an include depth is at least 1',
            $depth,
        ));

        self::resource(maxIncludeDepth: $depth);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function depths(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testAcceptsAMaxIncludeDepthThatRaisesTheDefault(): void
    {
        self::assertSame(1, self::resource(maxIncludeDepth: 1)->maxIncludeDepth);
        self::assertSame(3, self::resource(maxIncludeDepth: 3)->maxIncludeDepth);
    }

    private static function except(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Resource, [$kind], 'type "pets"');
    }

    private static function exceptRead(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Relationship, [$kind], 'a relationship');
    }

    /**
     * @param array<array-key, AttributeMetadata>    $attributes
     * @param array<array-key, RelationshipMetadata> $relationships
     * @param list<value-of<Capability>>             $capabilities
     * @param list<value-of<OperationKind>>          $permissions
     * @param array<value-of<OperationKind>, string> $monitorTypes
     */
    private static function resource(
        string $type = 'pets',
        array $attributes = [],
        array $relationships = [],
        array $capabilities = [],
        array $permissions = ['fetch_resource'],
        array $monitorTypes = [],
        ?Sort $defaultSort = null,
        int $maxIncludeDepth = 1,
        ClientIdPolicy $clientIdPolicy = ClientIdPolicy::Forbidden,
        ?IdMetadata $id = null,
        bool $withLoader = true,
        string $idParameterName = 'id',
        ?RouteExclusion $excluded = null,
    ): ResourceMetadata {
        $bound = $withLoader ? ['resource_loader' => Samples::implementation()] : [];

        foreach ($capabilities as $capability) {
            $bound[$capability] = Samples::implementation();
        }

        $declared = [];

        foreach ($permissions as $kind) {
            $declared[$kind] = Samples::implementation();
        }

        return new ResourceMetadata(
            $type,
            $id ?? Samples::id(),
            $idParameterName,
            $attributes,
            $relationships,
            $bound,
            $declared,
            $monitorTypes,
            $excluded ?? RouteExclusion::none(RouteOwner::Resource),
            $clientIdPolicy,
            null,
            $defaultSort,
            PaginationPolicy::libraryDefault(),
            $maxIncludeDepth,
            null,
        );
    }

    public function testQueryParametersOfReturnsTheParametersOfOneKindInDeclarationOrder(): void
    {
        $nearBy = new QueryParameterMetadata('nearBy', new StringCodec(), false);
        $minAge = new QueryParameterMetadata('minAge', new IntegerCodec(), true);
        $pets = Samples::resource('pets', capabilities: [Capability::CollectionReader], queryParameters: [
            OperationKind::FetchCollection->value => [$nearBy, $minAge],
        ]);

        self::assertSame([$nearBy, $minAge], $pets->queryParametersOf(OperationKind::FetchCollection));
        self::assertSame([], $pets->queryParametersOf(OperationKind::FetchResource));
    }

    public function testRefusesAQueryParameterForAKindOfARelationshipRoute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares a query parameter for fetch_related, which is no route of'
            . ' its own: a relationship declares the query parameters of its routes with its own queryParameter()');

        Samples::resource('pets', relationships: [Samples::toMany('tags', 'tags')], queryParameters: [
            OperationKind::FetchRelated->value => [new QueryParameterMetadata('since', new StringCodec(), false)],
        ]);
    }

    public function testRefusesAQueryParameterForAKindTheTypeDoesNotEnable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "nearBy" for fetch_collection, which it'
            . ' does not enable');

        Samples::resource('pets', queryParameters: [
            OperationKind::FetchCollection->value => [new QueryParameterMetadata('nearBy', new StringCodec(), false)],
        ]);
    }

    public function testRefusesAnEmptyDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares an empty description, and a declared text is never empty');

        Samples::resource('pets', description: '');
    }

    public function testRefusesAQueryParameterNamedTwiceForOneKind(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "preview" for fetch_resource twice');

        Samples::resource('pets', queryParameters: [OperationKind::FetchResource->value => [
            new QueryParameterMetadata('preview', new StringCodec(), false),
            new QueryParameterMetadata('preview', new IntegerCodec(), false),
        ]]);
    }
}
