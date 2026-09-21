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
use Override;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the leaf of the filter tree: a field compared by one operator, `filter[name][@eq]=Rex`.
 * the operator stays a name, so a custom operator reaches the visitor the way a built-in one does;
 * whether the field admits the operator is not checked here
 */
final readonly class Condition implements FilterNode
{
    /**
     * @param mixed $value decoded by the operator's value codec: for a built-in operator
     *                     in the shape `Operators::operandShape()` names,
     *                     for a custom one in the shape its own codec returns
     *
     * @throws InvalidArgumentException when the string can name no operator
     */
    public function __construct(
        public FieldPath $field,
        public string $operator,
        public mixed $value,
    ) {
        if (!Operators::isName($operator)) {
            throw new InvalidArgumentException(sprintf(
                'operator "%s" is no operator name: an operator is "@" and a member name',
                Printable::of($operator),
            ));
        }
    }

    /**
     * @template R
     *
     * @param Visitor<R> $visitor
     *
     * @return R
     */
    #[Override]
    public function accept(Visitor $visitor): mixed
    {
        return $visitor->condition($this);
    }
}
