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

namespace Polunich\WpJsonApi\Execution\Atomic;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use Throwable;

/**
 * one operation of an atomic request failed, and no effect of the request remains.
 *
 * the failure is kept whole, as `$failure` and as the previous exception:
 * its class decides the response, and a log of this exception holds the whole chain.
 * it is no `Error\JsonApiException`, since it is unwrapped before a response is chosen
 *
 * @internal
 */
final class OperationFailedException extends RuntimeException
{
    /**
     * @param int $position zero-based, in `atomic:operations`
     *
     * @throws InvalidArgumentException when the position is negative
     */
    public function __construct(public readonly int $position, public readonly Throwable $failure)
    {
        if ($position < 0) {
            throw new InvalidArgumentException(sprintf(
                'the position of a failed operation is counted from zero, and %d names no operation of the'
                . ' atomic request',
                $position,
            ));
        }

        parent::__construct(
            sprintf(
                'the operation at position %d of the atomic request failed, and every effect of the operations'
                . ' before it was rolled back: %s',
                $position,
                Printable::of($failure->getMessage()),
            ),
            0,
            $failure,
        );
    }
}
