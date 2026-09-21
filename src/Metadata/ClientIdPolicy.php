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

namespace Polunich\WpJsonApi\Metadata;

/**
 * whether a create may carry a client-generated id:
 *
 * - `Forbidden`: a create with an id gets 403,
 *   as the spec requires of a server that does not support client-generated ids;
 * - `Allowed`: a create may send an id or leave it to the store;
 * - `Required`: a create without an id gets 422
 *
 * @internal
 */
enum ClientIdPolicy: string
{
    case Forbidden = 'forbidden';

    case Allowed = 'allowed';

    case Required = 'required';
}
