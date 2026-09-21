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

namespace Polunich\WpJsonApi\Store;

use InvalidArgumentException;

/**
 * the result of a write the store accepted but has not finished:
 * the resource that monitors the work, rendered as the primary data of a 202 response.
 *
 * `$type` must be the monitor type the resource declares for the write kind,
 * or the request fails with 500.
 * an atomic request cannot leave a write unfinished,
 * so there an `Accepted` fails its operation and rolls the whole request back
 */
final readonly class Accepted
{
    /**
     * @throws InvalidArgumentException when the type is empty
     */
    public function __construct(
        public string $type,
        public object $record,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('an accepted write names the type of the resource that monitors it,'
                . ' and a type is never empty');
        }
    }
}
