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
use Stringable;

/**
 * the sort fields of one reading, applied in the order given.
 * the empty sort stands for a request without `sort`
 */
final readonly class Sort implements Stringable
{
    private const SEPARATOR = ',';

    /**
     * @var list<SortField>
     */
    private array $fields;

    /**
     * @param list<SortField> $fields
     *
     * @throws InvalidArgumentException when a field path appears twice
     */
    public function __construct(array $fields)
    {
        $seen = [];

        foreach ($fields as $field) {
            $path = $field->field->toString();

            if (array_key_exists($path, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'field "%s" is sorted by twice in one sort',
                    $path,
                ));
            }

            $seen[$path] = true;
        }

        $this->fields = $fields;
    }

    /**
     * refuses the empty text although `toString()` writes it for the empty sort:
     * the spec's `sort` value "MUST represent sort fields", and the empty sort is built, never read
     *
     * @param string $sort `-listedAt,name`
     *
     * @throws InvalidArgumentException when a sort field is no field path after an optional `-`,
     *                                  or a field path appears twice
     */
    public static function fromString(string $sort): self
    {
        return new self(array_map(
            static function (string $field): SortField {
                return SortField::fromString($field);
            },
            explode(self::SEPARATOR, $sort),
        ));
    }

    public static function byId(): self
    {
        return new self([new SortField(FieldPath::id())]);
    }

    /**
     * @return list<SortField>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * in either direction
     */
    public function hasField(FieldPath $field): bool
    {
        $path = $field->toString();

        foreach ($this->fields as $sortField) {
            if ($sortField->field->toString() === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidArgumentException when the sort already orders by that field
     */
    public function withField(SortField $field): self
    {
        $fields = $this->fields;
        $fields[] = $field;

        return new self($fields);
    }

    /**
     * the sort a reading runs in.
     * a type and an id identify one resource, so with `id` the order is total:
     * a page number or an offset names the same records on every read,
     * and a cursor splits the records in two
     */
    public function withId(): self
    {
        $id = FieldPath::id();

        return $this->hasField($id) ? $this : $this->withField(new SortField($id));
    }

    public function reverse(): self
    {
        return new self(array_map(
            static function (SortField $field): SortField {
                return new SortField($field->field, $field->direction->reverse());
            },
            $this->fields,
        ));
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, array_map(
            static function (SortField $field): string {
                return $field->toString();
            },
            $this->fields,
        ));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
