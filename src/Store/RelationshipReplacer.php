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

use Polunich\WpJsonApi\Operation\ReplaceRelationship;

/**
 * replaces the whole linkage of a relationship:
 * `PATCH` on the relationship route, or the op code `update` on a relationship.
 *
 * a to-many replacement keeps the members the client may not see:
 * the linkage sent replaces only the members the client may see,
 * so the client reads back exactly what it sent.
 * hidden members never make the replacement a 403, which would reveal that they exist,
 * and an identifier of a record the client may not see is `RelatedResourceNotFoundException`.
 * a member the client may see but has not read, such as one past the page it fetched,
 * is replaced like any other
 */
interface RelationshipReplacer
{
    /**
     * @return Accepted|null null when the relationship is replaced,
     *                       or the monitor of an unfinished write
     *
     * @throws ResourceNotFoundException        when the parent is gone by the time the write runs
     * @throws RelatedResourceNotFoundException when an identifier of the linkage names a resource
     *                                          that is missing or hidden from the client
     * @throws ConstraintViolationException     when the replacement breaks a store constraint
     * @throws VersionMismatchException         when the precondition does not admit
     *                                          the stored version of the parent
     */
    public function replace(ReplaceRelationship $operation): ?Accepted;
}
