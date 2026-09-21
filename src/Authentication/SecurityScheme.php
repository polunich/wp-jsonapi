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

namespace Polunich\WpJsonApi\Authentication;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one entry of the OpenAPI `components.securitySchemes`: its key and its Security Scheme Object.
 *
 * the key is checked here, where it is given, rather than when the document is built.
 * the object is taken as written. it only has to hold a member:
 * the empty PHP array encodes as `[]`, and `type` is required.
 * a member that is an empty object is given as a `stdClass`
 */
final readonly class SecurityScheme
{
    /**
     * the component key pattern of OpenAPI 3.1.2.
     * its official JSON Schema reads it in the ECMA-262 dialect, where `$` matches only at the end;
     * `D` gives PCRE the same meaning, since without it `$` also matches before a final LF
     */
    private const NAME_PATTERN = '/^[a-zA-Z0-9\.\-_]+$/D';

    /**
     * @param array<string, mixed> $openApi
     *
     * @throws InvalidArgumentException when the name is no legal component key
     *                                  or the object is empty
     */
    public function __construct(public string $name, public array $openApi)
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                'a security scheme name must match ^[a-zA-Z0-9\.\-_]+$, got "'
                . Printable::of($name) . '"',
            );
        }

        if ($openApi === []) {
            throw new InvalidArgumentException(
                'the security scheme "' . $name . '" carries no Security Scheme Object: type is required',
            );
        }
    }

    /**
     * Application Passwords: HTTP Basic authentication,
     * written as the Basic Authentication Example of OpenAPI 3.1.2
     */
    public static function applicationPasswords(): self
    {
        return new self('applicationPasswords', ['type' => 'http', 'scheme' => 'basic']);
    }

    /**
     * the nonce of cookie authentication, `wp_create_nonce('wp_rest')`, in `X-WP-Nonce`,
     * the place WordPress calls the most reliable.
     * the browser sends the logged-in cookie it needs beside it,
     * so the scheme names the header alone
     */
    public static function restNonce(): self
    {
        return new self('restNonce', [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-WP-Nonce',
            'description' => 'the nonce of the action wp_rest, read beside the logged-in cookie of the site;'
                . ' without it WordPress treats the request as unauthenticated',
        ]);
    }
}
