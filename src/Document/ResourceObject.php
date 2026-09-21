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
 * a resource object of a response; its attribute values arrive encoded.
 *
 * `id` is required and there is no `lid`:
 * the specification drops the id only for a resource the client sends to be created,
 * and a response never holds one.
 *
 * a `self` link is optional, as the specification has it ("MAY contain a self link"):
 * a resource without a URL of its own links none.
 * the constructor enforces one namespace for attributes, relationships, `type` and `id`,
 * as the specification asks, since the names are runtime data no static analysis sees;
 * a name of digits arrives as an int key, which the key comparison covers
 *
 * @internal
 */
final readonly class ResourceObject
{
    /**
     * @param array<array-key, mixed>              $attributes
     * @param array<array-key, RelationshipObject> $relationships
     * @param array<array-key, mixed>|null         $meta
     */
    public function __construct(
        public string $type,
        public string $id,
        public Links $links,
        public array $attributes = [],
        public array $relationships = [],
        public ?array $meta = null,
    ) {
        $shared = array_keys(array_intersect_key($attributes, $relationships));

        if ($shared !== []) {
            throw new InvalidArgumentException(sprintf(
                'the attributes and the relationships of a resource object share one namespace; the object with'
                . ' type "%s" and id "%s" carries "%s" as an attribute and as a relationship',
                $type,
                $id,
                self::nameList($shared),
            ));
        }

        $reserved = array_keys(array_intersect_key(
            $attributes + $relationships,
            [Member::TYPE => true, Member::ID => true],
        ));

        if ($reserved !== []) {
            throw new InvalidArgumentException(sprintf(
                'the fields of a resource object share their namespace with type and id; the object with type "%s"'
                . ' and id "%s" carries "%s" among its fields',
                $type,
                $id,
                self::nameList($reserved),
            ));
        }
    }

    /**
     * @param non-empty-list<int|string> $names
     */
    private static function nameList(array $names): string
    {
        return implode('", "', array_map(static function (int|string $name): string {
            return (string) $name;
        }, $names));
    }
}
