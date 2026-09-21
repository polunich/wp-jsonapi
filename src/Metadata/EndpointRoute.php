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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;

/**
 * an endpoint route lies outside the layout of `Routing\RouteKind`,
 * so it has no route kind and no target.
 * `$replaces` is the excluded read route whose path and method the endpoint takes,
 * and whose links it then responds to
 *
 * @internal
 */
final readonly class EndpointRoute extends Route
{
    /**
     * @throws InvalidArgumentException when the replaced route is no read
     */
    public function __construct(
        public EndpointMetadata $endpoint,
        public ?OperationRoute $replaces = null,
    ) {
        if ($replaces !== null && !$replaces->operationKind->isFetch()) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" takes the place of a read route alone, and got the %s',
                $endpoint->name,
                $replaces->subject(),
            ));
        }

        parent::__construct($endpoint->template, $endpoint->methods);
    }

    public function subject(): string
    {
        return sprintf('route of the endpoint "%s"', $this->endpoint->name);
    }
}
