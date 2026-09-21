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
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a condition on the resources a relationship points at, `filter[owner][name][@eq]=Ada`.
 * a to-one relationship matches when its resource matches,
 * a to-many one when at least one of its resources does;
 * the node does not say which of the two the relationship is.
 * a path through several relationships nests one node per relationship
 */
final readonly class RelationshipCondition implements FilterNode
{
    /**
     * @throws InvalidArgumentException when the relationship name is no member name
     */
    public function __construct(
        public string $relationship,
        public FilterNode $condition,
    ) {
        if (!MemberName::isValid($relationship)) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" is no member name, so no relationship carries it',
                Printable::of($relationship),
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
        return $visitor->relationshipCondition($this);
    }
}
