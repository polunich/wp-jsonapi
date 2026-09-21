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

namespace Polunich\WpJsonApi\Tests\Integration;

use LogicException;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\RecordingRestServer;
use WP;

/**
 * sends a request through WordPress as a web server hands one over:
 * the request goes into `$_SERVER`, `$_GET`, `$_POST`, `$_FILES` and the raw body,
 * then `WP_REST_Server::serve_request()` runs as `rest_api_loaded()` calls it,
 * so every hook runs as it does for a client.
 *
 * each request gets a fresh server, as a PHP process of a site serves one request:
 * `rest_get_server()` creates it and fires `rest_api_init`, which registers the routes again.
 *
 * a header field is written as PHP receives it:
 * `HTTP_` and the name in upper case with `_` for `-`,
 * and `CONTENT_TYPE` and `CONTENT_LENGTH` without the prefix.
 * the output is what reaches the client: the buffer the client opens,
 * or the output capture where the library wrote a content itself
 *
 * @internal
 */
final class RestClient
{
    /** the front controller a path of `index.php/` permalinks names */
    private const INDEX = '/index.php';

    private function __construct() {}

    /**
     * @param string                  $route   below the REST prefix, `/fixture/v1/pets`
     * @param array<array-key, mixed> $query   as PHP decodes a query string
     * @param array<string, string>   $headers
     * @param array<array-key, mixed> $post    the fields PHP took from a form
     * @param array<array-key, mixed> $files   as `$_FILES` holds them
     */
    public static function send(
        string $method,
        string $route,
        array $query = [],
        array $headers = [],
        string $body = '',
        array $post = [],
        array $files = [],
    ): RestResponse {
        foreach (array_keys($_SERVER) as $key) {
            if (
                str_starts_with((string) $key, 'HTTP_') && $key !== 'HTTP_HOST'
                || in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)
            ) {
                unset($_SERVER[$key]);
            }
        }

        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $_SERVER[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = $value;
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $query;
        $_POST = $post;
        $_FILES = $files;
        $GLOBALS['HTTP_RAW_POST_DATA'] = $body;
        $GLOBALS['wp_rest_server'] = null;
        // an earlier request's application password check leaves its result here,
        // which a process of its own would not see
        $GLOBALS['wp_rest_application_password_status'] = null;

        $server = rest_get_server();

        if (!$server instanceof RecordingRestServer) {
            throw new LogicException('the REST server is not the recording one the bootstrap installs');
        }

        // a content the library writes ends this buffer and PHPUnit's to reach `OutputCapture`,
        // so the levels PHPUnit expects are opened again
        $level = ob_get_level();
        OutputCapture::start();
        ob_start();

        try {
            $server->serve_request($route);
        } finally {
            $buffered = ob_get_level() > $level ? (string) ob_get_clean() : '';
            $output = OutputCapture::stop() . $buffered;

            while (ob_get_level() < $level) {
                ob_start();
            }
        }

        return new RestResponse($server->status() ?? 200, $server->headers(), $output);
    }

    /**
     * reads the route from the link as WordPress does:
     * with pretty permalinks the path below the REST prefix as it was sent,
     * without them the `rest_route` query as PHP decodes it,
     * with `index.php/` permalinks the route `WP::parse_request()` reads from `PATH_INFO`
     *
     * @param array<string, string> $headers
     */
    public static function follow(string $url, array $headers = []): RestResponse
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $prefix = '/' . rest_get_url_prefix();

        if (isset($query['rest_route']) && is_string($query['rest_route'])) {
            $route = $query['rest_route'];
            unset($query['rest_route']);
        } elseif (str_starts_with($path, $prefix . '/')) {
            $route = substr($path, strlen($prefix));
        } elseif (str_starts_with($path, self::INDEX . $prefix . '/')) {
            $route = self::pathInfoRoute($path);
        } else {
            throw new LogicException(sprintf('the link %s names no REST route of the site', $url));
        }

        return self::send('GET', untrailingslashit($route), $query, $headers);
    }

    /**
     * a web server hands the path after `index.php` over as `PATH_INFO`:
     * "Unlike a URI path, the PATH_INFO is not URL-encoded" (RFC 3875, section 4.1.5).
     * WordPress matches its rewrite rules against it.
     * `rest_api_loaded()`, which would serve the request at once, is off `parse_request` meanwhile
     */
    private static function pathInfoRoute(string $path): string
    {
        $wp = $GLOBALS['wp'] ?? null;

        if (!$wp instanceof WP) {
            throw new LogicException('WordPress has no request parser');
        }

        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['PATH_INFO'] = rawurldecode(substr($path, strlen(self::INDEX)));
        $priority = has_action('parse_request', 'rest_api_loaded');
        remove_action('parse_request', 'rest_api_loaded');

        try {
            $wp->parse_request();
        } finally {
            unset($_SERVER['PATH_INFO']);

            if (is_int($priority)) {
                add_action('parse_request', 'rest_api_loaded', $priority);
            }
        }

        $route = $wp->query_vars['rest_route'] ?? null;

        if (!is_string($route)) {
            throw new LogicException(sprintf('WordPress read no REST route from the path %s', $path));
        }

        return $route;
    }
}
