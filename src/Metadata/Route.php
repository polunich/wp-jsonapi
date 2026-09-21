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

use Polunich\WpJsonApi\Routing\PathTemplate;

/**
 * one route the library registers: a `GeneratedRoute`, laid out from the declared types,
 * or the `EndpointRoute` of an endpoint.
 * each variant derives its template and its methods from what it carries,
 * so they cannot disagree with it
 *
 * @internal
 *
 * @phpstan-sealed GeneratedRoute|EndpointRoute
 */
abstract readonly class Route
{
    /**
     * @param PathTemplate           $template relative to the REST namespace;
     *                                         the one source of every path of the route
     * @param non-empty-list<string> $methods  upper case, as in `Http\ServerRequest::$method`,
     *                                         the declared method first
     */
    protected function __construct(
        public PathTemplate $template,
        public array $methods,
    ) {}

    /**
     * the route as a message names it, such as `fetch_resource route of type "pets"`
     */
    abstract public function subject(): string;

    /** case-sensitive, as RFC 9110 (section 9) defines the method token */
    final public function respondsTo(string $method): bool
    {
        return in_array($method, $this->methods, true);
    }

    final public function sharedMethod(self $other): ?string
    {
        foreach ($this->methods as $method) {
            if ($other->respondsTo($method)) {
                return $method;
            }
        }

        return null;
    }
}
