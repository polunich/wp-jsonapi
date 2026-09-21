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
 * a document with top-level `meta` and no primary data.
 * its meta object is written even when empty:
 * a document "MUST contain at least one of the following top-level members" (JSON:API, Top Level),
 * and `meta` is the only one this document has.
 * it has no top-level links, for the reason `DataDocument` gives for `self`
 *
 * @internal
 */
final readonly class MetaDocument
{
    /**
     * @param array<array-key, mixed> $meta
     */
    public function __construct(
        public array $meta,
        public JsonApiObject $jsonApi,
    ) {}
}
