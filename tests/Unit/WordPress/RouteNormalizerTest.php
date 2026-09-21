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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\RouteNormalizer;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use ReflectionClass;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @internal
 */
#[CoversClass(RouteNormalizer::class)]
final class RouteNormalizerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteNormalizer::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('routes')]
    public function testTheRouteOfARequestIsWrittenInTheNormalFormWhereThatFormLiesOnARouteOfTheLibrary(
        string $sent,
        string $matched,
    ): void {
        $request = new WP_REST_Request('GET', $sent);

        self::normalizer()->filterPreDispatch(null, new WP_REST_Server(), $request);

        self::assertSame($matched, $request->get_route());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function routes(): iterable
    {
        yield 'an id percent-encoded' => ['/acme/v1/pets/%31', '/acme/v1/pets/1'];
        yield 'a literal percent-encoded' => ['/acme/v1/p%65ts', '/acme/v1/pets'];
        yield 'already normal' => ['/acme/v1/pets/1', '/acme/v1/pets/1'];
        yield 'an id the codec refuses, kept as sent' => ['/acme/v1/pets/%61', '/acme/v1/pets/%61'];
        yield 'a path of no route of the library, kept as sent' => ['/acme/v1/users/a@b', '/acme/v1/users/a@b'];
        yield 'another namespace, kept as sent' => ['/wp/v2/posts/%31', '/wp/v2/posts/%31'];
    }

    public function testTheResultOfTheFilterIsPassedOn(): void
    {
        $response = new WP_REST_Response(null, 204);

        self::assertNull(self::normalizer()->filterPreDispatch(
            null,
            new WP_REST_Server(),
            new WP_REST_Request('GET', '/acme/v1/pets'),
        ));
        self::assertSame(
            $response,
            self::normalizer()->filterPreDispatch(
                $response,
                new WP_REST_Server(),
                new WP_REST_Request('GET', '/acme/v1/pets'),
            ),
        );
    }

    private static function normalizer(): RouteNormalizer
    {
        $server = PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger());

        return new RouteNormalizer(new RoutePatterns(PetsApi::REST_NAMESPACE, $server->routes()));
    }
}
