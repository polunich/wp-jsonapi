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

namespace Polunich\WpJsonApi\OpenApi;

use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use stdClass;

/**
 * builds the tags of the contract: one per type, then the tags the endpoints name,
 * then the tags the atomic routes name, and the tag of the extension
 * where an atomic route of several types names none;
 * a name is listed once, so an endpoint or a route that names a type or the atomic tag shares it
 *
 * @internal
 */
final readonly class TagBuilder
{
    public function __construct(private ApiMetadata $api) {}

    /**
     * @return list<stdClass>
     */
    public function tags(): array
    {
        // keyed by name: OpenAPI 3.1.2, 4.8.1, "Each tag name in the list MUST be unique."
        $tags = [];

        foreach ($this->api->resources->all() as $resource) {
            $tag = ['name' => $resource->type];

            if ($resource->description !== null) {
                $tag['description'] = $resource->description;
            }

            $tags[$resource->type] = (object) $tag;
        }

        foreach ($this->api->endpoints as $endpoint) {
            if ($endpoint->tag !== null) {
                $tags[$endpoint->tag] ??= (object) ['name' => $endpoint->tag];
            }
        }

        foreach ($this->api->atomicRoutes as $route) {
            $tag = self::atomicTag($route);

            if ($tag === Member::ATOMIC_OPERATIONS) {
                // no type is named so, since a member name holds no colon;
                // an endpoint's bare tag of that name gets the description of the extension
                $tags[$tag] = (object) [
                    'name' => $tag,
                    'description' => 'the operations of one atomic request, performed in order and all or nothing'
                        . ' [ext-atomic]',
                ];
            } else {
                // listed above already where it names a type or the tag of an endpoint
                $tags[$tag] ??= (object) ['name' => $tag];
            }
        }

        return array_values($tags);
    }

    public static function atomicTag(AtomicRouteMetadata $route): string
    {
        return $route->tag ?? (count($route->types) === 1 ? $route->types[0] : Member::ATOMIC_OPERATIONS);
    }
}
