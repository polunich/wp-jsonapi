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

/**
 * the query parameters WordPress itself reads on a REST request,
 * split by whether a request may carry them.
 * a parameter another plugin reads is not listed, so it is refused as an unknown one
 *
 * @internal
 */
final readonly class WordPressReservedParameters
{
    /**
     * the route of a REST request on a site without pretty permalinks
     */
    public const REST_ROUTE = 'rest_route';

    /**
     * acted on by WordPress before the library sees the request, so a request may carry them;
     * refusing `_wpnonce` would fail a browser its query-string nonce already authenticated
     *
     * @var list<string>
     */
    private const CONSUMED = [self::REST_ROUTE, '_method', '_wpnonce'];

    /**
     * each changes the body, the format or the language of a response the library writes.
     * the refusal cannot undo all of them: WordPress applies `_envelope` and `_jsonp` to the error
     * response after dispatch, and `determine_locale()` reads `_locale` before any route matches
     *
     * @var list<string>
     */
    private const REFUSED = ['_fields', '_embed', '_envelope', '_jsonp', '_locale'];

    public static function isConsumed(string $name): bool
    {
        return in_array($name, self::CONSUMED, true);
    }

    public static function isRefused(string $name): bool
    {
        return in_array($name, self::REFUSED, true);
    }

    public static function isReserved(string $name): bool
    {
        return self::isConsumed($name) || self::isRefused($name);
    }
}
