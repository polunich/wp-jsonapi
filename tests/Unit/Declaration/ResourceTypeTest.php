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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Attribute;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ToMany;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResourceType::class)]
final class ResourceTypeTest extends TestCase
{
    public function testTheClassIsFinalMutableAndPublicApi(): void
    {
        $class = new ReflectionClass(ResourceType::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testEveryMethodReturnsTheBuilderItChanged(): void
    {
        $type = ResourceType::make('pets');
        $implementation = Implementation::service('implementation');

        self::assertSame($type, $type->id(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }));
        self::assertSame($type, $type->filterId('@eq'));
        self::assertSame($type, $type->idParameter('pet_id'));
        self::assertSame($type, $type->path('/shop/pets'));
        self::assertSame($type, $type->except(OperationKind::DeleteResource));
        self::assertSame($type, $type->attribute(Attribute::make('name', new StringCodec())->creatable()));
        self::assertSame($type, $type->relationship(ToOne::make('owner', 'customers')));
        self::assertSame($type, $type->reader($implementation));
        self::assertSame($type, $type->loader($implementation));
        self::assertSame($type, $type->relatedLoader($implementation));
        self::assertSame($type, $type->creator($implementation));
        self::assertSame($type, $type->updater($implementation));
        self::assertSame($type, $type->deleter($implementation));
        self::assertSame($type, $type->relationshipReplacer($implementation));
        self::assertSame($type, $type->relationshipAdder($implementation));
        self::assertSame($type, $type->relationshipRemover($implementation));
        self::assertSame($type, $type->permission(OperationKind::FetchResource, $implementation));
        self::assertSame($type, $type->monitorType(OperationKind::CreateResource, 'jobs'));
        self::assertSame($type, $type->clientIds());
        self::assertSame($type, $type->version(static function (Pet $pet): string {
            return $pet->version;
        }));
        self::assertSame($type, $type->defaultSort('name'));
        self::assertSame($type, $type->pagination(5, 50));
        self::assertSame($type, $type->maxIncludeDepth(2));
        self::assertSame($type, $type->description('the pets'));
        self::assertSame($type, $type->queryParameter(OperationKind::FetchCollection, 'nearBy', new StringCodec()));
    }

    public function testTheRoutesLieAtTheTypeUnlessAPathIsDeclared(): void
    {
        self::assertSame(['pets'], self::pets()->build()->path);
        self::assertSame(['database', 'tables'], self::pets()->path('/database/tables')->build()->path);
    }

    /**
     * past the brace check the path is read by `PathTemplate::written()`,
     * so every refusal but the parameter one is that of an endpoint path
     */
    #[DataProvider('pathsATypeCannotLieAt')]
    public function testRefusesAPathATypeCannotLieAt(string $path, string $message): void
    {
        $type = self::pets()->path($path);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $type->build();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pathsATypeCannotLieAt(): iterable
    {
        yield 'a parameter' => [
            '/sites/{site_id}/pets',
            'type "pets" writes the path "/sites/{site_id}/pets" with a parameter, and the path of a type takes none:'
            . ' a link to a resource of the type from another route would have no value for it',
        ];
        yield 'no leading slash' => [
            'database/tables',
            'type "pets" writes the path "database/tables", and a path begins with "/" and names one segment at least',
        ];
        yield 'an empty segment' => [
            '/database//tables',
            'type "pets" writes the path "/database//tables", which holds an empty segment',
        ];
        yield 'a dot' => ['/database/tables.json', 'type "pets" writes the segment "tables.json" in its path'];
    }

    public function testKeepsACallWhoseResultIsNotKept(): void
    {
        $type = self::pets();
        $type->description('the pets of the store');

        self::assertSame('the pets of the store', $type->build()->description);
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $implementation = Implementation::service('implementation');
        $resource = ResourceType::make('pets')
            ->id(new DigitsCodec(), static function (Pet $pet): string {
                return $pet->id;
            }, globallyUnique: true)
            ->filterId('@eq', '@in')
            ->idParameter('pet_id')
            ->attribute(Attribute::make('name', new StringCodec())->accessor(static function (Pet $pet): string {
                return $pet->name;
            }))
            ->relationship(ToOne::make('owner', 'customers')->relatedId(static function (Pet $pet): ?string {
                return $pet->ownerId;
            }))
            ->relationship(ToMany::make('tags', 'tags')->replaceable()->addable()->removable())
            ->reader($implementation)
            ->loader($implementation)
            ->relatedLoader($implementation)
            ->creator($implementation)
            ->updater($implementation)
            ->deleter($implementation)
            ->relationshipReplacer($implementation)
            ->relationshipAdder($implementation)
            ->relationshipRemover($implementation)
            ->permission(OperationKind::FetchCollection, $implementation)
            ->permission(OperationKind::FetchResource, $implementation)
            ->permission(OperationKind::FetchRelated, $implementation)
            ->permission(OperationKind::FetchRelationship, $implementation)
            ->permission(OperationKind::CreateResource, $implementation)
            ->permission(OperationKind::UpdateResource, $implementation)
            ->permission(OperationKind::DeleteResource, $implementation)
            ->permission(OperationKind::ReplaceRelationship, $implementation)
            ->permission(OperationKind::AddToRelationship, $implementation)
            ->permission(OperationKind::RemoveFromRelationship, $implementation)
            ->monitorType(OperationKind::CreateResource, 'jobs')
            ->clientIds(required: true)
            ->version(static function (Pet $pet): string {
                return $pet->version;
            }, preconditionsRequired: true)
            ->defaultSort('-name')
            ->pagination(5, 50)
            ->maxIncludeDepth(2)
            ->description('the pets of the store')
            ->build();

        self::assertSame('pets', $resource->type);
        self::assertSame('1', $resource->id->idOf(new Pet('1', 'Rex')));
        self::assertTrue($resource->id->globallyUnique);
        self::assertSame(['@eq', '@in'], $resource->id->operators);
        self::assertSame('pet_id', $resource->idParameterName);
        self::assertSame(['name'], array_keys($resource->attributes));
        self::assertSame(['owner', 'tags'], array_keys($resource->relationships));
        self::assertSame([
            Capability::CollectionReader->value,
            Capability::ResourceLoader->value,
            Capability::RelatedLoader->value,
            Capability::ResourceCreator->value,
            Capability::ResourceUpdater->value,
            Capability::ResourceDeleter->value,
            Capability::RelationshipReplacer->value,
            Capability::RelationshipAdder->value,
            Capability::RelationshipRemover->value,
        ], array_keys($resource->capabilities));
        self::assertSame('implementation', $resource->capabilities[Capability::ResourceLoader->value]->entryId);
        self::assertSame(
            array_map(static function (OperationKind $kind): string {
                return $kind->value;
            }, OperationKind::cases()),
            array_keys($resource->permissions),
        );
        self::assertSame([OperationKind::CreateResource->value => 'jobs'], $resource->monitorTypes);
        self::assertSame(ClientIdPolicy::Required, $resource->clientIdPolicy);
        $version = $resource->versionPolicy;
        self::assertNotNull($version);
        self::assertSame('1', $version->versionOf(new Pet('1', 'Rex')));
        self::assertTrue($version->preconditionsRequired);
        self::assertSame('-name', $resource->defaultSort?->toString());
        self::assertSame([5, 50], [$resource->paginationPolicy->defaultSize, $resource->paginationPolicy->maxSize]);
        self::assertSame(2, $resource->maxIncludeDepth);
        self::assertSame('the pets of the store', $resource->description);
    }

    public function testAResourceTakesTheDefaultsOfTheLibraryUntilTheDeclarationWritesMore(): void
    {
        $resource = self::pets()->build();

        self::assertSame('id', $resource->idParameterName);
        self::assertSame([], $resource->id->operators);
        self::assertSame([], $resource->attributes);
        self::assertSame([], $resource->relationships);
        self::assertSame([Capability::ResourceLoader->value], array_keys($resource->capabilities));
        self::assertSame([OperationKind::FetchResource->value], array_keys($resource->permissions));
        self::assertSame([], $resource->monitorTypes);
        self::assertSame([], $resource->excluded->kinds);
        self::assertSame(ClientIdPolicy::Forbidden, $resource->clientIdPolicy);
        self::assertNull($resource->versionPolicy);
        self::assertNull($resource->defaultSort);
        self::assertSame([10, 100], [$resource->paginationPolicy->defaultSize, $resource->paginationPolicy->maxSize]);
        self::assertSame(1, $resource->maxIncludeDepth);
        self::assertNull($resource->description);
    }

    /**
     * as `Attribute::filter()` does, and before or after `id()`
     */
    public function testTheOperatorsOfTheIdAppendAcrossCalls(): void
    {
        $type = ResourceType::make('pets')->filterId('@eq');
        $type->id(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        })->filterId('@in', '@notIn');

        self::assertSame(['@eq', '@in', '@notIn'], $type->loader(Implementation::service('loader'))->permission(
            OperationKind::FetchResource,
            Implementation::service('permission'),
        )->build()->id->operators);
    }

    public function testRefusesAnOperatorOfTheIdNamedTwice(): void
    {
        $type = self::pets()->filterId('@in')->filterId('@in');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the id declares operator "@in" twice');

        $type->build();
    }

    public function testRefusesAResourceThatDeclaresNoId(): void
    {
        $type = ResourceType::make('pets')
            ->loader(Implementation::service('loader'))
            ->permission(OperationKind::FetchResource, Implementation::service('permission'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares no id, and "Every resource object MUST also contain an'
            . ' id member" [spec]');

        $type->build();
    }

    public function testHoldsAFieldOfDigitsUnderAnIntKey(): void
    {
        $resource = self::pets()
            ->attribute(Attribute::make('0', new StringCodec())->creatable())
            ->build();

        self::assertSame([0], array_keys($resource->attributes));
        self::assertSame('0', $resource->attributes[0]->name);
    }

    public function testReadsAFieldWhereItIsHandedOver(): void
    {
        $attribute = Attribute::make('name', new StringCodec())
            ->accessor(static function (Pet $pet): string {
                return $pet->name;
            });
        $type = self::pets()->attribute($attribute);
        $attribute->sortable();

        self::assertFalse($type->build()->attributes['name']->sortable);
    }

    public function testRefusesADefaultSortThatIsNoSortWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('sort field "" is no field path');

        self::pets()->defaultSort('');
    }

    public function testRefusesAPageSizeNoPolicyCanHaveWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the default page size 50 is above the maximum page size 5');

        self::pets()->pagination(50, 5);
    }

