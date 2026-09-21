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

namespace Polunich\WpJsonApi\Tests\Integration\WordPress;

use PHPUnit\Framework\Attributes\CoversNothing;
use Polunich\WpJsonApi\Tests\Fixtures\Plugin\FixturePlugin;
use Polunich\WpJsonApi\Tests\Integration\RestClient;
use Polunich\WpJsonApi\Tests\Integration\WordPressTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class RouteRegistrationTest extends WordPressTestCase
{
    /**
     * every method of a route is registered, enabled by the declaration or not.
     * the pattern of an id is the one of its codec, under the name its type gives it.
     * the namespace index is WordPress's own
     */
    public function testEveryPathOfTheContractIsARouteWithItsMethods(): void
    {
        $GLOBALS['wp_rest_server'] = null;
        $routes = rest_get_server()->get_routes(ltrim(self::ROUTE_PREFIX, '/'));
        $methods = [];

        foreach ($routes as $pattern => $handlers) {
            $patternMethods = [];

            foreach (is_array($handlers) ? $handlers : [] as $handler) {
                $handled = is_array($handler) && is_array($handler['methods'] ?? null) ? $handler['methods'] : [];
                $patternMethods = [...$patternMethods, ...array_map(strval(...), array_keys(array_filter($handled)))];
            }

            sort($patternMethods);
            $methods[$pattern] = implode(',', $patternMethods);
        }

        $id = '(?P<id>(?=(?:0|[1-9][0-9]*)(?:/|\z))[^/]+)';
        $orderId = '(?P<order_id>(?=(?:[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12})(?:'
            . '/|\z))[^/]+)';

        self::assertSame([
            self::ROUTE_PREFIX => 'GET',
            self::ROUTE_PREFIX . '/pets' => 'GET,HEAD,POST',
            self::ROUTE_PREFIX . '/pets/' . $id => 'DELETE,GET,HEAD,PATCH',
            self::ROUTE_PREFIX . '/pets/' . $id . '/owner' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/' . $id . '/relationships/owner' => 'GET,HEAD,PATCH',
            self::ROUTE_PREFIX . '/pets/' . $id . '/orders' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/' . $id . '/relationships/orders' => 'DELETE,GET,HEAD,PATCH,POST',
            self::ROUTE_PREFIX . '/customers' => 'GET,HEAD,POST',
            self::ROUTE_PREFIX . '/customers/' . $id => 'DELETE,GET,HEAD,PATCH',
            self::ROUTE_PREFIX . '/shop/orders' => 'GET,HEAD,POST',
            self::ROUTE_PREFIX . '/shop/orders/' . $orderId => 'DELETE,GET,HEAD,PATCH',
            self::ROUTE_PREFIX . '/shop/orders/' . $orderId . '/customer' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/shop/orders/' . $orderId . '/relationships/customer' => 'GET,HEAD,PATCH',
            self::ROUTE_PREFIX . '/customers/' . $id . '/pets' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/' . $id . '/adopt' => 'POST',
            self::ROUTE_PREFIX . '/pets/recent' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/export' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/' . $id . '/photo' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/pets/' . $id . '/photos' => 'POST',
            self::ROUTE_PREFIX . '/webhooks/payments' => 'POST',
            self::ROUTE_PREFIX . '/stats' => 'GET,HEAD',
            self::ROUTE_PREFIX . '/operations' => 'POST',
            self::ROUTE_PREFIX . '/pets/operations' => 'POST',
        ], $methods);
    }

    /**
     * the fixture plugin registers its API as it loads;
     * the API is built by the first `rest_api_init` of the process and by no later one,
     * while each registers the routes of its own server
     */
    public function testTheApiIsBuiltOnceByTheFirstRestApiInit(): void
    {
        self::assertSame(did_action('rest_api_init') > 0 ? 1 : 0, FixturePlugin::builds());

        $first = self::send('GET', '/pets');
        $second = self::send('GET', '/pets');

        self::assertSame(200, $first->status, $first->body);
        self::assertSame(200, $second->status, $second->body);
        self::assertSame(1, FixturePlugin::builds());
    }

    public function testEveryHandlerCarriesAPermissionAndNoArguments(): void
    {
        $GLOBALS['wp_rest_server'] = null;

        foreach (rest_get_server()->get_routes(ltrim(self::ROUTE_PREFIX, '/')) as $pattern => $handlers) {
            if ($pattern === self::ROUTE_PREFIX) {
                continue;
            }

            self::assertIsArray($handlers);

            foreach ($handlers as $handler) {
                self::assertIsArray($handler);
                self::assertIsCallable($handler['permission_callback'] ?? null, $pattern);
                self::assertSame([], $handler['args'] ?? [], $pattern);
            }
        }
    }

    /**
     * WordPress's `Allow` lists the methods whose permission callback lets the request through,
     * and a write of the fixture API needs a logged-in user
     */
    public function testOptionsIsWordPresssResponse(): void
    {
        $response = self::send('OPTIONS', '/pets');

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('GET, HEAD', $response->header('Allow'));

        wp_set_current_user(1);

        self::assertSame('GET, HEAD, POST', self::send('OPTIONS', '/pets')->header('Allow'));
    }

    public function testAnOperationTheDeclarationDoesNotEnableIsForbidden(): void
    {
        wp_set_current_user(1);

        $response = self::send('DELETE', '/customers/1');

        self::assertSame(403, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('operation_not_supported', $response->member('errors', 0, 'code'));
    }

    public function testTheNamespaceIndexIsWordPresss(): void
    {
        $response = RestClient::send('GET', self::ROUTE_PREFIX);

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame(ltrim(self::ROUTE_PREFIX, '/'), $response->member('namespace'));
    }
}
