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

namespace Polunich\WpJsonApi\Http;

/**
 * the request methods the library serves, and the rules of RFC 9110 that turn on a method.
 * a method token is case-sensitive (RFC 9110 9.1),
 * and `ServerRequest::$method` carries it upper case, so a method is compared exactly
 *
 * @internal
 */
final readonly class Method
{
    public const GET = 'GET';

    public const HEAD = 'HEAD';

    public const POST = 'POST';

    public const PUT = 'PUT';

    public const PATCH = 'PATCH';

    public const DELETE = 'DELETE';

    /**
     * RFC 9110 9.2.1 defines these as safe
     */
    private const SAFE = [self::GET, self::HEAD, 'OPTIONS', 'TRACE'];

    /**
     * the methods a route declared with `$method` responds to: HEAD beside GET,
     * since every general-purpose server supports both (RFC 9110 9.1)
     * and HEAD is GET without content (RFC 9110 9.3.2)
     *
     * @return non-empty-list<string>
     */
    public static function served(string $method): array
    {
        return $method === self::GET ? [self::GET, self::HEAD] : [$method];
    }

    /**
     * GET and HEAD, which transfer a representation, or its metadata alone.
     * on a false `If-None-Match` the server responds to them with 304
     * and to any other method with 412 (RFC 9110 13.1.2)
     */
    public static function retrieves(string $method): bool
    {
        return $method === self::GET || $method === self::HEAD;
    }

    public static function isSafe(string $method): bool
    {
        return in_array($method, self::SAFE, true);
    }
}
