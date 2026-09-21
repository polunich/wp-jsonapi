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

namespace Polunich\WpJsonApi\Access;

use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * the one place that turns a denial into 401 or 403:
 *
 * - an authenticated client gets 403 `forbidden`: its credentials fall short (RFC 9110 15.5.4);
 * - any other client gets 401 with the challenges the provider returns for its request;
 * - any other client with no challenge that applies gets 403 `authentication_required`,
 *   since a 401 MUST carry at least one challenge applicable to the target resource
 *   (RFC 9110 15.5.2) and the code tells the client what it lacks
 *
 * @internal
 */
final readonly class DenialPolicy
{
    public function __construct(
        private Identity $identity,
        private ChallengeProvider $challenges,
    ) {}

    /**
     * the description names no source:
     * a denial belongs to no document member, query parameter or header
     *
     * @param list<SecurityScheme> $schemes those of the requested operation
     */
    public function deny(ServerRequest $request, array $schemes): JsonApiException
    {
        if ($this->identity->isAuthenticated()) {
            return new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]);
        }

        $challenges = $this->challenges->challenges($request, $schemes);

        if ($challenges === []) {
            return new ForbiddenException([new ErrorDescription(ErrorCode::AuthenticationRequired)]);
        }

        return new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)], $challenges);
    }

    /**
     * the `deny()` rule for a 401 raised elsewhere, so no response is a 401 without a challenge:
     * it gets the provider's challenges, or becomes a 403 that keeps its codes,
     * since a code names the problem and the status does not.
     * any other error passes unchanged, so every error may be routed through here.
     *
     * the identity is not read: a 401 already states that the request lacks valid credentials
     * (RFC 9110 15.5.2), so the one open question is whether a challenge applies
     *
     * @param list<SecurityScheme> $schemes those of the requested operation
     */
    public function rewriteUnauthorized(
        ServerRequest $request,
        array $schemes,
        JsonApiException $error,
    ): JsonApiException {
        if ($error->status() !== 401) {
            return $error;
        }

        if ($error instanceof UnauthorizedException && $error->challenges() !== []) {
            return $error;
        }

        $challenges = $this->challenges->challenges($request, $schemes);

        if ($challenges !== []) {
            return new UnauthorizedException($error->descriptions(), $challenges, $error);
        }

        return new ForbiddenException(array_map(self::forbidden(...), $error->descriptions()), $error);
    }

    /**
     * no error object may state a status its 403 response contradicts
     */
    private static function forbidden(ErrorDescription $description): ErrorDescription
    {
        return $description->status === 401 ? $description->withStatus(403) : $description;
    }
}