    /**
     * @param Closure(ResourceType): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testRefusesAPartOfTheDeclarationWrittenTwice(Closure $second, string $message): void
    {
        $type = self::pets()
            ->idParameter('pet_id')
            ->path('/shop/pets')
            ->except(OperationKind::DeleteResource)
            ->attribute(Attribute::make('name', new StringCodec())->creatable())
            ->relationship(ToOne::make('owner', 'customers'))
            ->monitorType(OperationKind::CreateResource, 'jobs')
            ->clientIds()
            ->version(static function (Pet $pet): string {
                return $pet->version;
            })
            ->defaultSort('name')
            ->pagination(5, 50)
            ->maxIncludeDepth(2)
            ->description('the pets');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $second($type);
    }

    /**
     * @return iterable<string, array{Closure(ResourceType): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        yield 'an id' => [
            static function (ResourceType $type): ResourceType {
                return $type->id(new DigitsCodec(), static function (Pet $pet): string {
                    return $pet->id;
                });
            },
            'type "pets" declares an id twice, and each part of a declaration is written once',
        ];
        yield 'an id parameter' => [
            static function (ResourceType $type): ResourceType {
                return $type->idParameter('pet_id');
            },
            'type "pets" declares an id parameter twice, and each part of a declaration is written once',
        ];
        yield 'a path' => [
            static function (ResourceType $type): ResourceType {
                return $type->path('/pets');
            },
            'type "pets" declares a path twice, and each part of a declaration is written once',
        ];
        yield 'its routes by except()' => [
            static function (ResourceType $type): ResourceType {
                return $type->except(OperationKind::UpdateResource);
            },
            'type "pets" declares its routes twice, and each part of a declaration is written once',
        ];
        yield 'its routes by only()' => [
            static function (ResourceType $type): ResourceType {
                return $type->only(OperationKind::FetchResource);
            },
            'type "pets" declares its routes twice, and each part of a declaration is written once',
        ];
        yield 'an attribute of one name' => [
            static function (ResourceType $type): ResourceType {
                return $type->attribute(Attribute::make('name', new StringCodec())->creatable());
            },
            'type "pets" declares attribute "name" twice, and each part of a declaration is written once',
        ];
        yield 'a relationship of one name' => [
            static function (ResourceType $type): ResourceType {
                return $type->relationship(ToMany::make('owner', 'customers'));
            },
            'type "pets" declares relationship "owner" twice, and each part of a declaration is written once',
        ];
        yield 'a permission of one kind' => [
            static function (ResourceType $type): ResourceType {
                return $type->permission(OperationKind::FetchResource, Implementation::service('other'));
            },
            'type "pets" declares a permission for fetch_resource twice, and each part of a declaration is written'
            . ' once',
        ];
        yield 'a monitor type of one kind' => [
            static function (ResourceType $type): ResourceType {
                return $type->monitorType(OperationKind::CreateResource, 'tasks');
            },
            'type "pets" declares a monitor type for create_resource twice, and each part of a declaration is'
            . ' written once',
        ];
        yield 'a client id policy' => [
            static function (ResourceType $type): ResourceType {
                return $type->clientIds(required: true);
            },
            'type "pets" declares a client id policy twice, and each part of a declaration is written once',
        ];
        yield 'a version policy' => [
            static function (ResourceType $type): ResourceType {
                return $type->version(static function (Pet $pet): string {
                    return $pet->id;
                });
            },
            'type "pets" declares a version policy twice, and each part of a declaration is written once',
        ];
        yield 'a default sort' => [
            static function (ResourceType $type): ResourceType {
                return $type->defaultSort('-name');
            },
            'type "pets" declares a default sort twice, and each part of a declaration is written once',
        ];
        yield 'a pagination policy' => [
            static function (ResourceType $type): ResourceType {
                return $type->pagination(10, 100);
            },
            'type "pets" declares a pagination policy twice, and each part of a declaration is written once',
        ];
        yield 'an include depth' => [
            static function (ResourceType $type): ResourceType {
                return $type->maxIncludeDepth(3);
            },
            'type "pets" declares an include depth twice, and each part of a declaration is written once',
        ];
        yield 'a description' => [
            static function (ResourceType $type): ResourceType {
                return $type->description('other pets');
            },
            'type "pets" declares a description twice, and each part of a declaration is written once',
        ];
    }

    /**
     * starts from the bare type, since `pets()` already binds the resource loader
     *
     * @param Closure(ResourceType, Implementation): mixed $bind
     */
    #[DataProvider('capabilities')]
    public function testRefusesACapabilityBoundTwice(Closure $bind, Capability $capability): void
    {
        $type = ResourceType::make('pets');
        $bind($type, Implementation::service('first'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" declares an implementation of %s twice, and each part of a declaration is written once',
            $capability->value,
        ));

        $bind($type, Implementation::service('second'));
    }

