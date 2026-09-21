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

namespace Polunich\WpJsonApi\WordPress;

use Closure;
use Polunich\WpJsonApi\Metadata\Route;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * a handler declares no `args`: WordPress would validate and sanitize them
 * before the permission callback, and the library reads the query and the body itself.
 * `register_rest_route()` returns false only for an empty namespace or route,
 * which `RoutePatterns` rules out, so its return value is ignored
 *
 * @internal
 */
final readonly class RouteRegistrar
{
    /**
     * @param Closure(
     *     non-falsy-string,
     *     non-falsy-string,
     *     array<array-key, mixed>,
     * ): bool $registerRoute
     */
    public function __construct(
        private RoutePatterns $patterns,
        private RestController $controller,
        private RegisteredCallbacks $callbacks,
        private Closure $registerRoute,
    ) {}

    public function register(): void
    {
        foreach ($this->patterns->all() as $pattern => $routes) {
            ($this->registerRoute)(
                $this->patterns->namespace,
                $pattern,
                array_map($this->handlerOf(...), $routes),
            );
        }
    }

    /**
     * @return array{
     *     methods: non-empty-list<string>,
     *     callback: Closure(WP_REST_Request): (WP_REST_Response|WP_Error),
     *     permission_callback: Closure(WP_REST_Request): (bool|WP_Error),
     * }
     */
    private function handlerOf(Route $route): array
    {
        $callback = function (WP_REST_Request $request) use ($route): WP_REST_Response|WP_Error {
            return $this->controller->handle($route, $request);
        };
        $this->callbacks->record($callback, $route);

        return [
            'methods' => $route->methods,
            'callback' => $callback,
            'permission_callback' => function (WP_REST_Request $request) use ($route): bool|WP_Error {
                return $this->controller->authorize($route, $request);
            },
        ];
    }
}
