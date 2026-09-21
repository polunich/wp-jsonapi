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

use Polunich\WpJsonApi\Operation\RemoveFromRelationship;

/**
 * removes members from a to-many relationship:
 * `DELETE` on the relationship route, or the op code `remove` on a relationship.
 *
 * an identifier that names no member is skipped, and the removal succeeds, as the spec requires;
 * the remover therefore never throws `RelatedResourceNotFoundException`.
 * a member the client may not see is skipped the same way and stays in the relationship:
 * removing it may write the related record, which the client may not access,
 * and a 403 would tell the client that it is a member
 */
interface RelationshipRemover
{
    /**
     * @return Accepted|null null when the members are removed,
     *                       or the monitor of an unfinished write
     *
     * @throws ResourceNotFoundException    when the parent is gone by the time the write runs
     * @throws ConstraintViolationException when the removal breaks a store constraint
     * @throws VersionMismatchException     when the precondition does not admit
     *                                      the stored version of the parent
     */
    public function remove(RemoveFromRelationship $operation): ?Accepted;
}
