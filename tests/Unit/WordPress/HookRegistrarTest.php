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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\RecordingHooks;
use Polunich\WpJsonApi\WordPress\HookRegistrar;
use ReflectionClass;
use WP_Error;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @internal
 */
#[CoversClass(HookRegistrar::class)]
final class HookRegistrarTest extends TestCase
{
    /** the pattern of the resource route of the pets, whose ids are digits */
    private const RESOURCE_PATTERN = '/pets/(?P<id>(?=(?:0|[1-9][0-9]*)(?:/|\\z))[^/]+)';

    private RecordingHooks $recording;

    #[Override]
    protected function setUp(): void
    {
        CoreFiles::resetHooks();
        $this->recording = new RecordingHooks();
    }

    #[Override]
    protected function tearDown(): void
    {
        CoreFiles::resetHooks();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(HookRegistrar::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheFiltersRegisterNoRouteAndTheRoutesAddNoFilter(): void
    {
        $hooks = $this->recording->hookRegistrar();
        $hooks->addFilters();

        self::assertSame([], $this->recording->registered);

        CoreFiles::resetHooks();
        $hooks->registerRoutes();

        self::assertSame(['/pets', self::RESOURCE_PATTERN], $this->recording->registered);
        self::assertSame([], $GLOBALS['wp_filter'] ?? null);
    }

    public function testTheFiltersRunFirstOfAllTheirCallbacksWithEveryArgument(): void
    {
        $this->recording->hookRegistrar()->addFilters();

        self::assertSame(PHP_INT_MIN, HookRegistrar::FIRST);
        self::assertSame([3], self::acceptedArguments('rest_pre_dispatch', PHP_INT_MIN));
        self::assertSame([3], self::acceptedArguments('rest_request_before_callbacks', PHP_INT_MIN));
        self::assertSame([3], self::acceptedArguments('rest_request_after_callbacks', PHP_INT_MIN));
        self::assertSame([3], self::acceptedArguments('rest_post_dispatch', PHP_INT_MIN));
        self::assertSame([4], self::acceptedArguments('rest_pre_serve_request', PHP_INT_MIN));
        self::assertSame(PHP_INT_MAX, HookRegistrar::LAST);
        self::assertSame([4], self::acceptedArguments('rest_pre_serve_request', PHP_INT_MAX));
    }

    public function testRestPreDispatchWritesTheRouteOfALibraryRouteInItsNormalForm(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets/%31');
        $this->recording->hookRegistrar()->addFilters();

        $result = apply_filters('rest_pre_dispatch', null, new WP_REST_Server(), $request);

        self::assertNull($result);
        self::assertSame('/acme/v1/pets/1', $request->get_route());
    }

    public function testRestPostDispatchServesTheResponseOfTheLibrary(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $restResponse = new WP_REST_Response(['meta' => []], 418);
        $this->recording->recorded->record($request, $restResponse);
        $this->recording->hookRegistrar()->addFilters();

        $served = apply_filters('rest_post_dispatch', $restResponse, new WP_REST_Server(), $request);

        self::assertSame($restResponse, $served);
        self::assertSame(418, $restResponse->get_status());
    }

    /**
     * replays the dispatch steps of WordPress,
     * the matched route it sets after `rest_request_after_callbacks` included
     */
    public function testACallbackOfTheSiteChangesTheRefusalTheClientIsServed(): void
    {
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/1');
        $request->set_url_params(['id' => '1']);
        $hooks = $this->recording->hookRegistrar(PetsApi::permission(false));
        $hooks->addFilters();
        $hooks->registerRoutes();
        $read = null;
        add_filter('rest_request_after_callbacks', static function (mixed $response) use (&$read): mixed {
            $read = $response;

            if ($response instanceof WP_REST_Response) {
                $response->header('X-Site', 'seen');
            }

            return $response;
        });
        $route = $this->handler(self::RESOURCE_PATTERN, 'DELETE');

        $refusal = $route['permission_callback']($request);
        $response = apply_filters('rest_request_after_callbacks', $refusal, $route, $request);

        self::assertInstanceOf(WP_REST_Response::class, $response);

        $response->set_matched_route('/acme/v1' . self::RESOURCE_PATTERN);
        $served = apply_filters('rest_post_dispatch', $response, new WP_REST_Server(), $request);

        self::assertInstanceOf(WP_Error::class, $refusal);
        self::assertSame($response, $read);
        self::assertSame($response, $served);
        self::assertSame(403, $response->get_status());
        self::assertSame('seen', $response->get_headers()['X-Site'] ?? null);
        self::assertSame('forbidden', self::firstErrorCode($response));
        self::assertSame('', $response->get_matched_route());
    }

    /**
     * replays `dispatch()`: WordPress responds to the broken body before any callback,
     * and the library takes the request back,
     * so a client the permission refuses learns that first
     */
    public function testABodyWordPressReadAsBrokenJsonReachesThePermissionOfTheLibrary(): void
    {
        $request = new WP_REST_Request('POST', '/acme/v1/pets');
        $request->set_header('Content-Type', 'application/vnd.api+json');
        $request->set_body('{');
        $hooks = $this->recording->hookRegistrar(PetsApi::permission(false));
        $hooks->addFilters();
        $hooks->registerRoutes();
        $route = $this->handler('/pets', 'POST');
        // what `has_valid_params()` returns,
        // which needs the translation files these tests do not load
        $invalid = new WP_Error('rest_invalid_json', 'Invalid JSON body passed.', ['status' => 400]);

        $response = apply_filters('rest_request_before_callbacks', $invalid, $route, $request);
        $refusal = $route['permission_callback']($request);

        self::assertNull($response);
        self::assertInstanceOf(WP_Error::class, $refusal);
        $data = $refusal->get_error_data();
        self::assertIsArray($data);
        self::assertSame(403, $data['status'] ?? null);
    }

    /**
     * @return array{methods: list<string>, callback: Closure, permission_callback: Closure}
     */
    private function handler(string $pattern, string $method): array
    {
        foreach ($this->recording->handlers[$pattern] ?? [] as $handler) {
            self::assertIsArray($handler);
            self::assertIsList($handler['methods'] ?? null);

            if (in_array($method, $handler['methods'], true)) {
                self::assertInstanceOf(Closure::class, $handler['callback'] ?? null);
                self::assertInstanceOf(Closure::class, $handler['permission_callback'] ?? null);

                /**
                 * @var array{
                 *     methods: list<string>,
                 *     callback: Closure,
                 *     permission_callback: Closure,
                 * }
                 */
                return $handler;
            }
        }

        self::fail(sprintf('no handler of %s responds to %s', $pattern, $method));
    }

    private static function firstErrorCode(WP_REST_Response $response): mixed
    {
        $data = $response->get_data();

        self::assertIsArray($data);
        self::assertIsArray($data['errors'] ?? null);
        self::assertIsArray($data['errors'][0] ?? null);

        return $data['errors'][0]['code'] ?? null;
    }

    /**
     * @return list<mixed>
     */
    private static function acceptedArguments(string $hook, int $priority): array
    {
        $hooks = $GLOBALS['wp_filter'] ?? null;

        self::assertIsArray($hooks);

        $filter = $hooks[$hook] ?? null;

        self::assertInstanceOf(WP_Hook::class, $filter);

        return array_values(array_map(
            static function (array $callback): mixed {
                return $callback['accepted_args'];
            },
            $filter->callbacks[$priority] ?? [],
        ));
    }
}
