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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;

/**
 * a route the library serves itself, dispatched by its route kind:
 * the route of an operation kind of a type, laid out by `Routing\RouteKind::segments()`
 * below the path of the type, or an atomic route at the path its declaration writes
 *
 * @internal
 *
 * @phpstan-sealed OperationRoute|AtomicRoute
 */
abstract readonly class GeneratedRoute extends Route
{
    /**
     * @param non-empty-list<string> $methods
     */
    protected function __construct(
        public RouteKind $routeKind,
        PathTemplate $template,
        array $methods,
    ) {
        parent::__construct($template, $methods);
    }

    /**
     * what the request addresses on this route
     *
     * @throws LogicException           when the request lacks a value the route pattern captures,
     *                                  which is a caller bug
     * @throws InvalidArgumentException when `Routing\RouteTarget` refuses a captured value
     */
    abstract public function target(ServerRequest $request): RouteTarget;
}
