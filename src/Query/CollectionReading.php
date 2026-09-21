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

use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;

/**
 * the `filter`, `sort` and `page` of a request that reads a collection of `$type`.
 * `$filter` carries the conditions of a page cursor as well,
 * and every cursor of the page follows `$readingSort`
 *
 * @internal
 */
final readonly class CollectionReading
{
    /**
     * @param ?FilterNode $filter      null where the request filters nothing and carries no cursor
     * @param Sort        $sort        as the request wrote it, empty without `sort`
     * @param Sort        $readingSort the order the reading runs in, `id` included
     */
    public function __construct(
        public string $type,
        public ?FilterNode $filter,
        public Sort $sort,
        public Sort $readingSort,
        public PageRequest $page,
    ) {}
}
