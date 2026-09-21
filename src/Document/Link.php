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
 * one link: an href alone is encoded as a string, anything more as a link object.
 * an empty `hreflang` or `meta` counts as absent, so it keeps the string form
 *
 * @internal
 */
final readonly class Link
{
    /**
     * @param list<string>|string|null     $hreflang
     * @param array<array-key, mixed>|null $meta
     */
    public function __construct(
        public string $href,
        public ?string $rel = null,
        public ?Link $describedby = null,
        public ?string $title = null,
        public ?string $type = null,
        public array|string|null $hreflang = null,
        public ?array $meta = null,
    ) {}

    public function isHrefOnly(): bool
    {
        return $this->rel === null
            && $this->describedby === null
            && $this->title === null
            && $this->type === null
            && ($this->hreflang === null || $this->hreflang === [])
            && ($this->meta === null || $this->meta === []);
    }
}
