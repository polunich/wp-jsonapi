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

use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * a declared query parameter, kept with its owner so a check across the API can name it
 *
 * @internal
 */
final readonly class DeclaredQueryParameter
{
    /**
     * @param string         $declaration what declares it, such as `type "pets"`,
     *                                    `relationship "orders" of type "pets"`
     *                                    or `the endpoint "adoptPet"`
     * @param ?OperationKind $kind        the kind of the route that reads it, null for an endpoint
     */
    public function __construct(
        public QueryParameterMetadata $parameter,
        public string $declaration,
        public ?OperationKind $kind,
    ) {}

    /**
     * where the parameter is read: `the fetch_collection route of type "pets"`, or the endpoint
     */
    public function place(): string
    {
        return $this->kind === null
            ? $this->declaration
            : sprintf('the %s route of %s', $this->kind->value, $this->declaration);
    }
}
