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

use WP_REST_Request;
use WP_REST_Server;

/**
 * rewrites the route of a request into the normal form of `RoutePatterns`
 * on `rest_pre_dispatch`, just before WordPress matches the route it reads from the request:
 * `/pets/%31` and `/pets/1` then reach one pet under every permalink form,
 * as RFC 3986 2.3 makes the two paths equivalent.
 *
 * only a normal form that matches the pattern of a route of the library is written;
 * any other route stays as sent,
 * so a foreign route of the namespace reads its parameters as the client wrote them
 *
 * @internal
 */
final readonly class RouteNormalizer
{
    public function __construct(private RoutePatterns $patterns) {}

    /**
     * returns the result it was given: the library never responds to a request here
     */
    public function filterPreDispatch(mixed $result, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        $route = $this->patterns->normalizedRoute($request->get_route());

        if ($route !== null) {
            $request->set_route($route);
        }

        return $result;
    }
}
