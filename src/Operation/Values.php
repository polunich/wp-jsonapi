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
use OutOfBoundsException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the members of an attributes or relationships object that a request carries,
 * or the declared query parameters it carries.
 *
 * a member the request leaves out is absent and keeps its current value,
 * while a member set to null is present with the value null.
 * the spec forbids reading a missing member as null, so `get()` refuses an absent name.
 *
 * PHP turns a member name of digits into an int key, so the map is keyed by `array-key`
 * and `names()` casts each key back to a string
 *
 * @template-covariant T
 */
final readonly class Values
{
    /**
     * @param array<array-key, T> $values member name => value, in request order
     *
     * @throws InvalidArgumentException when a member name is empty
     */
    public function __construct(private array $values)
    {
        if (array_key_exists('', $values)) {
            throw new InvalidArgumentException('a member name is never empty, and one of the values carries the'
                . ' empty name');
        }
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * @return T
     *
     * @throws OutOfBoundsException when the request leaves the member out
     */
    public function get(string $name): mixed
    {
        if (!array_key_exists($name, $this->values)) {
            throw new OutOfBoundsException(sprintf(
                'the request carries no "%s", which is absent, not null; ask has() first',
                Printable::of($name),
            ));
        }

        return $this->values[$name];
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(
            static function (int|string $name): string {
                return (string) $name;
            },
            array_keys($this->values),
        );
    }
}
