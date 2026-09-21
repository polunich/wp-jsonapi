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

namespace Polunich\WpJsonApi\Metadata;

use Closure;
use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * the spec makes an id a string, and the resource route carries it as a path segment,
 * which is never empty, so `idOf()` refuses anything else.
 *
 * `$globallyUnique` states what no codec schema can: that the project's ids are globally unique.
 *
 * the accessor is the project's `Closure(TRecord): string`.
 * like every accessor of a declaration, it is stored as a bare `Closure`:
 * `Closure(object): string` would refuse a closure typed on the record class
 *
 * @internal
 */
final readonly class IdMetadata
{
    /**
     * @param list<string> $operators the names of the filter operators it accepts
     *
     * @throws InvalidArgumentException when an operator is declared twice
     */
    public function __construct(
        public SegmentCodec $codec,
        private Closure $accessor,
        public bool $globallyUnique,
        public array $operators = [],
    ) {
        $seen = [];

        foreach ($operators as $operator) {
            if (array_key_exists($operator, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'the id declares operator "%s" twice',
                    Printable::of($operator),
                ));
            }

            $seen[$operator] = true;
        }
    }

    /**
     * @throws UnexpectedValueException when the accessor returns what is no string,
     *                                  or the empty string
     */
    public function idOf(object $record): string
    {
        $id = ($this->accessor)($record);

        if (!is_string($id)) {
            throw new UnexpectedValueException(sprintf(
                'the id accessor returned %s for a record of class %s, and an id is a string [spec]',
                get_debug_type($id),
                get_debug_type($record),
            ));
        }

        if ($id === '') {
            throw new UnexpectedValueException(sprintf(
                'the id accessor returned the empty string for a record of class %s, which identifies no'
                . ' resource',
                get_debug_type($record),
            ));
        }

        return $id;
    }
}
