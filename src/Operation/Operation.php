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

namespace Polunich\WpJsonApi\Operation;

/**
 * one request as the library executes it:
 * a single request and each atomic operation become the same immutable operation classes.
 *
 * `type()`, also exposed as `public string $type`, is the type of the collection, the resource,
 * or the parent whose relationship the operation reads or writes.
 * an operation on an existing resource carries `Identifier $target`,
 * one on a relationship carries `Identifier $parent` and `string $relationship`.
 *
 * every operation but `DeleteResource` carries `$include` and `$fields`:
 * the spec applies both to any response with primary data, whatever the request type.
 * `$include` is null when the request has no include parameter:
 * a supplied one, even empty, gives the response an `included` member.
 *
 * every operation carries `Values $queryParameters`:
 * the query parameters the route of its kind declares, decoded by their codecs,
 * absent where the request leaves one out.
 * an atomic operation carries none, as its operation object has no member for them.
 *
 * an operation checks structural invariants only,
 * such as a non-empty type, id, relationship or member name.
 * a violation throws `InvalidArgumentException`, a caller bug and never a client error
 */
interface Operation
{
    public function kind(): OperationKind;

    public function type(): string;

    /**
     * null unless the kind `targetsRelationship()`,
     * whose classes narrow it to `string` and expose it as `public string $relationship`
     */
    public function relationship(): ?string;
}
