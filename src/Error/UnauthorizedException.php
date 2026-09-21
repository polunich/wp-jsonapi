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
use Polunich\WpJsonApi\Authentication\Challenge;
use Throwable;

/**
 * 401 Unauthorized: "the request has not been applied because it lacks valid authentication
 * credentials for the target resource" (RFC 9110 15.5.2).
 *
 * it carries the challenges of its `WWW-Authenticate` field:
 * "The server generating a 401 response MUST send a WWW-Authenticate header field
 * (Section 11.6.1) containing at least one challenge applicable to the target resource" (15.5.2).
 * the list may be empty, but such an exception never leaves as a 401:
 * the library responds to it with the challenges that apply to the request,
 * or with a 403 keeping its codes
 */
final class UnauthorizedException extends JsonApiException
{
    /**
     * @param list<ErrorDescription> $descriptions
     * @param list<Challenge>        $challenges   in the order of the `WWW-Authenticate` field
     *
     * @throws InvalidArgumentException when no description is given
     */
    public function __construct(
        array $descriptions,
        private readonly array $challenges = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(401, $descriptions, $previous);
    }

    /**
     * @return list<Challenge>
     */
    public function challenges(): array
    {
        return $this->challenges;
    }
}
