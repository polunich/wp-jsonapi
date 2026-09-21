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

namespace Polunich\WpJsonApi\Execution;

use Polunich\WpJsonApi\Execution\Result\ResourceRecord;

/**
 * a record on one level of the include walk, with the rest of every include path
 * that continues from it, keyed by the place of the path in the request
 *
 * @internal
 */
final readonly class LevelEntry
{
    /**
     * @param array<int, non-empty-list<string>> $paths
     */
    public function __construct(
        public ResourceRecord $resource,
        public array $paths,
    ) {}
}
