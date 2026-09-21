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

use Polunich\WpJsonApi\Operation\UpdateResource;

/**
 * updates a resource: `PATCH` on the resource route,
 * or the op code `update` with a resource object.
 *
 * a relationship the document carries is replaced by the rules of `RelationshipReplacer`
 *
 * @template TRecord of object
 */
interface ResourceUpdater
{
    /**
     * @return TRecord|Accepted the updated record, or the monitor of an unfinished write
     *
     * @throws ResourceNotFoundException        when the target is gone by the time the write runs
     * @throws RelatedResourceNotFoundException when an identifier of the linkage names a resource
     *                                          that is missing or hidden from the client
     * @throws ConstraintViolationException     when the update breaks a store constraint
     * @throws VersionMismatchException         when the precondition does not admit
     *                                          the stored version of the target
     */
    public function update(UpdateResource $operation): object;
}
