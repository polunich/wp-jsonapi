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
use Override;
use Polunich\WpJsonApi\Authentication\Identity;

/**
 * reads the current user on every call, never ahead:
 * WordPress may still change it up to the dispatch,
 * as it treats a cookie sent without a nonce as no user.
 * `is_user_logged_in()` is pluggable and defined only once `pluggable.php` loads, hence the closure
 *
 * @internal
 */
final readonly class CurrentUserIdentity implements Identity
{
    /**
     * @param Closure(): bool $isUserLoggedIn `is_user_logged_in()`
     */
    public function __construct(private Closure $isUserLoggedIn) {}

    #[Override]
    public function isAuthenticated(): bool
    {
        return ($this->isUserLoggedIn)();
    }
}
