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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(EndpointRoute::class)]
final class EndpointRouteTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(EndpointRoute::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheRouteTakesItsTemplateAndMethodsFromTheEndpoint(): void
    {
        $endpoint = Samples::endpoint('petStats', 'GET', '/pets/{id}/stats');

        $route = new EndpointRoute($endpoint);

        self::assertSame($endpoint, $route->endpoint);
        self::assertNull($route->replaces);
        self::assertSame($endpoint->template, $route->template);
        self::assertSame(['GET', 'HEAD'], $route->methods);
    }

    public function testAnEndpointNamesTheReadItTakesThePlaceOf(): void
    {
        $read = new OperationRoute(
            OperationKind::FetchResource,
            'pets',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['pets'],
        );

        self::assertSame(
            $read,
            (new EndpointRoute(Samples::endpoint('replacement', 'GET', '/pets/{id}'), $read))->replaces,
        );
    }

    public function testAnEndpointTakesThePlaceOfAReadAlone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the endpoint "adoptPet" takes the place of a read route alone, and got the update_resource route of'
            . ' type "pets"',
        );

        new EndpointRoute(
            Samples::endpoint(),
            new OperationRoute(
                OperationKind::UpdateResource,
                'pets',
                null,
                new PathParameter('id', new DigitsCodec()),
                ['pets'],
            ),
        );
    }

    public function testAMessageNamesTheRouteByItsEndpoint(): void
    {
        self::assertSame('route of the endpoint "adoptPet"', (new EndpointRoute(Samples::endpoint()))->subject());
    }
}
