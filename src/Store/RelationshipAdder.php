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

use Polunich\WpJsonApi\Operation\AddToRelationship;

/**
 * adds members to a to-many relationship:
 * `POST` on the relationship route, or the op code `add` on a relationship.
 *
 * an identifier of a member already in the relationship is skipped, and the addition succeeds.
 * an identifier of a record the client may not see is `RelatedResourceNotFoundException`
 * even when that record is a member already: success would tell the client that it exists
 */
interface RelationshipAdder
{
    /**
     * @return Accepted|null null when the members are added, or the monitor of an unfinished write
     *
     * @throws ResourceNotFoundException        when the parent is gone by the time the write runs
     * @throws RelatedResourceNotFoundException when an identifier of the linkage names a resource
     *                                          that is missing or hidden from the client
     * @throws ConstraintViolationException     when the addition breaks a store constraint
     * @throws VersionMismatchException         when the precondition does not admit
     *                                          the stored version of the parent
     */
    public function add(AddToRelationship $operation): ?Accepted;
}
