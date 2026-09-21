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

namespace Polunich\WpJsonApi\Access;

/**
 * the port a project implements to decide whether a client may perform an operation.
 *
 * a declaration binds one to every kind a resource enables.
 * one implementation decides at both levels, told apart by `$attempt->record`:
 *
 * - null, before the query and the body of the request are read;
 * - the loaded target or parent, where `OperationKind::actsOnExistingResource()` is true.
 *
 * deny by returning false, never by throwing: `DenialPolicy` turns a denial into 401 or 403.
 * a permission has no side effect, since one request may ask it more than once
 */
interface OperationPermission
{
    public function allows(OperationAttempt $attempt): bool;
}
