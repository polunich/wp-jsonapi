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

namespace Polunich\WpJsonApi\Operation;

use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Routing\RouteKind;

/**
 * what an operation does: the ten kinds every request becomes. the case values are public API.
 *
 * `route()`, `method()`, `methods()` and `onRoute()` hold the routing table of the kinds,
 * internal because the routing is the library's own
 */
enum OperationKind: string
{
    /** `GET` on the collection route */
    case FetchCollection = 'fetch_collection';

    /** `GET` on the resource route */
    case FetchResource = 'fetch_resource';

    /** `GET` on the related route */
    case FetchRelated = 'fetch_related';

    /** `GET` on the relationship route */
    case FetchRelationship = 'fetch_relationship';

    /** `POST` on the collection route, or an atomic `add` of a resource object */
    case CreateResource = 'create_resource';

    /** `PATCH` on the resource route, or an atomic `update` of a resource object */
    case UpdateResource = 'update_resource';

    /** `DELETE` on the resource route, or an atomic `remove` of a resource */
    case DeleteResource = 'delete_resource';

    /** `PATCH` on the relationship route, or an atomic `update` of a relationship */
    case ReplaceRelationship = 'replace_relationship';

    /** `POST` on the relationship route of a to-many relationship, or an atomic `add` to it */
    case AddToRelationship = 'add_to_relationship';

    /**
     * `DELETE` on the relationship route of a to-many relationship, or an atomic `remove` from it
     */
    case RemoveFromRelationship = 'remove_from_relationship';

    public function isFetch(): bool
    {
        return match ($this) {
            self::FetchCollection,
            self::FetchResource,
            self::FetchRelated,
            self::FetchRelationship => true,
            self::CreateResource,
            self::UpdateResource,
            self::DeleteResource,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => false,
        };
    }

    public function targetsRelationship(): bool
    {
        return match ($this) {
            self::FetchRelated,
            self::FetchRelationship,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => true,
            self::FetchCollection,
            self::FetchResource,
            self::CreateResource,
            self::UpdateResource,
            self::DeleteResource => false,
        };
    }

    /**
     * whether the target or the parent exists before the operation runs: not for `FetchCollection`,
     * named by its type alone, nor for `CreateResource`, whose resource has no record yet
     */
    public function actsOnExistingResource(): bool
    {
        return match ($this) {
            self::FetchResource,
            self::FetchRelated,
            self::FetchRelationship,
            self::UpdateResource,
            self::DeleteResource,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => true,
            self::FetchCollection,
            self::CreateResource => false,
        };
    }

    /**
     * a write on an existing resource, whose operation is a `ConditionalWrite`:
     * a fetch only reads, and a create makes a resource with no version yet
     */
    public function isConditionalWrite(): bool
    {
        return match ($this) {
            self::UpdateResource,
            self::DeleteResource,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => true,
            self::FetchCollection,
            self::FetchResource,
            self::FetchRelated,
            self::FetchRelationship,
            self::CreateResource => false,
        };
    }

    /**
     * @internal
     */
    public function route(): RouteKind
    {
        return match ($this) {
            self::FetchCollection, self::CreateResource => RouteKind::Collection,
            self::FetchResource, self::UpdateResource, self::DeleteResource => RouteKind::Resource,
            self::FetchRelated => RouteKind::Related,
            self::FetchRelationship,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => RouteKind::Relationship,
        };
    }

    /**
     * @internal
     */
    public function method(): string
    {
        return match ($this) {
            self::FetchCollection, self::FetchResource, self::FetchRelated, self::FetchRelationship => Method::GET,
            self::CreateResource, self::AddToRelationship => Method::POST,
            self::UpdateResource, self::ReplaceRelationship => Method::PATCH,
            self::DeleteResource, self::RemoveFromRelationship => Method::DELETE,
        };
    }

    /**
     * @internal
     *
     * @return non-empty-list<string>
     */
    public function methods(): array
    {
        return Method::served($this->method());
    }

    /**
     * the kind that responds to `$method` on `$route`, null for none;
     * no two kinds share a route and a method
     *
     * @internal
     *
     * @param string $method compared case-sensitively, as a method token is (RFC 9110 9.1)
     */
    public static function onRoute(RouteKind $route, string $method): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->route() === $route && in_array($method, $kind->methods(), true)) {
                return $kind;
            }
        }

        return null;
    }

    public function carriesContent(): bool
    {
        return match ($this) {
            self::CreateResource,
            self::UpdateResource,
            self::ReplaceRelationship,
            self::AddToRelationship,
            self::RemoveFromRelationship => true,
            self::FetchCollection,
            self::FetchResource,
            self::FetchRelated,
            self::FetchRelationship,
            self::DeleteResource => false,
        };
    }
}
