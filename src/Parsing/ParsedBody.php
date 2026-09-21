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

use Polunich\WpJsonApi\Endpoint\BytesBody;
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\MetaBody;
use Polunich\WpJsonApi\Endpoint\ResourceBody as EndpointResourceBody;
use Polunich\WpJsonApi\Operation\Linkage;

/**
 * the body an endpoint read, and the media type of the body declaration that read it,
 * as the endpoint declares it; both null where the endpoint reads no body
 *
 * @internal
 */
final readonly class ParsedBody
{
    public function __construct(
        public EndpointResourceBody|Linkage|MetaBody|JsonBody|FormBody|BytesBody|null $value,
        public ?string $mediaType,
    ) {}
}
