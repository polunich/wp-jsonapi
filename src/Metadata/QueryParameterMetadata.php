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
use Polunich\WpJsonApi\Codec\Codec;

/**
 * a query parameter an endpoint or a generated route declares,
 * named by the base name of its family under the rule of `CustomQueryParameterName`
 *
 * @internal
 */
final readonly class QueryParameterMetadata
{
    /**
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the name is no legal member name,
     *                                  names a family of the specification,
     *                                  or is one WordPress reads
     */
    public function __construct(
        public string $name,
        public Codec $codec,
        public bool $required,
    ) {
        CustomQueryParameterName::check($name);
    }
}
