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

use Polunich\WpJsonApi\Operation\CreateResource;

/**
 * creates a resource: `POST` on the collection route, or the op code `add` with a resource object
 *
 * @template TRecord of object
 */
interface ResourceCreator
{
    /**
     * @return TRecord|Accepted the created record, or the monitor of an unfinished write
     *
     * @throws RelatedResourceNotFoundException when an identifier of the linkage names a resource
     *                                          that is missing or hidden from the client
     * @throws ResourceExistsException          when the client-generated id is taken
     * @throws ConstraintViolationException     when the resource breaks a store constraint
     */
    public function create(CreateResource $operation): object;
}
