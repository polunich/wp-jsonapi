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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AtomicRoute::class)]
final class AtomicRouteTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(AtomicRoute::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheRouteLiesAtTheDeclaredPathAndRespondsToPostAlone(): void
    {
        $metadata = Samples::atomicRoute(['pets'], 'petOperations', '/pets/operations');
        $route = new AtomicRoute($metadata);

        self::assertSame($metadata, $route->metadata);
        self::assertSame(RouteKind::Atomic, $route->routeKind);
        self::assertSame($metadata->template, $route->template);
        self::assertSame('/pets/operations', $route->template->path);
        self::assertSame(['POST'], $route->methods);
    }

    public function testTheTargetCarriesTheRouteKindAloneWhateverThePathHolds(): void
    {
        $target = (new AtomicRoute(Samples::atomicRoute()))->target(
            new ServerRequest('POST', new Headers(), [], ['id' => '7'], ''),
        );

        self::assertSame(RouteKind::Atomic, $target->kind);
        self::assertNull($target->type);
        self::assertNull($target->id);
        self::assertNull($target->relationship);
    }

    public function testAMessageNamesTheRouteByItsName(): void
    {
        self::assertSame(
            'atomic route "petOperations"',
            (new AtomicRoute(Samples::atomicRoute(name: 'petOperations')))->subject(),
        );
    }
}
