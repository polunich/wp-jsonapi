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
use Polunich\WpJsonApi\Tests\Integration\RestClient;
use Polunich\WpJsonApi\Tests\Integration\WordPressTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @internal
 */
#[CoversNothing]
final class ResponseFilterTest extends WordPressTestCase
{
    /**
     * the route pattern holds the pattern of the codec of the id, so WordPress matches no route;
     * the path still lies on a route of the library,
     * which turns the response into an error document
     */
    public function testAnIdTheCodecRefusesIsNoRouteAsAnErrorDocument(): void
    {
        $response = self::send('GET', '/pets/abc');

        self::assertSame(404, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('rest_no_route', $response->member('errors', 0, 'code'));
        self::assertSame('1.1', $response->member('jsonapi', 'version'));
        self::assertMatchesContract($response, '/pets/abc', 'GET');
    }

    public function testAPathOfTheNamespaceOnNoRouteOfTheLibraryKeepsTheResponseOfWordPress(): void
    {
        $response = self::send('GET', '/nothing');

        self::assertSame(404, $response->status, $response->body);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('rest_no_route', $response->member('code'));
    }

    /**
     * another plugin may register a route on the namespace of the library:
     * it reads its parameter as the client sent it, and its error is its own
     */
    public function testARouteAnotherPluginRegistersOnTheNamespaceIsLeftAlone(): void
    {
        $read = null;
        $this->filter('rest_api_init', static function () use (&$read): void {
            register_rest_route(ltrim(self::ROUTE_PREFIX, '/'), '/foreign/(?P<name>[^/]+)', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => static function (WP_REST_Request $request) use (&$read): WP_Error {
                    $read = $request->get_url_params()['name'] ?? null;

                    return new WP_Error('site_conflict', 'the site says no', ['status' => 409]);
                },
            ]);
        });

        $response = self::send('GET', '/foreign/a%7Eb');

        self::assertSame(409, $response->status, $response->body);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
        self::assertSame('site_conflict', $response->member('code'));
        self::assertSame('a%7Eb', $read);
    }

    /**
     * a method no handler of the path takes gets WordPress's `rest_no_route`, a 404, not a 405
     */
    public function testAnUnknownMethodOnAPathIsNoRoute(): void
    {
        $response = self::send('PUT', '/pets');

        self::assertSame(404, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('rest_no_route', $response->member('errors', 0, 'code'));
    }

    public function testAForeign401GetsTheChallengesOfTheProvider(): void
    {
        $this->filter('wp_is_application_passwords_available', static function (): bool {
            return true;
        });
        $this->filter('rest_authentication_errors', static function (): WP_Error {
            return new WP_Error('site_says_no', 'the site says no', ['status' => 401]);
        });
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'password';

        $response = self::send('GET', '/pets');

        self::assertSame(401, $response->status, $response->body);
        self::assertSame('Basic realm="WordPress"', $response->header('WWW-Authenticate'));
        self::assertSame('site_says_no', $response->member('errors', 0, 'code'));
        self::assertSame('the site says no', $response->member('errors', 0, 'detail'));
        self::assertMatchesContract($response, '/pets', 'GET');
    }

    /**
     * WordPress raises the error before it looks for a handler, so none is matched,
     * and the create of a pet, which takes the nonce alone, is not the first route of `/pets`
     */
    public function testAForeign401IsChallengedForTheSchemesOfTheRouteOfItsMethod(): void
    {
        $this->filter('wp_is_application_passwords_available', static function (): bool {
            return true;
        });
        $this->filter('rest_authentication_errors', static function (): WP_Error {
            return new WP_Error('site_says_no', 'the site says no', ['status' => 401]);
        });
        $_SERVER['PHP_AUTH_USER'] = 'admin';
        $_SERVER['PHP_AUTH_PW'] = 'password';

        $read = self::send('GET', '/pets');
        $create = self::send('POST', '/pets', [], ['content-type' => self::JSON_API], '{}');

        self::assertSame(401, $read->status, $read->body);
        self::assertSame(403, $create->status, $create->body);
        self::assertNull($create->header('WWW-Authenticate'));
        self::assertSame('site_says_no', $create->member('errors', 0, 'code'));
        self::assertMatchesContract($create, '/pets', 'POST');
    }

    public function testAForeign401WithoutAChallengeBecomes403(): void
    {
        $this->filter('rest_authentication_errors', static function (): WP_Error {
            return new WP_Error('site_says_no', 'the site says no', ['status' => 401]);
        });

        $response = self::send('GET', '/pets');

        self::assertSame(403, $response->status, $response->body);
        self::assertNull($response->header('WWW-Authenticate'));
        self::assertSame('site_says_no', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/pets', 'GET');
    }

    /**
     * `DELETE /customers/1` is refused in `permission_callback`, so the change reaches a denial too
     */
    public function testAChangeOfACallbackAfterTheCallbacksReachesTheClient(): void
    {
        $this->filter('rest_request_after_callbacks', static function (mixed $response): mixed {
            if ($response instanceof WP_REST_Response) {
                $response->header('X-Site', 'seen');
            }

            return $response;
        });
        wp_set_current_user(1);

        $granted = self::send('GET', '/pets');
        $denied = self::send('DELETE', '/customers/1');

        self::assertSame(200, $granted->status, $granted->body);
        self::assertSame('seen', $granted->header('X-Site'));
        self::assertMatchesContract($granted, '/pets', 'GET');
        self::assertSame(403, $denied->status, $denied->body);
        self::assertSame('seen', $denied->header('X-Site'));
        self::assertSame(self::JSON_API, $denied->header('Content-Type'));
        self::assertSame('operation_not_supported', $denied->member('errors', 0, 'code'));
    }

    public function testAnErrorReturnedInPlaceOfTheResponseIsAnErrorDocument(): void
    {
        $this->filter('rest_request_after_callbacks', static function (): WP_Error {
            return new WP_Error('site_says_no', 'the site says no', ['status' => 409]);
        });

        $response = self::send('GET', '/pets');

        self::assertSame(409, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('site_says_no', $response->member('errors', 0, 'code'));
        self::assertSame('the site says no', $response->member('errors', 0, 'detail'));
    }

    public function testEverythingOutsideTheNamespaceIsLeftAlone(): void
    {
        $missing = RestClient::send('GET', '/wp/v2/nothing');

        self::assertSame(404, $missing->status);
        self::assertStringStartsWith('application/json', (string) $missing->header('Content-Type'));
        self::assertSame('rest_no_route', $missing->member('code'));

        $index = RestClient::send('GET', '/');

        self::assertSame(200, $index->status);
        self::assertStringStartsWith('application/json', (string) $index->header('Content-Type'));
    }

    public function testANamespaceThatBeginsLikeTheLibrarysIsAnother(): void
    {
        $response = RestClient::send('GET', self::ROUTE_PREFIX . 'x/pets');

        self::assertSame(404, $response->status);
        self::assertStringStartsWith('application/json', (string) $response->header('Content-Type'));
    }
}
