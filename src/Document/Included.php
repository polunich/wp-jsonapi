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

namespace Polunich\WpJsonApi\Document;

/**
 * the included resource objects of a compound document, one per type and id, in insertion order.
 *
 * two include paths may reach one resource; of the two objects the first wins.
 * an instance, even an empty one, means the document writes `included`
 *
 * @internal
 */
final readonly class Included
{
    /**
     * @var list<ResourceObject>
     */
    private array $resources;

    /**
     * @param list<ResourceObject> $resources
     */
    public function __construct(array $resources = [])
    {
        $seen = [];
        $unique = [];

        foreach ($resources as $resource) {
            if (isset($seen[$resource->type][$resource->id])) {
                continue;
            }

            $seen[$resource->type][$resource->id] = true;
            $unique[] = $resource;
        }

        $this->resources = $unique;
    }

    /**
     * @return list<ResourceObject>
     */
    public function all(): array
    {
        return $this->resources;
    }
}
