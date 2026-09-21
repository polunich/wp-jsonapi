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

namespace Polunich\WpJsonApi\Error;

use InvalidArgumentException;
use Throwable;

/**
 * 412 Precondition Failed: "one or more conditions given in the request header fields
 * evaluated to false when tested on the server" (RFC 9110 15.5.13)
 */
final class PreconditionFailedException extends JsonApiException
{
    /**
     * @param list<ErrorDescription> $descriptions
     *
     * @throws InvalidArgumentException when no description is given
     */
    public function __construct(array $descriptions, ?Throwable $previous = null)
    {
        parent::__construct(412, $descriptions, $previous);
    }
}
