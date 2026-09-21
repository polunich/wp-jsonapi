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

namespace Polunich\WpJsonApi\Endpoint;

use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;

/**
 * a resource object of a request body, read by the rules of a JSON:API document.
 * an attribute or relationship the object leaves out is absent from its `Values`,
 * never null: `Values::has()` tells the two apart
 */
final readonly class ResourceBody
{
    /**
     * @param ?string         $id            the client-generated id of a new resource,
     *                                       or the id of the bound record the object updates
     * @param Values<mixed>   $attributes
     * @param Values<Linkage> $relationships
     */
    public function __construct(
        public string $type,
        public ?string $id,
        public Values $attributes,
        public Values $relationships,
    ) {}
}
