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
 * loads records of one type by id in one call.
 *
 * a list of another length, or an entry that is neither a record nor null,
 * fails the request with 500
 *
 * @template TRecord of object
 */
interface ResourceLoader
{
    /**
     * @return list<TRecord|null> same length and order as `$request->ids`;
     *                            null for a record that is missing or hidden from the client,
     *                            so the client cannot tell the two apart
     */
    public function load(LoadRequest $request): array;
}
