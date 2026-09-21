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

namespace Polunich\WpJsonApi\Query;

/**
 * how a client spells the multi-word names the library defines:
 * the members of `page`, the built-in filter operators,
 * and the members of `meta.page` in a document and in an error.
 * PHP code names them in camel case under either policy, such as `@notIn` in a `Condition`;
 * the names a project declares, such as its types and fields, reach the client as declared
 */
enum NamingPolicy
{
    /**
     * `page[with_count]`, `@not_in`, `meta.page.estimated_total`, as WordPress spells `per_page`
     */
    case SnakeCase;

    /**
     * `page[withCount]`, `@notIn`, `meta.page.estimatedTotal`: the spelling JSON:API recommends
     * for member names and the cursor pagination profile uses
     */
    case CamelCase;

    /**
     * a name of one word is spelled alike under either policy
     *
     * @param string $camelCase such as `withCount` or `@startsWithi`
     */
    public function name(string $camelCase): string
    {
        return match ($this) {
            self::CamelCase => $camelCase,
            self::SnakeCase => strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $camelCase)),
        };
    }
}
