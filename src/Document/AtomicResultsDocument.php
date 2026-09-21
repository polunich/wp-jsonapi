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
 * the `atomic:results` document of an atomic operations request.
 *
 * the extension forbids `data` and `included` here
 * and asks for one result per operation, in operation order, so the list is never empty.
 *
 * a result is a resource object as `data`, or null for a result without members, encoded as `{}`.
 * the extension asks nothing else of a server: an add or an update may return the resource,
 * a remove or a relationship update "MUST return a result with no data".
 * the results are no array shape, since PHPStan accepts an unnamed member in an open shape
 * and the encoder would drop it silently
 *
 * @internal
 */
final readonly class AtomicResultsDocument
{
    /**
     * @param non-empty-list<ResourceObject|null> $results
     * @param JsonApiObject                       $jsonApi carries the extension URI in `ext`
     * @param array<array-key, mixed>|null        $meta
     */
    public function __construct(
        public array $results,
        public JsonApiObject $jsonApi,
        public ?array $meta = null,
    ) {}
}
