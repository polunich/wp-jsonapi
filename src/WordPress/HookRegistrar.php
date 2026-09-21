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

/**
 * `addFilters()` is not idempotent: each call adds every callback again,
 * a first-class callable being a new closure each time
 *
 * @internal
 */
final readonly class HookRegistrar
{
    /**
     * ahead of every other callback of the five filters, WordPress's own at 10 included,
     * so each later callback sees the route WordPress matches and the response the client receives
     */
    public const FIRST = PHP_INT_MIN;

    public const LAST = PHP_INT_MAX;

    public function __construct(
        private RouteRegistrar $routeRegistrar,
        private RouteNormalizer $routeNormalizer,
        private ResponseFilter $responseFilter,
    ) {}

    public function addFilters(): void
    {
        add_filter('rest_pre_dispatch', $this->routeNormalizer->filterPreDispatch(...), self::FIRST, 3);
        add_filter(
            'rest_request_before_callbacks',
            $this->responseFilter->filterRequestBeforeCallbacks(...),
            self::FIRST,
            3,
        );
        add_filter(
            'rest_request_after_callbacks',
            $this->responseFilter->filterRequestAfterCallbacks(...),
            self::FIRST,
            3,
        );
        add_filter('rest_post_dispatch', $this->responseFilter->filterPostDispatch(...), self::FIRST, 3);
        add_filter('rest_pre_serve_request', $this->responseFilter->filterPreServeRequest(...), self::FIRST, 4);
        add_filter('rest_pre_serve_request', $this->responseFilter->writeContent(...), self::LAST, 4);
    }

    public function registerRoutes(): void
    {
        $this->routeRegistrar->register();
    }
}
