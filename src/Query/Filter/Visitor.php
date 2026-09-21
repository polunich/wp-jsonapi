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

/**
 * a walk over the filter tree,
 * implemented by the project to translate it into the query its storage speaks;
 * `R` is what the walk produces.
 * one method per kind of node: a new kind breaks every implementation loudly
 * instead of being dropped by a translation unnoticed
 *
 * @template R
 */
interface Visitor
{
    /**
     * @return R
     */
    public function condition(Condition $node): mixed;

    /**
     * @return R
     */
    public function allOf(AllOf $node): mixed;

    /**
     * @return R
     */
    public function anyOf(AnyOf $node): mixed;

    /**
     * @return R
     */
    public function not(Not $node): mixed;

    /**
     * @return R
     */
    public function relationshipCondition(RelationshipCondition $node): mixed;
}
