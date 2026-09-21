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

namespace Polunich\WpJsonApi\Access;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * an endpoint performs no operation kind and addresses no type of its own,
 * so its permission gets this attempt instead of an `OperationAttempt`
 */
final readonly class EndpointAttempt
{
    /**
     * @param array<string, object>|null $records null before any record is loaded
     *
     * @throws InvalidArgumentException when the name of the endpoint is empty
     */
    private function __construct(
        public string $endpointName,
        public ServerRequest $request,
        public ?array $records,
    ) {
        if ($endpointName === '') {
            throw new InvalidArgumentException('an attempted endpoint is named, and a name is never empty');
        }
    }

    /**
     * level 1: before the query and the body are read and before any record is loaded
     *
     * @throws InvalidArgumentException when the name of the endpoint is empty
     */
    public static function forEndpoint(string $endpointName, ServerRequest $request): self
    {
        return new self($endpointName, $request, null);
    }

    /**
     * level 2: with the record of every parameter the endpoint binds to a type
     *
     * @param array<string, object> $records by the name of the parameter
     *
     * @throws InvalidArgumentException when the name of the endpoint is empty
     */
    public static function forRecords(string $endpointName, ServerRequest $request, array $records): self
    {
        return new self($endpointName, $request, $records);
    }
}
