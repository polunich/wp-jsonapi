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

namespace Polunich\WpJsonApi\WordPress;

use Closure;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\ServerRequest;

/**
 * a Basic challenge for WordPress Application Passwords, given only when all three hold:
 *
 * - the requested operation accepts `SecurityScheme::applicationPasswords()`;
 * - `wp_is_application_passwords_available()`: true over HTTPS or in a local environment,
 *   unless a filter says otherwise;
 * - the request carries Basic credentials: `$_SERVER` has both `PHP_AUTH_USER` and `PHP_AUTH_PW`,
 *   the pair WordPress requires before it tries an application password.
 *   WordPress fills them from an `Authorization` field the server passed through.
 *
 * the challenge is built in the constructor,
 * so a realm no field can carry fails when the provider is built, not on a denied request.
 * `$available` and `$serverParameters` are asked per challenge, after WordPress read the request
 *
 * @internal
 */
final readonly class ApplicationPasswordsChallengeProvider implements ChallengeProvider
{
    public const DEFAULT_REALM = 'WordPress';

    private Challenge $challenge;

    /**
     * @param Closure(): bool                    $available
     * @param Closure(): array<array-key, mixed> $serverParameters
     *
     * @throws InvalidArgumentException when no header field can carry the realm
     */
    public function __construct(
        string $realm,
        private Closure $available,
        private Closure $serverParameters,
    ) {
        $this->challenge = new Challenge('Basic', ['realm' => $realm]);
    }

    /**
     * @return list<Challenge>
     */
    #[Override]
    public function challenges(ServerRequest $request, array $schemes): array
    {
        if (!in_array(SecurityScheme::applicationPasswords(), $schemes, false)) {
            return [];
        }

        $server = ($this->serverParameters)();

        if (!isset($server['PHP_AUTH_USER'], $server['PHP_AUTH_PW']) || !($this->available)()) {
            return [];
        }

        return [$this->challenge];
    }
}
