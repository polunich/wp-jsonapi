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

namespace Polunich\WpJsonApi\Execution\Result;

use Polunich\WpJsonApi\Http\EntityTag;

/**
 * what one operation or endpoint produced.
 *
 * the status is carried, not derived from the operation kind:
 * a create gets 201 or 202, a delete 204 or 202,
 * and a fetch whose `If-None-Match` condition is false gets 304
 *
 * @internal
 */
interface Result
{
    public function status(): int;

    public function entityTag(): ?EntityTag;
}
