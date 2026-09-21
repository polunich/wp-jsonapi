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

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Text\Printable;

/**
 * an entry of the operator table of an API.
 * `valueCodec()` is the one place that decides which codec reads the value of a condition
 *
 * @internal
 */
final readonly class OperatorMetadata
{
    /**
     * @param ?Codec<mixed> $codec null for a built-in operator
     */
    private function __construct(
        public string $name,
        private ?Codec $codec,
    ) {}

    /**
     * @throws InvalidArgumentException when no built-in operator carries the name
     */
    public static function builtIn(string $name): self
    {
        if (!Operators::isBuiltIn($name)) {
            throw new InvalidArgumentException(sprintf(
                'no built-in operator is named "%s"',
                Printable::of($name),
            ));
        }

        return new self($name, null);
    }

    /**
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the name is not `@` followed by a member name,
     *                                  or is the name of a built-in operator
     */
    public static function custom(string $name, Codec $codec): self
    {
        if (!Operators::isName($name)) {
            throw new InvalidArgumentException(sprintf(
                'custom operator "%s" is no "@" followed by a member name',
                Printable::of($name),
            ));
        }

        if (Operators::isBuiltIn($name)) {
            throw new InvalidArgumentException(sprintf(
                'custom operator "%s" collides with the built-in operator of that name',
                $name,
            ));
        }

        return new self($name, $codec);
    }

    public function isCustom(): bool
    {
        return $this->codec !== null;
    }

    /**
     * a text operator reads any string: the field codec may refuse a fragment or a case variant.
     * `@between` reads its two bounds in either order:
     * no JSON Schema keyword can order two items,
     * and `decode()` enforces only what `schema()` writes
     *
     * @param Codec<mixed> $field the codec of the attribute the condition is on
     *
     * @return Codec<mixed>
     */
    public function valueCodec(Codec $field): Codec
    {
        if ($this->codec !== null) {
            return $this->codec;
        }

        return match (Operators::operandShape($this->name)) {
            Operators::OPERAND_SCALAR => $field,
            Operators::OPERAND_TEXT => new StringCodec(),
            Operators::OPERAND_LIST => new ListCodec($field),
            Operators::OPERAND_BOOLEAN => new BooleanCodec(),
            Operators::OPERAND_PAIR => new ListCodec($field, 2, 2),
        };
    }
}
