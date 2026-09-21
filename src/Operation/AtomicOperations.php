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

namespace Polunich\WpJsonApi\Operation;

use InvalidArgumentException;

/**
 * the operations of one atomic request, never empty, in request order:
 * a key is the `<n>` of the pointer `/atomic:operations/<n>`
 *
 * @internal
 */
final readonly class AtomicOperations
{
    /**
     * @var non-empty-list<AtomicOperation>
     */
    private array $operations;

    /**
     * @param list<AtomicOperation> $operations
     *
     * @throws InvalidArgumentException when the list is empty
     */
    public function __construct(array $operations)
    {
        if ($operations === []) {
            throw new InvalidArgumentException('an atomic request holds one or more operations, and this one holds'
                . ' none');
        }

        $this->operations = $operations;
    }

    /**
     * @return non-empty-list<AtomicOperation>
     */
    public function all(): array
    {
        return $this->operations;
    }
}
