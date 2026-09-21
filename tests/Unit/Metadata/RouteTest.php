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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Route::class)]
final class RouteTest extends TestCase
{
    public function testTheClassIsAnAbstractReadonlyInternalBase(): void
    {
        $class = new ReflectionClass(Route::class);

        self::assertTrue($class->isAbstract());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * an adapter reads these properties of every route, so their names are part of the surface
     */
    public function testThePropertiesAnAdapterReadsKeepTheirNames(): void
    {
        self::assertSame(
            ['template', 'methods'],
            array_map(
                static function (ReflectionProperty $property): string {
                    return $property->getName();
                },
                (new ReflectionClass(Route::class))->getProperties(),
            ),
        );
    }

    public function testAMethodTheRouteDoesNotRespondToIsRefusedByRespondsTo(): void
    {
        $reading = self::reading();

        self::assertTrue($reading->respondsTo('GET'));
        self::assertFalse($reading->respondsTo('PATCH'));
        self::assertFalse($reading->respondsTo('DELETE'));
        self::assertFalse($reading->respondsTo('OPTIONS'));
        self::assertFalse($reading->respondsTo(''));
    }

    public function testTheMethodOfRespondsToIsComparedCaseSensitively(): void
    {
        $reading = self::reading();

        self::assertTrue($reading->respondsTo('HEAD'));
        self::assertFalse($reading->respondsTo('head'));
        self::assertFalse($reading->respondsTo('Get'));
    }

    #[DataProvider('pairs')]
    public function testTheSharedMethodIsTheFirstOfTheRouteTheOtherAlsoRespondsTo(
        Route $route,
        Route $other,
        ?string $shared,
    ): void {
        self::assertSame($shared, $route->sharedMethod($other));
    }

    /**
     * @return iterable<string, array{Route, Route, ?string}>
     */
    public static function pairs(): iterable
    {
        $reading = self::reading();
        $update = new OperationRoute(
            OperationKind::UpdateResource,
            'pets',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['pets'],
        );

        yield 'a reading and an endpoint of GET' => [
            $reading,
            new EndpointRoute(Samples::endpoint('petStats', 'GET', '/pets/{id}/stats')),
            'GET',
        ];
        yield 'a reading and an update' => [$reading, $update, null];
        yield 'an atomic route and an endpoint of POST' => [
            new AtomicRoute(Samples::atomicRoute()),
            new EndpointRoute(Samples::endpoint()),
            'POST',
        ];
    }

    private static function reading(): OperationRoute
    {
        return new OperationRoute(
            OperationKind::FetchResource,
            'pets',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['pets'],
        );
    }
}
