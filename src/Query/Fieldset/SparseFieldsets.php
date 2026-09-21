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

namespace Polunich\WpJsonApi\Query\Fieldset;

use InvalidArgumentException;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the client's `fields[TYPE]` parameters, per type.
 *
 * a type the client did not name differs from a type it named with no field:
 * for the first the server "MAY send all fields",
 * for the second it "MUST NOT include additional fields",
 * so `fields()` returns null for the one and `[]` for the other.
 *
 * whether a type exists and carries the field is not checked here.
 * a type of digits arrives under an int key, so the map is keyed `array-key`
 * and a type is read back with `(string)`
 */
final readonly class SparseFieldsets
{
    /**
     * @var array<array-key, list<string>>
     */
    private array $fieldsets;

    /**
     * @param array<array-key, list<string>> $fieldsets type => field names
     *
     * @throws InvalidArgumentException when a type or a field is no member name
     */
    public function __construct(array $fieldsets)
    {
        $normalized = [];

        foreach ($fieldsets as $type => $fields) {
            if (!MemberName::isValid((string) $type)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" is no member name, so no resource type carries it',
                    Printable::of((string) $type),
                ));
            }

            foreach ($fields as $field) {
                if (!MemberName::isValid($field)) {
                    throw new InvalidArgumentException(sprintf(
                        'field "%s" of type "%s" is no member name, so no attribute and no '
                        . 'relationship carries it',
                        Printable::of($field),
                        (string) $type,
                    ));
                }
            }

            $normalized[$type] = array_values(array_unique($fields));
        }

        $this->fieldsets = $normalized;
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->fieldsets);
    }

    /**
     * @return list<string>|null
     */
    public function fields(string $type): ?array
    {
        return $this->fieldsets[$type] ?? null;
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_map(
            static function (int|string $type): string {
                return (string) $type;
            },
            array_keys($this->fieldsets),
        );
    }

    public function isEmpty(): bool
    {
        return $this->fieldsets === [];
    }
}
