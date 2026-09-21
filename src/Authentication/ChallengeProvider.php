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

use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * the extension point that picks the challenges of a 401.
 *
 * a denied client that is not authenticated gets 401 with the challenges returned for its request,
 * or 403 `authentication_required` when the list is empty.
 *
 * the challenges are per request,
 * since a 401 carries a challenge "applicable to the requested resource" (RFC 9110 11.3):
 * a scheme the client cannot use applies to nothing,
 * and a Basic challenge sent to a browser page script opens a login dialog instead of a 403
 */
interface ChallengeProvider
{
    /**
     * the challenges in the order they are written.
     * RFC 9110 11.3 advises listing a well-supported scheme such as Basic first:
     * "Many clients fail to parse a challenge that contains an unknown scheme"
     *
     * @param list<SecurityScheme> $schemes the schemes the contract declares for the requested
     *                                      operation: its own, else those of the API
     *
     * @return list<Challenge>
     */
    public function challenges(ServerRequest $request, array $schemes): array;
}
