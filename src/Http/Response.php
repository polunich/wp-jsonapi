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

namespace Polunich\WpJsonApi\Http;

use InvalidArgumentException;

/**
 * `$document` is the document as an array in member order, or null for no document, as a 204 has.
 * the top level may be an array: a document always has members,
 * so `json_encode()` writes an object.
 * inside it every object is a `stdClass`, since an array would render an empty object as `[]`
 * and one with the keys 0, 1, ... as a JSON array.
 *
 * `$content` is the body of a response in a media type other than JSON:API
 *
 * @internal
 */
final readonly class Response
{
    /**
     * @param array<string, mixed>|null $document
     *
     * @throws InvalidArgumentException when both a document and a content are given
     */
    public function __construct(
        public int $status,
        public Headers $headers,
        public ?array $document,
        public ?Content $content = null,
    ) {
        if ($document !== null && $content !== null) {
            throw new InvalidArgumentException('a response carries a document or a content, and this one carries'
                . ' both');
        }
    }
}
