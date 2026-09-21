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

namespace Polunich\WpJsonApi\Query\Filter;

use InvalidArgumentException;
use Override;

/**
 * `@and`: every child matches
 */
final readonly class AllOf implements FilterNode
{
    /**
     * @var non-empty-list<FilterNode>
     */
    public array $nodes;

    /**
     * @param list<FilterNode> $nodes
     *
     * @throws InvalidArgumentException when the list is empty
     */
    public function __construct(array $nodes)
    {
        if ($nodes === []) {
            throw new InvalidArgumentException('"@and" holds at least one condition');
        }

        $this->nodes = $nodes;
    }

    /**
     * @template R
     *
     * @param Visitor<R> $visitor
     *
     * @return R
     */
    #[Override]
    public function accept(Visitor $visitor): mixed
    {
        return $visitor->allOf($this);
    }
}
