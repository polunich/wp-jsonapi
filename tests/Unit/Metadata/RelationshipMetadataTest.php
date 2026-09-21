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
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionParameter;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(RelationshipMetadata::class)]
final class RelationshipMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RelationshipMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheMembersOfTheRelationshipAndItsReadersKeepTheirNamesAndTypes(): void
    {
        $class = new ReflectionClass(RelationshipMetadata::class);

        self::assertSame(
            [
                ['name', 'string'],
                ['cardinality', Cardinality::class],
                ['relatedType', 'string'],
                ['relatedId', '?Closure'],
                ['nullable', 'bool'],
                ['linkageAlwaysPresent', 'bool'],
                ['includable', 'bool'],
                ['updates', 'array'],
                ['excluded', RouteExclusion::class],
                ['writeRule', WriteRule::class],
                ['defaultSort', '?' . Sort::class],
                ['paginationPolicy', '?' . PaginationPolicy::class],
                ['description', '?string'],
                ['deprecated', 'bool'],
                ['queryParameters', 'array'],
                ['securitySchemes', 'array'],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertFalse($class->getProperty('relatedId')->isPublic());
        self::assertSame(
            [['record', 'object']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod('relatedIdOf')->getParameters(),
            ),
        );
        self::assertSame('?string', (string) $class->getMethod('relatedIdOf')->getReturnType());
    }

    public function testCarriesAToOneRelationship(): void
    {
        $rule = new WriteRule(true, true, false);
        $relationship = new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            static function (Pet $pet): ?string {
                return $pet->ownerId;
            },
            true,
            true,
            false,
            [RelationshipUpdate::Replace],
            RouteExclusion::none(RouteOwner::Relationship),
            $rule,
            null,
            null,
            'the owner of the pet',
            true,
        );

        self::assertSame('owner', $relationship->name);
        self::assertSame(Cardinality::ToOne, $relationship->cardinality);
        self::assertSame('customers', $relationship->relatedType);
        self::assertTrue($relationship->readsRelatedId());
        self::assertTrue($relationship->nullable);
        self::assertTrue($relationship->linkageAlwaysPresent);
        self::assertFalse($relationship->includable);
        self::assertSame([RelationshipUpdate::Replace], $relationship->updates);
        self::assertSame($rule, $relationship->writeRule);
        self::assertNull($relationship->defaultSort);
        self::assertNull($relationship->paginationPolicy);
        self::assertSame('the owner of the pet', $relationship->description);
        self::assertTrue($relationship->deprecated);
    }

    public function testCarriesAToManyRelationshipWithOrWithoutSortAndPolicy(): void
    {
        $sort = Sort::fromString('-placedAt');
        $policy = new PaginationPolicy(5, 20);
        $declared = self::toMany(defaultSort: $sort, paginationPolicy: $policy);
        $undeclared = self::toMany();

        self::assertSame(Cardinality::ToMany, $declared->cardinality);
        self::assertSame($sort, $declared->defaultSort);
        self::assertSame($policy, $declared->paginationPolicy);
        self::assertFalse($declared->readsRelatedId());
        self::assertFalse($declared->nullable);
        self::assertNull($undeclared->defaultSort);
        self::assertNull($undeclared->paginationPolicy);
    }

    public function testAToManyRelationshipAcceptsEveryUpdate(): void
    {
        $updates = [RelationshipUpdate::Add, RelationshipUpdate::Remove, RelationshipUpdate::Replace];

        self::assertSame($updates, self::toMany(updates: $updates)->updates);
    }

    public function testAcceptsExactlyTheUpdatesItDeclares(): void
    {
        $relationship = self::toMany(updates: [RelationshipUpdate::Add]);

        self::assertTrue($relationship->accepts(RelationshipUpdate::Add));
        self::assertFalse($relationship->accepts(RelationshipUpdate::Remove));
        self::assertFalse($relationship->accepts(RelationshipUpdate::Replace));
    }

    #[DataProvider('impossibleNames')]
    public function testRefusesANameOrRelatedTypeNoRelationshipCarries(
        string $name,
        string $relatedType,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::toOne(name: $name, relatedType: $relatedType);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function impossibleNames(): iterable
    {
        yield 'the empty name' => ['', 'customers', 'relationship "" is no member name'];
        yield 'a reserved character' => ['owner.name', 'customers', 'relationship "owner.name" is no member name'];
        yield 'a control character, escaped' => ["owner\r", 'customers', 'relationship "owner\r" is no member name'];
        yield 'an @-member' => ['@owner', 'customers', 'relationship "@owner" is an @-member'];
        yield 'type' => [
            'type',
            'customers',
            'relationship "type" shares the namespace of type and id, and no attribute or relationship is named'
            . ' type or id',
        ];
        yield 'id' => [
            'id',
            'customers',
            'relationship "id" shares the namespace of type and id, and no attribute or relationship is named type'
            . ' or id',
        ];
        yield 'the empty related type' => [
            'owner',
            '',
            'relationship "owner" points at type "", which is no member name',
        ];
        yield 'a related type with a slash' => [
            'owner',
            'customers/admins',
            'relationship "owner" points at type "customers/admins", which is no member name',
        ];
        yield 'a related type with a control character, escaped' => [
            'owner',
            "customers\0",
            'relationship "owner" points at type "customers\000", which is no member name',
        ];
    }

    public function testRefusesAToManyRelationshipWithARelatedIdAccessor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-many relationship "orders" has a related id accessor, which only a to-one'
            . ' relationship has');

        new RelationshipMetadata(
            'orders',
            Cardinality::ToMany,
            'orders',
            static function (Pet $pet): ?string {
                return $pet->ownerId;
            },
            false,
            true,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    public function testRefusesANullableToManyRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-many relationship "orders" is declared nullable');

        new RelationshipMetadata(
            'orders',
            Cardinality::ToMany,
            'orders',
            null,
            true,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    public function testRefusesARelatedIdAccessorWithoutLinkageAlwaysPresent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" reads its linkage from the record, so its linkage is'
            . ' always present');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            static function (Pet $pet): ?string {
                return $pet->ownerId;
            },
            true,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    #[DataProvider('linkage')]
    public function testAToOneRelationshipWithoutAccessorMayBeLinkedEitherWay(bool $linkageAlwaysPresent): void
    {
        $relationship = new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            $linkageAlwaysPresent,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );

        self::assertFalse($relationship->readsRelatedId());
        self::assertSame($linkageAlwaysPresent, $relationship->linkageAlwaysPresent);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function linkage(): iterable
    {
        yield 'always linked' => [true];
        yield 'linked when included' => [false];
    }

    /**
     * @param list<RelationshipUpdate> $updates
     */
    #[DataProvider('impossibleUpdates')]
    public function testRefusesAnUpdateTheRelationshipCannotAccept(
        Cardinality $cardinality,
        array $updates,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        if ($cardinality === Cardinality::ToOne) {
            self::toOne(updates: $updates);
        } else {
            self::toMany(updates: $updates);
        }
    }

    /**
     * @return iterable<string, array{Cardinality, list<RelationshipUpdate>, string}>
     */
    public static function impossibleUpdates(): iterable
    {
        yield 'add on a to-one' => [
            Cardinality::ToOne,
            [RelationshipUpdate::Add],
            'to-one relationship "owner" declares the update add',
        ];
        yield 'remove on a to-one' => [
            Cardinality::ToOne,
            [RelationshipUpdate::Replace, RelationshipUpdate::Remove],
            'to-one relationship "owner" declares the update remove',
        ];
        yield 'replace twice on a to-one' => [
            Cardinality::ToOne,
            [RelationshipUpdate::Replace, RelationshipUpdate::Replace],
            'relationship "owner" declares the update replace twice',
        ];
        yield 'add twice on a to-many' => [
            Cardinality::ToMany,
            [RelationshipUpdate::Add, RelationshipUpdate::Remove, RelationshipUpdate::Add],
            'relationship "orders" declares the update add twice',
        ];
    }

    public function testRefusesADefaultSortOnAToOneRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-one relationship "owner" declares a default sort');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            Sort::fromString('name'),
            null,
            null,
            false,
        );
    }

    public function testRefusesADefaultSortWithoutASortFieldOnAToManyRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-many relationship "orders" declares a default sort without a sort field,'
            . ' and a default sort that is not declared is null');

        self::toMany(defaultSort: new Sort([]));
    }

    public function testRefusesAPaginationPolicyOnAToOneRelationship(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-one relationship "owner" declares a pagination policy');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            PaginationPolicy::libraryDefault(),
            null,
            false,
        );
    }

    #[DataProvider('notNullableWithoutRequired')]
    public function testRefusesANotNullableToOneThatIsNotRequired(WriteRule $rule): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-one relationship "owner" is not nullable and not required on create');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            false,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            $rule,
            null,
            null,
            null,
            false,
        );
    }

    /**
     * @return iterable<string, array{WriteRule}>
     */
    public static function notNullableWithoutRequired(): iterable
    {
        yield 'read only' => [WriteRule::readOnly()];
        yield 'creatable and updatable' => [new WriteRule(true, true, false)];
    }

    public function testAcceptsANotNullableToOneThatIsRequired(): void
    {
        $relationship = new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            false,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            new WriteRule(true, false, true),
            null,
            null,
            null,
            false,
        );

        self::assertFalse($relationship->nullable);
    }

    #[DataProvider('relatedIds')]
    public function testReadsTheIdOfTheRelatedResource(?string $ownerId): void
    {
        self::assertSame($ownerId, self::toOne()->relatedIdOf(new Pet('1', 'Rex', $ownerId)));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function relatedIds(): iterable
    {
        yield 'an id' => ['9'];
        yield 'zero' => ['0'];
        yield 'the empty relationship' => [null];
    }

    #[DataProvider('impossibleRelatedIds')]
    public function testRefusesAnAccessorThatReturnsNoRelatedId(bool $nullable, mixed $result, string $message): void
    {
        $relationship = new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            static function (Pet $pet) use ($result): mixed {
                return $result;
            },
            $nullable,
            true,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            new WriteRule(true, true, true),
            null,
            null,
            null,
            false,
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $relationship->relatedIdOf(new Pet('1', 'Rex'));
    }

    /**
     * @return iterable<string, array{bool, mixed, string}>
     */
    public static function impossibleRelatedIds(): iterable
    {
        yield 'an int' => [
            true,
            9,
            'the related id accessor of relationship "owner" returned int for a record of class ' . Pet::class,
        ];
        yield 'an array' => [
            true,
            ['9'],
            'the related id accessor of relationship "owner" returned array for a record of class ' . Pet::class,
        ];
        yield 'the empty string' => [
            true,
            '',
            'the related id accessor of relationship "owner" returned the empty string',
        ];
        yield 'null on a relationship that is not nullable' => [
            false,
            null,
            'the related id accessor of relationship "owner" returned null for a record of class '
                . Pet::class
                . ', and the relationship is not nullable',
        ];
    }

    public function testReadsNoRelatedIdWithoutAnAccessor(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('relationship "orders" has no related id accessor');

        self::toMany()->relatedIdOf(new Pet('1', 'Rex'));
    }

    public function testEnablesTheReadsItKeepsAndTheWritesItDeclares(): void
    {
        $relationship = new RelationshipMetadata(
            'orders',
            Cardinality::ToMany,
            'orders',
            null,
            false,
            false,
            false,
            [RelationshipUpdate::Add],
            RouteExclusion::except(RouteOwner::Relationship, [OperationKind::FetchRelated], 'relationship "orders"'),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );

        self::assertFalse($relationship->enables(OperationKind::FetchRelated));
        self::assertTrue($relationship->enables(OperationKind::FetchRelationship));
        self::assertTrue($relationship->enables(OperationKind::AddToRelationship));
        self::assertFalse($relationship->enables(OperationKind::ReplaceRelationship));
        self::assertFalse($relationship->enables(OperationKind::RemoveFromRelationship));
    }

    #[DataProvider('kindsOfTheResources')]
    public function testCoversNoKindOfTheRoutesOfTheResources(OperationKind $kind): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'a %s operation addresses no relationship, and a relationship covers the kinds that do',
            $kind->value,
        ));

        self::toMany()->enables($kind);
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kindsOfTheResources(): iterable
    {
        foreach (RouteOwner::Resource->kinds() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    public function testRefusesTheExclusionOfAType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" takes the exclusion of a type, and a relationship'
            . ' excludes its own reads only');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Resource),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    /**
     * the builders set `linkageAlwaysPresent` once both reads are excluded, as `relatedId()` does,
     * so only metadata built by hand reaches this refusal
     */
    public function testRefusesBothReadsExcludedWithoutAnAlwaysPresentLinkage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" excludes its related and its relationship route, so no'
            . ' link leads to its linkage and every document carries it: its linkage is'
            . ' always present');

        new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            false,
            true,
            [],
            self::bothReads(),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    public function testAcceptsBothReadsExcludedWithAnAlwaysPresentLinkage(): void
    {
        $relationship = new RelationshipMetadata(
            'owner',
            Cardinality::ToOne,
            'customers',
            null,
            true,
            true,
            true,
            [],
            self::bothReads(),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );

        self::assertFalse($relationship->enables(OperationKind::FetchRelated));
        self::assertFalse($relationship->enables(OperationKind::FetchRelationship));
        self::assertTrue($relationship->linkageAlwaysPresent);
    }

    private static function bothReads(): RouteExclusion
    {
        return RouteExclusion::except(
            RouteOwner::Relationship,
            [OperationKind::FetchRelated, OperationKind::FetchRelationship],
            'relationship "owner"',
        );
    }

    /**
     * @param list<RelationshipUpdate> $updates
     */
    private static function toOne(
        string $name = 'owner',
        string $relatedType = 'customers',
        array $updates = [],
    ): RelationshipMetadata {
        return new RelationshipMetadata(
            $name,
            Cardinality::ToOne,
            $relatedType,
            static function (Pet $pet): ?string {
                return $pet->ownerId;
            },
            true,
            true,
            true,
            $updates,
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            null,
            false,
        );
    }

    /**
     * @param list<RelationshipUpdate> $updates
     */
    private static function toMany(
        array $updates = [],
        ?Sort $defaultSort = null,
        ?PaginationPolicy $paginationPolicy = null,
    ): RelationshipMetadata {
        return new RelationshipMetadata(
            'orders',
            Cardinality::ToMany,
            'orders',
            null,
            false,
            false,
            true,
            $updates,
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            $defaultSort,
            $paginationPolicy,
            null,
            false,
        );
    }

    public function testQueryParametersOfReturnsTheParametersOfOneKindInDeclarationOrder(): void
    {
        $since = new QueryParameterMetadata('since', new StringCodec(), false);
        $tags = Samples::toMany('tags', 'tags', queryParameters: [OperationKind::FetchRelated->value => [$since]]);

        self::assertSame([$since], $tags->queryParametersOf(OperationKind::FetchRelated));
        self::assertSame([], $tags->queryParametersOf(OperationKind::FetchRelationship));
    }

    public function testRefusesAnEmptyDescription(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "tags" declares an empty description, and a declared text is'
            . ' never empty');

        new RelationshipMetadata(
            'tags',
            Cardinality::ToMany,
            'tags',
            null,
            false,
            false,
            true,
            [],
            RouteExclusion::none(RouteOwner::Relationship),
            WriteRule::readOnly(),
            null,
            null,
            '',
            false,
        );
    }

    public function testRefusesAQueryParameterForAKindOfTheResourceRoutes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "tags" declares a query parameter for fetch_collection, which is no'
            . ' route of its own: the routes of the resources take the query parameters their type declares with its'
            . ' own queryParameter()');

        Samples::toMany('tags', 'tags', queryParameters: [
            OperationKind::FetchCollection->value => [new QueryParameterMetadata('since', new StringCodec(), false)],
        ]);
    }

    public function testRefusesAQueryParameterForAWriteTheRelationshipDoesNotDeclare(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship "owner" declares the query parameter "notify" for'
            . ' replace_relationship, which it does not enable');

        Samples::toOne('owner', 'customers', queryParameters: [
            OperationKind::ReplaceRelationship->value => [new QueryParameterMetadata(
                'notify',
                new StringCodec(),
                false,
            )],
        ]);
    }
}
