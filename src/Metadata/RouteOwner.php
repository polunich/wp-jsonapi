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

use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the declaration whose routes an exclusion names, and the kinds it may name.
 *
 * a type excludes the routes of its resources, the collection and the resource route.
 * a relationship excludes its own two reads:
 * every relationship has its related and its relationship route,
 * and one type may keep them on one relationship and drop them on another.
 * its writes are no part of an exclusion: a relationship enables each one by declaring the update,
 * and without that declaration the write gets 403 already
 *
 * @internal
 */
enum RouteOwner
{
    case Resource;

    case Relationship;

    /**
     * @return list<OperationKind> in the order of `OperationKind::cases()`
     */
    public function kinds(): array
    {
        $owner = $this;

        return array_values(array_filter(
            OperationKind::cases(),
            static function (OperationKind $kind) use ($owner): bool {
                return $owner === self::Resource
                    ? !$kind->targetsRelationship()
                    : $kind->targetsRelationship() && $kind->isFetch();
            },
        ));
    }

    /**
     * where a kind this declaration may not name is declared instead, for a message
     */
    public function elsewhere(): string
    {
        return match ($this) {
            self::Resource => 'a relationship excludes its own reads with its only() or except(), and enables its'
                . ' writes with replaceable(), addable() or removable()',
            self::Relationship => 'a relationship enables its writes with replaceable(), addable() or removable(),'
                . ' and the routes of the resources are excluded by the only() or except() of their type',
        };
    }
}
