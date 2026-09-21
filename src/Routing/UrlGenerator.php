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

namespace Polunich\WpJsonApi\Routing;

/**
 * builds the absolute URL of a path below the REST namespace.
 * the implementation appends the query, because only it knows whether its URL already has one.
 *
 * `$query` is keyed as PHP parses a query string: a name of digits arrives as an int key,
 * which an implementation casts back with `(string)`
 *
 * @internal
 */
interface UrlGenerator
{
    /**
     * each segment is its text, which the implementation percent-encodes
     *
     * @param non-empty-list<string>  $segments the path below the namespace
     * @param array<array-key, mixed> $query
     */
    public function url(array $segments, array $query = []): string;
}
