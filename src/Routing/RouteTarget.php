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

namespace Polunich\WpJsonApi\Routing;

use InvalidArgumentException;

/**
 * a member the kind does not address is null, never `''`:
 * a lookup or a URL would take an empty string for a member unnoticed
 *
 * @internal
 */
final readonly class RouteTarget
{
    /**
     * @throws InvalidArgumentException when an addressed member is missing or empty,
     *                                  or an unaddressed one is given
     */
    public function __construct(
        public RouteKind $kind,
        public ?string $type,
        public ?string $id,
        public ?string $relationship,
    ) {
        $this->kind->checkMembers($this->type, $this->id, $this->relationship);
    }
}
