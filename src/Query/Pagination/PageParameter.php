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

namespace Polunich\WpJsonApi\Query\Pagination;

use Polunich\WpJsonApi\Query\NamingPolicy;

/**
 * a member of the `page` family, named in camel case;
 * `nameIn()` spells it as the client writes it between the brackets.
 * which strategy a member selects or belongs to is `Strategy`'s
 */
enum PageParameter: string
{
    case Number = 'number';

    case Size = 'size';

    case Offset = 'offset';

    case Limit = 'limit';

    case After = 'after';

    case Before = 'before';

    case WithCount = 'withCount';

    /**
     * `withCount` locates no page: it stays the same on every page of a walk
     */
    public function isPosition(): bool
    {
        return $this !== self::WithCount;
    }

    public function nameIn(NamingPolicy $naming): string
    {
        return $naming->name($this->value);
    }

    /**
     * null for a name that spells no member under the policy
     */
    public static function named(string $name, NamingPolicy $naming): ?self
    {
        foreach (self::cases() as $parameter) {
            if ($parameter->nameIn($naming) === $name) {
                return $parameter;
            }
        }

        return null;
    }
}
