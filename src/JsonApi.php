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

namespace Polunich\WpJsonApi;

use Closure;
use LogicException;
use Polunich\WpJsonApi\WordPress\DeferredRegistration;
use Polunich\WpJsonApi\WordPress\HookRegistrar;

/**
 * the entry point of the library: the builder `api()` returns composes the whole library,
 * so a project names only what it declares and what it replaces
 *
 *     JsonApi::register(static function () use ($container): Api {
 *         return JsonApi::api(name: 'petstore', version: '1.0.0', restNamespace: 'acme/v1')
 *             ->resource(ResourceType::make('pets')->...)
 *             ->container($container)
 *             ->build();
 *     });
 */
final class JsonApi
{
    private function __construct() {}

    /**
     * @param string $name          `info.title` of the contract
     * @param string $version       `info.version` of the contract, which only the project knows
     * @param string $restNamespace the REST namespace of the routes, such as `acme/v1`,
     *                              with no slash at either end
     */
    public static function api(string $name, string $version, string $restNamespace): ApiBuilder
    {
        return new ApiBuilder($name, $version, $restNamespace);
    }

    /**
     * call it before `rest_api_init` fires:
     * in the main file of the plugin, or on `plugins_loaded` or `init`.
     *
     * nothing is built before WordPress prepares a REST server:
     * the first `rest_api_init` of the process calls the closure,
     * checks the implementation references and adds the hooks of the library,
     * so a declaration the library refuses throws on the first REST request
     *
     * @param Closure(): Api $api called again on the next `rest_api_init` after a call that threw
     */
    public static function register(Closure $api): void
    {
        (new DeferredRegistration(static function () use ($api): HookRegistrar {
            return self::built($api)->hooks();
        }))->register();
    }

    /**
     * the closure is the project's, and PHP checks no return type it does not declare
     *
     * @throws LogicException when the closure returns no `Api`
     */
    private static function built(Closure $api): Api
    {
        $built = $api();

        if (!$built instanceof Api) {
            throw new LogicException(sprintf(
                'the closure JsonApi::register() calls returns an Api, got %s',
                get_debug_type($built),
            ));
        }

        return $built;
    }
}
