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

namespace Polunich\WpJsonApi\Execution\Result;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Http\EntityTag;

/**
 * the results of one atomic request, one per operation and at its position,
 * as the atomic operations extension requires.
 *
 * a create or an update returns a `ResourceResult` with its resource;
 * a delete or a relationship write returns a `NoContentResult`, the empty result object `{}`.
 * a `ResourceResult` without a resource would be neither, so it is refused.
 *
 * the constructor takes a `list` and checks that it is not empty:
 * typed `non-empty-list`, that check would be reported by PHPStan as `identical.alwaysFalse`
 *
 * @internal
 */
final readonly class AtomicResult implements Result
{
    /**
     * @var non-empty-list<ResourceResult|NoContentResult>
     */
    public array $results;

    /**
     * @param list<ResourceResult|NoContentResult> $results
     *
     * @throws InvalidArgumentException when the list is empty,
     *                                  or a `ResourceResult` carries no resource
     */
    public function __construct(array $results)
    {
        if ($results === []) {
            throw new InvalidArgumentException(
                'an atomic response holds a result of every operation of the request, and this one'
                . ' holds none, while a request holds one or more operations [ext-atomic]',
            );
        }

        foreach ($results as $position => $result) {
            if ($result instanceof ResourceResult && $result->resource === null) {
                throw new InvalidArgumentException(sprintf(
                    'the result of the operation at position %d carries no resource, and a result'
                    . ' object of an atomic response either carries the resource of its operation or'
                    . ' is the empty result object [ext-atomic]',
                    $position,
                ));
            }
        }

        $this->results = $results;
    }

    #[Override]
    public function status(): int
    {
        foreach ($this->results as $result) {
            if (!$result instanceof NoContentResult) {
                return 200;
            }
        }

        return 204;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return null;
    }
}
