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
 * the constructor requires what the specification does: "at least one of" links, data and meta,
 * where links hold a `self` or a `related` link (Relationships).
 * a links object holding only pagination links carries neither, so it is refused.
 * an empty meta object is not written, so it counts for nothing.
 *
 * absent linkage says nothing about the related resources, empty linkage says there are none
 *
 * @internal
 */
final readonly class RelationshipObject
{
    /**
     * @param ResourceIdentifierObject|list<ResourceIdentifierObject>|null $linkage
     * @param array<array-key, mixed>|null                     $meta
     */
    private function __construct(
        public Links $links,
        private bool $hasLinkage,
        private ResourceIdentifierObject|array|null $linkage,
        public ?array $meta,
    ) {
        $linked = $links->has(Member::SELF) || $links->has(Member::RELATED);

        if (!$linked && !$links->isEmpty()) {
            throw new InvalidArgumentException(
                'the links of a relationship object carry a self or a related link wherever they carry any',
            );
        }

        if (!$linked && !$hasLinkage && ($meta === null || $meta === [])) {
            throw new InvalidArgumentException(
                'a relationship object carries at least one of links, data and meta; this one carries none',
            );
        }
    }

    /**
     * @param array<array-key, mixed>|null $meta
     */
    public static function withoutLinkage(Links $links, ?array $meta = null): self
    {
        return new self($links, false, null, $meta);
    }

    /**
     * null `$data` is an empty relationship, not absent linkage
     *
     * @param array<array-key, mixed>|null $meta
     */
    public static function toOne(Links $links, ?ResourceIdentifierObject $data, ?array $meta = null): self
    {
        return new self($links, true, $data, $meta);
    }

    /**
     * @param list<ResourceIdentifierObject>     $data
     * @param array<array-key, mixed>|null $meta
     */
    public static function toMany(Links $links, array $data, ?array $meta = null): self
    {
        return new self($links, true, $data, $meta);
    }

    public function hasLinkage(): bool
    {
        return $this->hasLinkage;
    }

    /**
     * @return ResourceIdentifierObject|list<ResourceIdentifierObject>|null
     */
    public function linkage(): ResourceIdentifierObject|array|null
    {
        return $this->linkage;
    }
}
