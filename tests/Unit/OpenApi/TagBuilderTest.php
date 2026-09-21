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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\OpenApi\TagBuilder;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\OpenApi\SchemaMembers;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(TagBuilder::class)]
final class TagBuilderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(TagBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAnAtomicRouteOfEveryTypeAddsTheTagOfTheExtension(): void
    {
        self::assertSame(['pets', 'customers', 'orders'], self::tagNames(new TagBuilder(self::petstore())));
        self::assertSame(
            ['pets', 'customers', 'orders', 'atomic:operations'],
            self::tagNames(new TagBuilder(self::atomicPetstore())),
        );
    }

    public function testAnEndpointAddsItsTagAfterTheTypes(): void
    {
        $api = self::endpointPetstore([Samples::endpoint(), self::statsEndpoint()]);

        self::assertSame(['pets', 'customers', 'orders', 'jobs', 'stats'], self::tagNames(new TagBuilder($api)));
    }

    public function testAnEndpointThatNamesATypeSharesItsTag(): void
    {
        $api = self::endpointPetstore([self::statsEndpoint('pets'), self::statsEndpoint('stats', 'sales')]);

        self::assertSame(['pets', 'customers', 'orders', 'jobs', 'stats'], self::tagNames(new TagBuilder($api)));
    }

    public function testAnEndpointThatNamesTheAtomicTagSharesItsDescribedTag(): void
    {
        $api = self::api(
            self::resources(),
            ImplementationReference::service('transactions'),
            [self::statsEndpoint(Member::ATOMIC_OPERATIONS)],
        );

        $tags = (new TagBuilder($api))->tags();

        self::assertSame(['pets', 'customers', 'orders', 'atomic:operations'], self::tagNames(new TagBuilder($api)));
        self::assertStringContainsString('[ext-atomic]', SchemaMembers::text($tags[3], 'description'));
    }

    /**
     * a route of one type shares the tag of its type,
     * and a declared tag is listed as an endpoint's is
     */
    public function testAnAtomicRouteAddsItsOwnTagAlone(): void
    {
        $api = self::api(self::resources(), atomicRoutes: [
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
            Samples::atomicRoute(['orders'], 'orderOperations', '/orders/operations', 'bulk'),
        ]);

        self::assertSame(['pets', 'customers', 'orders', 'bulk'], self::tagNames(new TagBuilder($api)));
    }

    public function testTheTagOfAnAtomicRouteIsItsOwnElseItsOneTypeElseTheTagOfTheExtension(): void
    {
        self::assertSame('bulk', TagBuilder::atomicTag(
            Samples::atomicRoute(['pets', 'orders'], 'bulkOperations', '/operations', 'bulk'),
        ));
        self::assertSame('pets', TagBuilder::atomicTag(
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations'),
        ));
        self::assertSame(Member::ATOMIC_OPERATIONS, TagBuilder::atomicTag(
            Samples::atomicRoute(['pets', 'orders'], 'petOrderOperations', '/operations'),
        ));
    }

    public function testRoutesThatShareTheTagOfTheExtensionListItOnceWithItsDescription(): void
    {
        $api = self::api(self::resources(), atomicRoutes: [
            Samples::atomicRoute(['pets', 'orders'], 'petOrderOperations', '/operations'),
            Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations', Member::ATOMIC_OPERATIONS),
        ]);

        $tags = (new TagBuilder($api))->tags();

        self::assertSame(['pets', 'customers', 'orders', 'atomic:operations'], self::tagNames(new TagBuilder($api)));
        self::assertStringContainsString('[ext-atomic]', SchemaMembers::text($tags[3], 'description'));
    }

    private static function petstore(): ApiMetadata
    {
        return self::api(self::resources());
    }

    private static function atomicPetstore(): ApiMetadata
    {
        return self::api(self::resources(), ImplementationReference::service('transactions'));
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

    private static function statsEndpoint(string $tag = 'stats', string $name = 'stats'): EndpointMetadata
    {
        return Samples::endpoint(
            $name,
            'GET',
            '/' . $name,
            [],
            responses: [200 => new ResponseMetadata(
                ResponseShapeKind::Meta,
                null,
                null,
                null,
                null,
                new ObjectCodec(['pets' => new IntegerCodec()], ['pets']),
            )],
            tag: $tag,
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
     * @return list<string>
     */
    private static function tagNames(TagBuilder $builder): array
    {
        $names = [];

        foreach ($builder->tags() as $tag) {
            $names[] = SchemaMembers::text($tag, 'name');
        }

        return $names;
    }
}
