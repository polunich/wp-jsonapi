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

namespace Polunich\WpJsonApi\Parsing;

use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;

/**
 * `$queryParameters` holds only the declared parameters the request carries;
 * `$include` is null without `include`;
 * `$reading` is null unless the endpoint responds with a page
 *
 * @internal
 */
final readonly class EndpointQuery
{
    /**
     * @param Values<mixed> $queryParameters
     */
    public function __construct(
        public Values $queryParameters,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public ?CollectionReading $reading,
    ) {}
}
