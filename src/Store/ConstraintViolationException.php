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

namespace Polunich\WpJsonApi\Store;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use Throwable;

/**
 * a write would break a constraint the store enforces, so it gets 409;
 * a taken client-generated id is `ResourceExistsException` instead.
 *
 * `$constraint` is the stable name a client tells the rules apart by;
 * `$fields` are the attributes and relationships the constraint concerns, if any
 */
final class ConstraintViolationException extends RuntimeException
{
    private const CONSTRAINT = '/^[a-z]+(?:_[a-z]+)*$/D';

    /**
     * @param string       $constraint
     * @param list<string> $fields
     * @param ?Throwable   $previous
     *
     * @throws InvalidArgumentException when the constraint is no lower_snake name,
     *                                  or a field is empty or named twice
     */
    public function __construct(
        public readonly string $constraint,
        public readonly array $fields = [],
        ?Throwable $previous = null,
    ) {
        if (preg_match(self::CONSTRAINT, $constraint) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'constraint "%s" is no lower_snake name of lower-case letters and single underscores',
                Printable::of($constraint),
            ));
        }

        // PHP casts only a canonical decimal string to an int key,
        // so two different names never share a key of this set
        $seen = [];

        foreach ($fields as $field) {
            if ($field === '') {
                throw new InvalidArgumentException(sprintf(
                    'constraint "%s" names an empty field, and a field name is never empty',
                    $constraint,
                ));
            }

            if (array_key_exists($field, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'constraint "%s" names field "%s" twice',
                    $constraint,
                    Printable::of($field),
                ));
            }

            $seen[$field] = true;
        }

        parent::__construct(self::describe($constraint, $fields), 0, $previous);
    }

    /**
     * @param list<string> $fields
     */
    private static function describe(string $constraint, array $fields): string
    {
        if ($fields === []) {
            return sprintf('the write violates constraint "%s"', $constraint);
        }

        return sprintf(
            'the write violates constraint "%s" of fields %s',
            $constraint,
            implode(', ', array_map(
                static function (string $field): string {
                    return '"' . Printable::of($field) . '"';
                },
                $fields,
            )),
        );
    }
}
