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

namespace Polunich\WpJsonApi\Error;

use InvalidArgumentException;

/**
 * the status of a response that lists several problems:
 * "the most generally applicable HTTP error code SHOULD be used in the response. For instance,
 * 400 Bad Request might be appropriate for multiple 4xx errors" (JSON:API, Processing Errors).
 *
 * problems of one status keep it; a mix of 4xx statuses gives 400,
 * the x00 status a client falls back to for any status of the class (RFC 9110 15).
 * a status outside 4xx has no defined result, so it is refused rather than guessed
 *
 * @internal
 */
final readonly class StatusSelector
{
    /**
     * @template T of int
     *
     * @param list<T> $statuses one per problem, repeats allowed
     *
     * @return T|400
     *
     * @throws InvalidArgumentException when the list is empty or a status is not 4xx
     */
    public function select(array $statuses): int
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('a status is selected among the statuses of at least one problem');
        }

        foreach ($statuses as $status) {
            if ($status < 400 || $status > 499) {
                throw new InvalidArgumentException(
                    'a status is selected among client errors, 4xx, got ' . $status,
                );
            }
        }

        $distinct = array_unique($statuses);

        return count($distinct) === 1 ? $statuses[0] : 400;
    }
}
