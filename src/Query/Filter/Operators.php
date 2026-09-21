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

namespace Polunich\WpJsonApi\Query\Filter;

use InvalidArgumentException;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the filter vocabulary: the only place an operator name is spelled.
 *
 * the built-in comparisons are Strapi's filter operators, in Strapi's order, with `@` for `$`:
 * `$` is reserved in a member name, while an @-member is never an attribute,
 * so an operator cannot collide with a field.
 * the logical operators are nodes of the tree, not comparisons, and have no operand shape.
 * a custom operator is not listed: its own value codec is the one source of its value's shape
 */
final readonly class Operators
{
    public const ALL_OF = '@and';

    public const ANY_OF = '@or';

    public const NOT = '@not';

    public const EQUAL = '@eq';

    public const GREATER_THAN = '@gt';

    public const LESS_THAN = '@lt';

    /**
     * one value of the field
     */
    public const OPERAND_SCALAR = 'scalar';

    /**
     * one string, matched against a fragment or a case variant of the field;
     * it need not be a value the field's codec accepts
     */
    public const OPERAND_TEXT = 'text';

    /**
     * a list of values of the field
     */
    public const OPERAND_LIST = 'list';

    public const OPERAND_BOOLEAN = 'boolean';

    /**
     * two values of the field, the bounds of a range
     */
    public const OPERAND_PAIR = 'pair';

    /**
     * @var array<string, self::OPERAND_*>
     */
    private const BUILT_IN = [
        self::EQUAL => self::OPERAND_SCALAR,
        '@eqi' => self::OPERAND_TEXT,
        '@ne' => self::OPERAND_SCALAR,
        '@nei' => self::OPERAND_TEXT,
        self::LESS_THAN => self::OPERAND_SCALAR,
        '@lte' => self::OPERAND_SCALAR,
        self::GREATER_THAN => self::OPERAND_SCALAR,
        '@gte' => self::OPERAND_SCALAR,
        '@in' => self::OPERAND_LIST,
        '@notIn' => self::OPERAND_LIST,
        '@contains' => self::OPERAND_TEXT,
        '@notContains' => self::OPERAND_TEXT,
        '@containsi' => self::OPERAND_TEXT,
        '@notContainsi' => self::OPERAND_TEXT,
        '@null' => self::OPERAND_BOOLEAN,
        '@notNull' => self::OPERAND_BOOLEAN,
        '@between' => self::OPERAND_PAIR,
        '@startsWith' => self::OPERAND_TEXT,
        '@startsWithi' => self::OPERAND_TEXT,
        '@endsWith' => self::OPERAND_TEXT,
        '@endsWithi' => self::OPERAND_TEXT,
    ];

    /**
     * whether the string has the shape of an operator name, built-in or custom
     */
    public static function isName(string $name): bool
    {
        return str_starts_with($name, '@') && MemberName::isValid($name);
    }

    /**
     * member names are case sensitive, so `@EQ` is not `@eq`
     */
    public static function isBuiltIn(string $name): bool
    {
        return array_key_exists($name, self::BUILT_IN);
    }

    /**
     * @return self::OPERAND_*
     *
     * @throws InvalidArgumentException when no built-in operator carries the name
     */
    public static function operandShape(string $name): string
    {
        if (!self::isBuiltIn($name)) {
            throw new InvalidArgumentException(sprintf(
                'no built-in operator is named "%s", and only a built-in operator has an operand shape',
                Printable::of($name),
            ));
        }

        return self::BUILT_IN[$name];
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::BUILT_IN);
    }
}
