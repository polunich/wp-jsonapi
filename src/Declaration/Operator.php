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

namespace Polunich\WpJsonApi\Declaration;

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;

/**
 * a custom filter operator of the project.
 * `make()` checks the name at once.
 * an attribute accepts it by name through `Attribute::filter()`, as it accepts a built-in operator
 */
final readonly class Operator
{
    private function __construct(private OperatorMetadata $operator) {}

    /**
     * a client writes the name as declared, so it must not be the name of a built-in operator
     * as the naming policy spells it for the client, `@not_in` under snake case:
     * the API build refuses that, since a filter could not tell the two apart
     *
     * @param string       $name  `@` and a member name no built-in operator carries
     * @param Codec<mixed> $codec decodes the condition value, whatever field the condition is on
     *
     * @throws InvalidArgumentException when the name is no `@` followed by a member name,
     *                                  or a built-in operator carries it
     */
    public static function make(string $name, Codec $codec): self
    {
        return new self(OperatorMetadata::custom($name, $codec));
    }

    /**
     * @internal
     */
    public function build(): OperatorMetadata
    {
        return $this->operator;
    }
}
