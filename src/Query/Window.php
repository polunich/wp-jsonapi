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

use InvalidArgumentException;

/**
 * the offset and the limit of a collection reading, whatever the pagination strategy:
 * a page number becomes an offset, and a cursor page starts at 0,
 * placed by the conditions its cursor adds to the filter
 */
final readonly class Window
{
    /**
     * @param int $limit the page size plus one, unless the size is `PHP_INT_MAX`:
     *                   the extra record tells whether a next page exists
     *                   without counting the whole set, and is dropped from the page
     *
     * @throws InvalidArgumentException when the offset is negative or the limit is below 1
     */
    public function __construct(
        public int $offset,
        public int $limit,
    ) {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf(
                'a window skips %d records, which is fewer than none',
                $offset,
            ));
        }

        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf(
                'a window reads %d records, and it reads at least one',
                $limit,
            ));
        }
    }
}
