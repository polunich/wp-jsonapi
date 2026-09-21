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

namespace Polunich\WpJsonApi\Authentication;

/**
 * whether the request comes from an authenticated client: all the library asks about the client.
 *
 * it decides how the library responds to a denial: an authenticated client gets 403,
 * since its credentials are valid but not adequate (RFC 9110 11.4);
 * for any other client `ChallengeProvider` decides.
 * the port carries no user identifier, since nothing the library decides needs one
 */
interface Identity
{
    /**
     * tells about the request being served, so it takes no argument
     */
    public function isAuthenticated(): bool;
}
