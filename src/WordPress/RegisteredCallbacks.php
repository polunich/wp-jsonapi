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
use WeakMap;

/**
 * the route of each callback the library registered with WordPress;
 * a callback it does not hold is foreign, even on the namespace of the library.
 *
 * WordPress sets the matched handler, its `callback` among them, as the attributes of the request,
 * and that is the very closure the library registered, so identity alone tells whose it is.
 *
 * the map is weak: each `rest_api_init` registers the routes again, with new closures,
 * and the closures of a server no request uses any more leave no entry behind.
 * it is mutable on purpose: it records each callback as it is registered
 *
 * @internal
 */
final class RegisteredCallbacks
{
    /** @var WeakMap<Closure, Route> */
    private WeakMap $routes;

    public function __construct()
    {
        $this->routes = new WeakMap();
    }

    public function record(Closure $callback, Route $route): void
    {
        $this->routes[$callback] = $route;
    }

    /**
     * null for a foreign callback or none at all
     */
    public function routeOf(mixed $callback): ?Route
    {
        return $callback instanceof Closure ? $this->routes[$callback] ?? null : null;
    }
}
