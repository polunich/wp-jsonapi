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

use InvalidArgumentException;

/**
 * the top-level `self` link is optional, as the specification has it ("MAY contain"):
 * a document links only a URL that responds to GET with data, and the URL of a write may not.
 *
 * a compound document holds one resource object per type and id, the primary data included.
 * the primary data is the result and is never altered, so a pair repeated in it is refused;
 * `included` is derived from it, so a resource the primary data already carries is dropped there.
 * resource identifier objects count for neither rule
 *
 * @internal
 */
final readonly class DataDocument
{
    /**
     * null when the document writes no `included` member
     */
    public ?Included $included;

    /**
     * @param (ResourceIdentifierObject|ResourceObject
     *     |list<ResourceIdentifierObject>|list<ResourceObject>|null) $data
     * @param array<array-key, mixed>|null $meta
     */
    public function __construct(
        public ResourceIdentifierObject|ResourceObject|array|null $data,
        public JsonApiObject $jsonApi,
        public Links $links,
        ?Included $included = null,
        public ?array $meta = null,
    ) {
        $primary = [];

        foreach (self::resourceObjects($data) as $resource) {
            if (isset($primary[$resource->type][$resource->id])) {
                throw new InvalidArgumentException(sprintf(
                    'a compound document carries one resource object per type and id; the primary data carries'
                    . ' type "%s" and id "%s" twice',
                    $resource->type,
                    $resource->id,
                ));
            }

            $primary[$resource->type][$resource->id] = true;
        }

        if ($included === null) {
            $this->included = null;

            return;
        }

        $rest = [];

        foreach ($included->all() as $resource) {
            if (!isset($primary[$resource->type][$resource->id])) {
                $rest[] = $resource;
            }
        }

        $this->included = new Included($rest);
    }

    /**
     * @param (ResourceIdentifierObject|ResourceObject
     *     |list<ResourceIdentifierObject>|list<ResourceObject>|null) $data
     *
     * @return list<ResourceObject>
     */
    private static function resourceObjects(ResourceIdentifierObject|ResourceObject|array|null $data): array
    {
        if ($data instanceof ResourceObject) {
            return [$data];
        }

        if (!is_array($data)) {
            return [];
        }

        $resources = [];

        foreach ($data as $item) {
            if ($item instanceof ResourceObject) {
                $resources[] = $item;
            }
        }

        return $resources;
    }
}
