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
use Override;
use Polunich\WpJsonApi\Metadata\WordPressReservedParameters;
use Polunich\WpJsonApi\Routing\UrlGenerator;

/**
 * the URLs of the API, from `rest_url()`.
 *
 * `rest_url()` escapes nothing: it appends the path to the REST root or, without pretty permalinks,
 * hands it to `add_query_arg()` as the `rest_route` value, which is expected encoded already.
 * so every segment is `rawurlencode()`d: no `/`, `?`, `#` or `%` of its own can end or change it,
 * and the sub-delimiters, `:` and `@` it encodes as well mean the same encoded.
 * without pretty permalinks the whole path is encoded once more, `/` kept,
 * since PHP decodes the query value once: both kinds of site then match the same path.
 * the namespace is the plugin's own and stays as it is.
 *
 * the permalink form is read from the URL `rest_url()` returns for the path, never kept,
 * since `rest_url()` reads the permalink option on every call.
 * a `rest_route` argument in that URL has the path asked for again, encoded once more,
 * so a site with pretty permalinks is asked once.
 * any other query argument, such as one a `rest_url` filter adds, leaves the form as it is.
 *
 * the query is encoded with `PHP_QUERY_RFC3986`: a space becomes `%20`, never a `+`,
 * which RFC 3986 does not read as a space
 *
 * @internal
 */
final readonly class RestUrlGenerator implements UrlGenerator
{
    /**
     * @param Closure(string): string $restUrl `rest_url()`
     */
    public function __construct(
        private string $namespace,
        private Closure $restUrl,
    ) {}

    /**
     * @param non-empty-list<string>  $segments
     * @param array<array-key, mixed> $query
     */
    #[Override]
    public function url(array $segments, array $query = []): string
    {
        $path = '/' . $this->namespace . '/' . implode('/', array_map(rawurlencode(...), $segments));
        $url = ($this->restUrl)($path);

        if (self::hasRestRoute($url)) {
            $url = ($this->restUrl)(str_replace('%2F', '/', rawurlencode($path)));
        }

        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function hasRestRoute(string $url): bool
    {
        $arguments = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $arguments);

        return array_key_exists(WordPressReservedParameters::REST_ROUTE, $arguments);
    }
}
