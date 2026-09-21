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

use Override;

/**
 * `@not`: the child does not match.
 * `filter[@not][name][@eq]=Rex` and `filter[name][@not][@eq]=Rex` both arrive as this node,
 * so a visitor handles one form
 */
final readonly class Not implements FilterNode
{
    public function __construct(public FilterNode $node) {}

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
        return $visitor->not($this);
    }
}