    /**
     * @return iterable<string, array{Closure(ResourceType, Implementation): mixed, Capability}>
     */
    public static function capabilities(): iterable
    {
        yield 'a collection reader' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->reader($implementation);
            },
            Capability::CollectionReader,
        ];
        yield 'a resource loader' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->loader($implementation);
            },
            Capability::ResourceLoader,
        ];
        yield 'a related loader' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->relatedLoader($implementation);
            },
            Capability::RelatedLoader,
        ];
        yield 'a resource creator' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->creator($implementation);
            },
            Capability::ResourceCreator,
        ];
        yield 'a resource updater' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->updater($implementation);
            },
            Capability::ResourceUpdater,
        ];
        yield 'a resource deleter' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->deleter($implementation);
            },
            Capability::ResourceDeleter,
        ];
        yield 'a relationship replacer' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->relationshipReplacer($implementation);
            },
            Capability::RelationshipReplacer,
        ];
        yield 'a relationship adder' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->relationshipAdder($implementation);
            },
            Capability::RelationshipAdder,
        ];
        yield 'a relationship remover' => [
            static function (ResourceType $type, Implementation $implementation): ResourceType {
                return $type->relationshipRemover($implementation);
            },
            Capability::RelationshipRemover,
        ];
    }

    /**
     * @param Closure(): ResourceType $declaration
     */
    #[DataProvider('impossibleResources')]
    public function testRefusesADeclarationNoResourceCanHave(Closure $declaration, string $message): void
    {
        $type = $declaration();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $type->build();
    }

    /**
     * @return iterable<string, array{Closure(): ResourceType, string}>
     */
    public static function impossibleResources(): iterable
    {
        yield 'a type that is no member name' => [
            static function (): ResourceType {
                return ResourceType::make('pet/2')
                    ->id(new DigitsCodec(), static function (Pet $pet): string {
                        return $pet->id;
                    })
                    ->loader(Implementation::service('loader'))
                    ->permission(OperationKind::FetchResource, Implementation::service('permission'));
            },
            'type "pet/2" is no member name [spec]',
        ];
        yield 'an excluded write whose port is bound' => [
            static function (): ResourceType {
                return self::pets()->creator(Implementation::service('creator'))->except(OperationKind::CreateResource);
            },
            'type "pets" excludes create_resource and binds its resource_creator, and binding the port of a write'
            . ' enables it: declare one of the two',
        ];
        yield 'a permission for an excluded read' => [
            static function (): ResourceType {
                return self::pets()->only(OperationKind::FetchCollection)->reader(Implementation::service('reader'))
                    ->permission(OperationKind::FetchCollection, Implementation::service('permission'));
            },
            'type "pets" declares a permission for fetch_resource, which it does not enable',
        ];
        yield 'a name that is an attribute and a relationship' => [
            static function (): ResourceType {
                return self::pets()
                    ->attribute(Attribute::make('owner', new StringCodec())->creatable())
                    ->relationship(ToOne::make('owner', 'customers'))
                    ->permission(OperationKind::FetchRelated, Implementation::service('permission'))
                    ->permission(OperationKind::FetchRelationship, Implementation::service('permission'));
            },
            'type "pets" declares "owner" as an attribute and as a relationship',
        ];
        yield 'an update without its port' => [
            static function (): ResourceType {
                return self::pets()
                    ->relationship(ToMany::make('tags', 'tags')->addable())
                    ->permission(OperationKind::FetchRelated, Implementation::service('permission'))
                    ->permission(OperationKind::FetchRelationship, Implementation::service('permission'));
            },
            'relationship "tags" of type "pets" declares the update add, and the type binds no relationship_adder',
        ];
        yield 'a permission for a kind the resource does not enable' => [
            static function (): ResourceType {
                return self::pets()->permission(OperationKind::CreateResource, Implementation::service('permission'));
            },
            'type "pets" declares a permission for create_resource, which it does not enable',
        ];
        yield 'no permission for a kind the resource enables' => [
            static function (): ResourceType {
                return self::pets()->reader(Implementation::service('reader'));
            },
            'type "pets" enables fetch_collection and declares no permission for it',
        ];
        yield 'a monitor type for a fetch' => [
            static function (): ResourceType {
                return self::pets()->monitorType(OperationKind::FetchResource, 'jobs');
            },
            'type "pets" declares a monitor type for fetch_resource, and only a write gets 202',
        ];
        yield 'client ids on ids that are not globally unique' => [
            static function (): ResourceType {
                return self::pets()->clientIds();
            },
            'type "pets" accepts client ids with the policy allowed, and its ids are neither declared globally'
            . ' unique nor read by a codec of the uuid format',
        ];
        yield 'an include depth below 1' => [
            static function (): ResourceType {
                return self::pets()->maxIncludeDepth(0);
            },
            'type "pets" declares the include depth 0, and an include depth is at least 1',
        ];
        yield 'an id parameter that is no group name' => [
            static function (): ResourceType {
                return self::pets()->idParameter('pet-id');
            },
            'the id parameter of type "pets" is named "pet-id", and a path parameter is named by a letter or "_"',
        ];
    }

    /**
     * @param Closure(ResourceType): ResourceType $select
     * @param list<OperationKind>                 $excluded
     */
    #[DataProvider('selections')]
    public function testOnlyAndExceptNameTheRoutesOfTheResourcesThatLeave(Closure $select, array $excluded): void
    {
        $type = ResourceType::make('pets')->id(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        });

        self::assertSame($excluded, $select($type)->build()->excluded->kinds);
    }

    /**
     * the kinds leave in the order of `OperationKind`, whatever order the call names them in
     *
     * @return iterable<string, array{Closure(ResourceType): ResourceType, list<OperationKind>}>
     */
    public static function selections(): iterable
    {
        yield 'except of one kind' => [
            static function (ResourceType $type): ResourceType {
                return $type->except(OperationKind::FetchResource);
            },
            [OperationKind::FetchResource],
        ];
        yield 'except of several kinds' => [
            static function (ResourceType $type): ResourceType {
                return $type->except(OperationKind::DeleteResource, OperationKind::FetchCollection)
                    ->permission(OperationKind::FetchResource, Implementation::service('permission'));
            },
            [OperationKind::FetchCollection, OperationKind::DeleteResource],
        ];
        yield 'only of two kinds' => [
            static function (ResourceType $type): ResourceType {
                return $type->only(OperationKind::FetchResource, OperationKind::FetchCollection)
                    ->permission(OperationKind::FetchResource, Implementation::service('permission'));
            },
            [OperationKind::CreateResource, OperationKind::UpdateResource, OperationKind::DeleteResource],
        ];
        yield 'only of every kind' => [
            static function (ResourceType $type): ResourceType {
                return $type->only(
                    OperationKind::FetchCollection,
                    OperationKind::CreateResource,
                    OperationKind::FetchResource,
                    OperationKind::UpdateResource,
                    OperationKind::DeleteResource,
                )->permission(OperationKind::FetchResource, Implementation::service('permission'));
            },
            [],
        ];
    }

    #[DataProvider('kindsOfARelationshipRoute')]
    public function testRefusesAKindOfARelationshipRouteWhereItIsWritten(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" names %s in except(), and it names only fetch_collection, fetch_resource, create_resource,'
            . ' update_resource, delete_resource there: a relationship excludes its own reads with its only() or'
            . ' except(), and enables its writes with replaceable(), addable() or removable()',
            $kind->value,
        ));

        ResourceType::make('pets')->except($kind);
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kindsOfARelationshipRoute(): iterable
    {
        foreach (OperationKind::cases() as $kind) {
            if ($kind->targetsRelationship()) {
                yield $kind->value => [$kind];
            }
        }
    }

    public function testQueryParametersAreCarriedPerKindInDeclarationOrder(): void
    {
        $type = self::pets()
            ->reader(Implementation::service('reader'))
            ->permission(OperationKind::FetchCollection, Implementation::service('permission'))
            ->queryParameter(OperationKind::FetchCollection, 'nearBy', new StringCodec())
            ->queryParameter(OperationKind::FetchResource, 'preview', new BooleanCodec())
            ->queryParameter(OperationKind::FetchCollection, 'minAge', new IntegerCodec(), required: true)
            ->build();

        self::assertSame(
            [['nearBy', false], ['minAge', true]],
            array_map(static function (QueryParameterMetadata $parameter): array {
                return [$parameter->name, $parameter->required];
            }, $type->queryParametersOf(OperationKind::FetchCollection)),
        );
        self::assertSame(['preview'], array_map(static function (QueryParameterMetadata $parameter): string {
            return $parameter->name;
        }, $type->queryParametersOf(OperationKind::FetchResource)));
        self::assertSame([], $type->queryParametersOf(OperationKind::DeleteResource));
    }

    public function testOneNameMayServeSeveralKinds(): void
    {
        $type = self::pets()
            ->updater(Implementation::service('updater'))
            ->permission(OperationKind::UpdateResource, Implementation::service('permission'))
            ->queryParameter(OperationKind::FetchResource, 'preview', new BooleanCodec())
            ->queryParameter(OperationKind::UpdateResource, 'preview', new BooleanCodec())
            ->build();

        self::assertCount(1, $type->queryParametersOf(OperationKind::FetchResource));
        self::assertCount(1, $type->queryParametersOf(OperationKind::UpdateResource));
    }

    public function testRefusesAQueryParameterNamedTwiceForOneKind(): void
    {
        $type = self::pets()->queryParameter(OperationKind::FetchResource, 'preview', new BooleanCodec());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "preview" for fetch_resource twice, and'
            . ' each part of a declaration is written once');

        $type->queryParameter(OperationKind::FetchResource, 'preview', new StringCodec());
    }

    #[DataProvider('kindsOfARelationshipRoute')]
    public function testRefusesAQueryParameterForAKindOfARelationshipRoute(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" declares a query parameter for %s, which addresses a relationship: a relationship declares'
            . ' the query parameters of its routes with its own queryParameter()',
            $kind->value,
        ));

        ResourceType::make('pets')->queryParameter($kind, 'nearBy', new StringCodec());
    }

    public function testRefusesAQueryParameterNamedAsAFamilyOfTheSpecification(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the query parameter "filter" is a family JSON:API defines itself');

        ResourceType::make('pets')->queryParameter(OperationKind::FetchCollection, 'filter', new StringCodec());
    }

    public function testRefusesAQueryParameterForAKindTheTypeDoesNotEnable(): void
    {
        $type = self::pets()->queryParameter(OperationKind::DeleteResource, 'hard', new BooleanCodec());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the query parameter "hard" for delete_resource, which it'
            . ' does not enable');

        $type->build();
    }

    public function testSecuritySchemesAreCarriedPerKindAndAKindWithoutThemTakesThoseOfTheApi(): void
    {
        $nonce = SecurityScheme::restNonce();
        $type = self::pets()
            ->reader(Implementation::service('reader'))
            ->permission(OperationKind::FetchCollection, Implementation::service('permission'))
            ->securitySchemes(OperationKind::FetchResource, $nonce)
            ->securitySchemes(OperationKind::FetchCollection)
            ->build();

        self::assertSame([$nonce], $type->securitySchemesOf(OperationKind::FetchResource));
        self::assertSame([], $type->securitySchemesOf(OperationKind::FetchCollection));
        self::assertNull($type->securitySchemesOf(OperationKind::DeleteResource));
    }

    public function testRefusesTheSecuritySchemesOfOneKindWrittenTwice(): void
    {
        $type = self::pets()->securitySchemes(OperationKind::FetchResource, SecurityScheme::restNonce());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares the security schemes of fetch_resource twice, and each part'
            . ' of a declaration is written once');

        $type->securitySchemes(OperationKind::FetchResource);
    }

    #[DataProvider('kindsOfARelationshipRoute')]
    public function testRefusesSecuritySchemesForAKindOfARelationshipRoute(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'type "pets" declares security schemes for %s, which addresses a relationship: a relationship declares'
            . ' the security schemes of its routes with its own securitySchemes()',
            $kind->value,
        ));

        ResourceType::make('pets')->securitySchemes($kind, SecurityScheme::restNonce());
    }

    public function testRefusesSecuritySchemesForAKindTheTypeDoesNotEnable(): void
    {
        $type = self::pets()->securitySchemes(OperationKind::DeleteResource, SecurityScheme::restNonce());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" declares security schemes for delete_resource, which it does not'
            . ' enable');

        $type->build();
    }

    public function testRefusesASecuritySchemeNamedTwiceForOneKind(): void
    {
        $nonce = SecurityScheme::restNonce();
        $type = self::pets()->securitySchemes(OperationKind::FetchResource, $nonce, $nonce);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" for fetch_resource declares the security scheme "restNonce" twice');

        $type->build();
    }

    public function testRefusesAKindNamedTwiceWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('type "pets" names fetch_resource twice in only(), and each kind is named once');

        ResourceType::make('pets')->only(OperationKind::FetchResource, OperationKind::FetchResource);
    }

    /**
     * the smallest type an API accepts, which also needs the loader `build()` does not ask for
     */
    private static function pets(): ResourceType
    {
        return ResourceType::make('pets')
            ->id(new DigitsCodec(), static function (Pet $pet): string {
                return $pet->id;
            })
            ->loader(Implementation::service('loader'))
            ->permission(OperationKind::FetchResource, Implementation::service('permission'));
    }
}
