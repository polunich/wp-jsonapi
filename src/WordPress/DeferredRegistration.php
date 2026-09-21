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

/**
 * registers an API with WordPress and builds it when WordPress first prepares a REST server,
 * as core asks of endpoint objects:
 * "Endpoint objects should be created and register their hooks on this action
 * rather than another action to ensure they're only loaded when needed"
 * (`rest_api_init`, `wp-includes/rest-api.php`).
 * a request that never reaches the REST API builds nothing and adds one callback.
 *
 * the first `rest_api_init` builds the hooks and adds the filters, once per process;
 * every `rest_api_init` registers the routes, since each prepares a server of its own.
 * a build that throws leaves nothing behind, and the next `rest_api_init` builds again.
 * it is mutable on purpose: it keeps the hooks it built
 *
 * @internal
 */
final class DeferredRegistration
{
    private ?HookRegistrar $hooks = null;

    /**
     * @param Closure(): HookRegistrar $build builds the API and the hooks over it
     */
    public function __construct(private readonly Closure $build) {}

    /**
     * call it before `rest_api_init` fires:
     * a callback added later registers nothing for that request
     */
    public function register(): void
    {
        add_action('rest_api_init', $this->prepare(...));
    }

    public function prepare(): void
    {
        if ($this->hooks === null) {
            $hooks = ($this->build)();
            $hooks->addFilters();
            $this->hooks = $hooks;
        }

        $this->hooks->registerRoutes();
    }
}
