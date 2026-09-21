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

/**
 * loads the related records of one relationship for many parents in one call:
 * those of a to-many relationship, and those of a to-one relationship
 * whose parent record does not name the related id.
 *
 * a list of another length, an entry that is not a list of records,
 * or more records for a parent than `$request->limit` fail the request with 500.
 * a to-many relationship read here must match `CollectionReader` for the same relationship,
 * in order and in the records it hides,
 * so the linkage a document renders equals the first page of the relationship route
 *
 * @template TRecord of object
 */
interface RelatedLoader
{
    /**
     * @return list<list<TRecord>> same length and order as `$request->parentIds`;
     *                             for each parent at most `$request->limit` records
     *                             in `$request->sort`, leaving out those the client may not see
     */
    public function loadRelated(RelatedLoadRequest $request): array;
}
