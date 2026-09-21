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

namespace Polunich\WpJsonApi\Tests\Fixtures\WordPress;

use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\WordPress\HookRegistrar;
use Polunich\WpJsonApi\WordPress\OutputBuffers;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use Polunich\WpJsonApi\WordPress\RegisteredCallbacks;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use Polunich\WpJsonApi\WordPress\ResponseFilter;
use Polunich\WpJsonApi\WordPress\RestController;
use Polunich\WpJsonApi\WordPress\RouteNormalizer;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use Polunich\WpJsonApi\WordPress\RouteRegistrar;

/**
 * the hooks of the pets API as `Api` composes them,
 * with `register_rest_route()` replaced by a closure that records each route,
 * since registering one needs a site. no status is sent and no content is written.
 * it is mutable on purpose: it records the routes as they are registered
 *
 * @internal
 */
final class RecordingHooks
{
    /** @var list<string> the patterns registered, in order */
    public array $registered = [];

    /** @var array<string, array<array-key, mixed>> the handlers last registered per pattern */
    public array $handlers = [];

    public function __construct(public readonly RecordedResponses $recorded = new RecordedResponses()) {}

    public function hookRegistrar(?OperationPermission $permission = null): HookRegistrar
    {
        $logger = new RecordingLogger();
        $server = PetsApi::server(PetsApi::store(), $permission ?? PetsApi::permission(true), $logger);
        $patterns = new RoutePatterns(PetsApi::REST_NAMESPACE, $server->routes());
        $requests = new RequestFactory();
        $responses = new ResponseFactory();
        $callbacks = new RegisteredCallbacks();

        return new HookRegistrar(
            new RouteRegistrar(
                $patterns,
                new RestController($server, $requests, $responses, $this->recorded, $logger),
                $callbacks,
                /**
                 * @param array<array-key, mixed> $args
                 */
                function (string $namespace, string $route, array $args): bool {
                    $this->registered[] = $route;
                    $this->handlers[$route] = $args;

                    return true;
                },
            ),
            new RouteNormalizer($patterns),
            new ResponseFilter(
                $server,
                $patterns,
                $callbacks,
                $requests,
                $responses,
                $this->recorded,
                $logger,
                static function (int $status): void {},
                static function (string $piece): void {},
                new OutputBuffers(),
            ),
        );
    }
}
