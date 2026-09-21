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
 * reads a collection of records of one type: the collection route
 * and, scoped by `$query->parent`, the related and relationship routes of a to-many relationship.
 *
 * leave out the records the client may not see inside the storage query, not afterwards:
 * filtering a page after the read leaves it short of the page size.
 * a relationship read here must match `RelatedLoader` for the same relationship,
 * in order and in the records it hides,
 * so the first page of the relationship route equals the linkage a document renders
 *
 * @template TRecord of object
 */
interface CollectionReader
{
    /**
     * the records of `$query->window` in the order of `$query->sort`,
     * plus the size of the collection when `$query->totalRequested` asks for it.
     * the limit of the window counts the record past the page: return it,
     * and never more than the limit, or the request fails with 500
     *
     * @return Slice<TRecord>
     */
    public function read(CollectionQuery $query): Slice;
}
