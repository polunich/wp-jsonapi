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

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use Polunich\WpJsonApi\WordPress\RegisteredCallbacks;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use Polunich\WpJsonApi\WordPress\RestController;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use Polunich\WpJsonApi\WordPress\RouteRegistrar;
use ReflectionClass;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @internal
 */
#[CoversClass(RouteRegistrar::class)]
final class RouteRegistrarTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RouteRegistrar::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testOnePatternIsRegisteredPerPathWithAHandlerPerRoute(): void
    {
        $calls = self::register(PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger()));

        self::assertSame([
            ['acme/v1', '/pets', [['GET', 'HEAD'], ['POST']]],
            ['acme/v1', '/pets/(?P<id>(?=(?:0|[1-9][0-9]*)(?:/|\z))[^/]+)', [['GET', 'HEAD'], ['PATCH'], ['DELETE']]],
        ], array_map(
            static function (array $call): array {
                return [$call[0], $call[1], array_map(static function (array $handler): mixed {
                    return $handler['methods'];
                }, $call[2])];
            },
            $calls,
        ));
    }

    public function testAHandlerNamesItsMethodsAndItsTwoCallbacksAlone(): void
    {
        $calls = self::register(PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger()));

        foreach ($calls as [, , $handlers]) {
            foreach ($handlers as $handler) {
                self::assertSame(['methods', 'callback', 'permission_callback'], array_keys($handler));
            }
        }
    }

    /**
     * the pets declaration enables no update,
     * so the permission of `PATCH` refuses the very request the one of `DELETE` lets through
     */
    public function testTheCallbacksOfAHandlerActForItsOwnRoute(): void
    {
        $store = PetsApi::store();
        $calls = self::register(PetsApi::server($store, PetsApi::permission(true), new RecordingLogger()));
        [, , $handlers] = $calls[1];
        [, $update, $delete] = $handlers;
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/1');
        $request->set_url_params(['id' => '1']);

        self::assertInstanceOf(Closure::class, $update['permission_callback']);
        self::assertInstanceOf(Closure::class, $delete['permission_callback']);
        self::assertInstanceOf(Closure::class, $delete['callback']);

        $refused = ($update['permission_callback'])($request);
        $allowed = ($delete['permission_callback'])($request);
        $response = ($delete['callback'])($request);

        self::assertInstanceOf(WP_Error::class, $refused);
        self::assertSame('operation_not_supported', $refused->get_error_code());
        self::assertTrue($allowed);
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(204, $response->get_status());
        self::assertNull($store->find('1'));
    }

    /**
     * a permission callback is never the callback WordPress sets as matched, so it is not recorded
     */
    public function testEveryRouteCallbackIsRecordedWithItsRoute(): void
    {
        $server = PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger());
        $callbacks = new RegisteredCallbacks();
        $calls = self::register($server, $callbacks);
        $routes = [];

        foreach ($calls as [, , $registered]) {
            foreach ($registered as $handler) {
                $routes[] = $callbacks->routeOf($handler['callback'] ?? null);
                self::assertNull($callbacks->routeOf($handler['permission_callback'] ?? null));
            }
        }

        self::assertSame($server->routes(), $routes);
    }

    /**
     * an endpoint that takes the place of a read is registered in that place,
     * beside the other methods of its path; one of its own follows the generated routes.
     * a GET endpoint responds to HEAD as well.
     * every route of the layout is registered, the ones the type does not enable included
     */
    public function testAnEndpointIsRegisteredUnderItsPatternWithTheMethodsItRespondsTo(): void
    {
        $store = PetsApi::store();
        $anyone = Implementation::instance(new AllowAny());
        $registry = new ResourceRegistry([
            ResourceType::make('pets')
                ->id(new DigitsCodec(), static function (Pet $pet): string {
                    return $pet->id;
                })
                ->reader(Implementation::instance($store))
                ->loader(Implementation::instance($store))
                ->permission(OperationKind::FetchCollection, $anyone)
                ->except(OperationKind::FetchResource)
                ->build(),
        ]);
        $endpoints = [
            (new Endpoint('GET', '/pets/{id}', Implementation::instance($store)))->name('showPet')->bind('id', 'pets')
                ->responds(ResponseShape::record('id'))->permission($anyone)->build($registry),
            (new Endpoint(
                'POST',
                '/pets/{id}/adopt',
                Implementation::instance($store),
            ))->name('adoptPet')->bind('id', 'pets')
                ->responds(ResponseShape::noContent())->permission($anyone)->build($registry),
            (new Endpoint('GET', '/stats', Implementation::instance($store)))->name('stats')
                ->responds(ResponseShape::meta(new IntegerCodec()))->permission($anyone)->build($registry),
        ];
        $api = new ApiMetadata(
            PetsApi::NAME,
            PetsApi::VERSION,
            PetsApi::REST_NAMESPACE,
            $registry,
            $endpoints,
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
        $callbacks = new RegisteredCallbacks();
        $calls = self::register(
            Server::compose(
                $api,
                new ContainerImplementationResolver(),
                new RestUrlGenerator(PetsApi::REST_NAMESPACE, static function (string $path): string {
                    return PetsApi::REST_ROOT . $path;
                }),
                PetsApi::identity(true),
                PetsApi::challengeProvider([]),
                null,
                null,
                [],
                false,
                new RecordingLogger(),
            ),
            $callbacks,
        );
        $recorded = [];

        foreach ($calls as [, $pattern, $registered]) {
            foreach ($registered as $handler) {
                $methods = $handler['methods'] ?? null;
                self::assertIsArray($methods);
                $names = [];

                foreach ($methods as $method) {
                    self::assertIsString($method);
                    $names[] = $method;
                }

                $route = $callbacks->routeOf($handler['callback'] ?? null);
                $recorded[$pattern][implode(',', $names)] = $route instanceof EndpointRoute
                    ? $route->endpoint->name
                    : null;
            }
        }

        $id = '(?P<id>(?=(?:0|[1-9][0-9]*)(?:/|\\z))[^/]+)';

        self::assertSame(
            [
                '/pets' => ['GET,HEAD' => null, 'POST' => null],
                '/pets/' . $id => ['GET,HEAD' => 'showPet', 'PATCH' => null, 'DELETE' => null],
                '/pets/' . $id . '/adopt' => ['POST' => 'adoptPet'],
                '/stats' => ['GET,HEAD' => 'stats'],
            ],
            $recorded,
        );
    }

    /**
     * @param non-falsy-string $namespace
     *
     * @return list<array{string, string, list<array<array-key, mixed>>}>
     */
    private static function register(
        Server $server,
        ?RegisteredCallbacks $callbacks = null,
        string $namespace = PetsApi::REST_NAMESPACE,
    ): array {
        $calls = [];
        $registrar = new RouteRegistrar(
            new RoutePatterns($namespace, $server->routes()),
            new RestController(
                $server,
                new RequestFactory(),
                new ResponseFactory(),
                new RecordedResponses(),
                new RecordingLogger(),
            ),
            $callbacks ?? new RegisteredCallbacks(),
            static function (string $namespace, string $route, array $args) use (&$calls): bool {
                $handlers = [];

                foreach ($args as $handler) {
                    self::assertIsArray($handler);
                    $handlers[] = $handler;
                }

                $calls[] = [$namespace, $route, $handlers];

                return true;
            },
        );

        $registrar->register();

        return $calls;
    }
}
