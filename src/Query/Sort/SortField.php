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

namespace Polunich\WpJsonApi\Query\Sort;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Text\Printable;
use Stringable;

final readonly class SortField implements Stringable
{
    public function __construct(
        public FieldPath $field,
        public Direction $direction = Direction::Ascending,
    ) {}

    /**
     * an optional `-` for descending, then a field path read by `FieldPath::fromString()`,
     * whose refusal becomes the previous exception; `toString()` writes the text back unchanged.
     * nothing else is read: no `+`, reserved in member names, no whitespace, no second `-`
     *
     * @param string $field `-listedAt` or `owner.name`
     *
     * @throws InvalidArgumentException when the text is no field path with at most a `-` before it
     */
    public static function fromString(string $field): self
    {
        $prefix = Direction::Descending->prefix();
        $descending = str_starts_with($field, $prefix);

        try {
            $path = FieldPath::fromString($descending ? substr($field, strlen($prefix)) : $field);
        } catch (InvalidArgumentException $refusal) {
            throw new InvalidArgumentException(
                sprintf(
                    'sort field "%s" is no field path of member names separated by ".", with at most a "-"'
                    . ' before it',
                    Printable::of($field),
                ),
                0,
                $refusal,
            );
        }

        return new self($path, $descending ? Direction::Descending : Direction::Ascending);
    }

    public function toString(): string
    {
        return $this->direction->prefix() . $this->field->toString();
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
