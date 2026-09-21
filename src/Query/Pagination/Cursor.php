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

namespace Polunich\WpJsonApi\Query\Pagination;

use InvalidArgumentException;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the boundary record of a cursor page: its sort values and its id.
 * the adjacent page is found by comparing against these values, not by skipping rows.
 *
 * the values are keyed by field path, so a cursor read back under another sort is caught
 * instead of being applied to the wrong field.
 * a field of digits arrives under an int key, hence `array-key`; read a key back with `(string)`.
 * cursors are unsigned: every key is checked because the client may have edited it,
 * and every value is readable by the client, so a secret field does not belong in a sort.
 * there is no null, since a nullable field cannot be paginated by cursor,
 * and no non-finite float, which orders nothing and has no JSON form
 */
final readonly class Cursor
{
    /**
     * @param array<array-key, bool|float|int|string> $sortValues path => value, in sort order
     *
     * @throws InvalidArgumentException when a key is no field path, a value is not finite,
     *                                  or the id is empty
     */
    public function __construct(
        public array $sortValues,
        public string $id,
    ) {
        foreach ($sortValues as $field => $value) {
            if (!FieldPath::isValid((string) $field)) {
                throw new InvalidArgumentException(sprintf(
                    'a sort value of a cursor is keyed by the field path it belongs to, and "%s" is none',
                    Printable::of((string) $field),
                ));
            }

            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException(sprintf(
                    'sort value %s of field "%s" is not a finite number, so it orders nothing',
                    var_export($value, true),
                    (string) $field,
                ));
            }
        }

        if ($id === '') {
            throw new InvalidArgumentException('a cursor carries the id of the record it stands at, which is never'
                . ' empty');
        }
    }
}
