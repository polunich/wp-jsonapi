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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteUrls::class)]
final class RouteUrlsTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteUrls::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param ?non-empty-list<string> $path
     */
    #[DataProvider('targets')]
    public function testTheUrlOfATargetIsTheTemplateOfItsReadFilledWithTheId(
        ?array $path,
        RouteTarget $target,
        string $url,
    ): void {
        self::assertSame($url, self::urls(self::pets($path))->fetchUrl($target, []));
    }

    /**
     * @return iterable<string, array{?non-empty-list<string>, RouteTarget, string}>
     */
    public static function targets(): iterable
    {
        $collection = new RouteTarget(RouteKind::Collection, 'pets', null, null);
        $resource = new RouteTarget(RouteKind::Resource, 'pets', '7', null);
        $related = new RouteTarget(RouteKind::Related, 'pets', '7', 'orders');
        $relationship = new RouteTarget(RouteKind::Relationship, 'pets', '7', 'orders');

        yield 'the collection' => [null, $collection, '/pets'];
        yield 'a resource' => [null, $resource, '/pets/7'];
        yield 'a related route' => [null, $related, '/pets/7/orders'];
        yield 'a relationship route' => [null, $relationship, '/pets/7/relationships/orders'];
        yield 'the collection at the path of the type' => [['shop', 'pets'], $collection, '/shop/pets'];
        yield 'a resource at the path of the type' => [['shop', 'pets'], $resource, '/shop/pets/7'];
        yield 'a relationship route at the path of the type' => [
            ['shop', 'pets'],
            $relationship,
            '/shop/pets/7/relationships/orders',
        ];
    }

    public function testTheQueryIsAppendedAsGiven(): void
    {
        self::assertSame(
            '/pets/7?include=orders',
            self::urls(self::pets(null))->fetchUrl(
                new RouteTarget(RouteKind::Resource, 'pets', '7', null),
                ['include' => 'orders'],
            ),
        );
    }

    public function testTheIdFillsTheParameterUnderTheNameTheTypeGivesIt(): void
    {
        $pets = Samples::resource(
            'pets',
            idParameterName: 'pet_id',
            path: ['shop', 'pets'],
        );

        self::assertSame(
            '/shop/pets/a/b',
            self::urls($pets)->fetchUrl(new RouteTarget(RouteKind::Resource, 'pets', 'a/b', null), []),
        );
    }

    public function testTheUrlOfAReadAnEndpointTakesThePlaceOfIsTheUrlOfThatEndpoint(): void
    {
        $endpoint = Samples::endpoint(
            'shopPet',
            'GET',
            '/shop/pets/{id}',
            ['id' => 'pets'],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
        );
        $registry = new ResourceRegistry([
            Samples::resource(
                'pets',
                capabilities: [Capability::ResourceLoader],
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
                path: ['shop', 'pets'],
            ),
        ]);
        $urls = new RouteUrls(RouteTable::of($registry, [$endpoint], []), PlainUrls::httpQuery());

        self::assertSame('/shop/pets/7', $urls->fetchUrl(new RouteTarget(RouteKind::Resource, 'pets', '7', null), []));
    }

    public function testATargetNoRouteRespondsToAGetOfIsRefused(): void
    {
        $registry = new ResourceRegistry([
            Samples::resource(
                'pets',
                capabilities: [Capability::ResourceLoader],
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
            ),
        ]);
        $urls = new RouteUrls(RouteTable::of($registry, [], []), PlainUrls::httpQuery());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'no route responds to a GET of the resource route of type "pets", so no link leads there',
        );

        $urls->fetchUrl(new RouteTarget(RouteKind::Resource, 'pets', '7', null), []);
    }

    public function testTheUrlOfAnEndpointIsItsTemplateFilledWithThePathOfTheRequest(): void
    {
        $endpoint = Samples::endpoint('adoptPet', 'POST', '/pets/{id}/adopt', ['id' => 'pets']);

        self::assertSame(
            '/pets/7/adopt?include=owner',
            self::urls(self::pets(null))->endpointUrl($endpoint, ['id' => '7'], ['include' => 'owner']),
        );
    }

    public function testAnEndpointUrlWithoutTheValueOfAParameterIsRefused(): void
    {
        $endpoint = Samples::endpoint('adoptPet', 'POST', '/pets/{id}/adopt', ['id' => 'pets']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the path "/pets/{id}/adopt" names the parameter "id", and no value of it is given',
        );

        self::urls(self::pets(null))->endpointUrl($endpoint, [], []);
    }

    /**
     * @param ?non-empty-list<string> $path
     */
    private static function pets(?array $path): ResourceMetadata
    {
        return Samples::resource(
            'pets',
            relationships: [Samples::toMany('orders', 'orders')],
            capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            path: $path,
        );
    }

    private static function urls(ResourceMetadata $pets): RouteUrls
    {
        $registry = new ResourceRegistry([
            $pets,
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);

        return new RouteUrls(RouteTable::of($registry, [], []), PlainUrls::httpQuery());
    }
}
