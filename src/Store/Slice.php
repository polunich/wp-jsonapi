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
 * the records read for one `CollectionQuery`,
 * and the size of the collection where the store knows it
 *
 * @template TRecord of object
 */
final readonly class Slice
{
    /**
     * @param list<TRecord> $records
     * @param ?int          $total          the size of the whole collection, `meta.page.total`;
     *                                      null when the store does not count it
     * @param ?int          $estimatedTotal an estimate of that size, null when none;
     *                                      rendered as `meta.page.estimatedTotal.bestGuess`,
     *                                      spelled by the naming policy
     *
     * @throws InvalidArgumentException when a size is negative
     */
    public function __construct(
        public array $records,
        public ?int $total = null,
        public ?int $estimatedTotal = null,
    ) {
        if ($total !== null && $total < 0) {
            throw new InvalidArgumentException(sprintf(
                'a collection holds %d records, which is fewer than none',
                $total,
            ));
        }

        if ($estimatedTotal !== null && $estimatedTotal < 0) {
            throw new InvalidArgumentException(sprintf(
                'a collection is estimated to hold %d records, which is fewer than none',
                $estimatedTotal,
            ));
        }
    }
}
