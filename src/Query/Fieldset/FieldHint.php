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
 * the fields a reading is asked to load: the client's `fields[TYPE]`,
 * widened by the fields the library reads whatever the client asked for.
 *
 * a hint, not a limit: a reading that loads the whole row anyway may ignore it
 * and loses only the work the hint would have saved
 */
final readonly class FieldHint
{
    /**
     * @var list<string>|null
     */
    private ?array $fields;

    /**
     * @param list<string>|null $requested null when the client sent no `fields[TYPE]` for the type:
     *                                     every field is loaded then, `$required` included
     * @param list<string>      $required
     *
     * @throws InvalidArgumentException when a field is no member name
     */
    public function __construct(?array $requested, array $required = [])
    {
        foreach ($requested ?? [] as $field) {
            self::check($field);
        }

        foreach ($required as $field) {
            self::check($field);
        }

        if ($requested === null) {
            $this->fields = null;

            return;
        }

        $this->fields = array_values(array_unique([...$requested, ...$required]));
    }

    /**
     * null when every field is meant
     *
     * @return list<string>|null
     */
    public function fields(): ?array
    {
        return $this->fields;
    }

    public function hasEveryField(): bool
    {
        return $this->fields === null;
    }

    public function has(string $field): bool
    {
        return $this->fields === null || in_array($field, $this->fields, true);
    }

    /**
     * @throws InvalidArgumentException when the field is no member name
     */
    private static function check(string $field): void
    {
        if (!MemberName::isValid($field)) {
            throw new InvalidArgumentException(sprintf(
                'field "%s" is no member name, so no attribute and no relationship carries it',
                Printable::of($field),
            ));
        }
    }
}
