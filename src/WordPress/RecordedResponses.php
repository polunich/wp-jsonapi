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

use WeakMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * the response of the library to each WordPress request,
 * kept from the route callback to the dispatch filters.
 *
 * `permission_callback`, `callback`, `rest_request_after_callbacks` and `rest_post_dispatch`
 * all get the one `WP_REST_Request` WordPress built, so that object is the key.
 * the response is the very `WP_REST_Response` handed to WordPress,
 * which `rest_ensure_response()` passes on as it is:
 * a filter that changed the response in place still holds this object,
 * one that replaced it holds another, and identity alone tells the two apart.
 *
 * the map is weak, so an entry goes with its request:
 * `rest_post_dispatch`, its last reader, does not run inside `rest_do_request()`,
 * so no filter can be relied on to drop it.
 * it is mutable on purpose: it records each request as the library responds to it
 *
 * @internal
 */
final class RecordedResponses
{
    /** @var WeakMap<WP_REST_Request, array{WP_REST_Response, ?WP_Error}> */
    private WeakMap $responses;

    public function __construct()
    {
        $this->responses = new WeakMap();
    }

    /**
     * a later record of the same request replaces the earlier one, stand-in included
     *
     * @param WP_REST_Response $response the response handed to WordPress, or the one of a refusal
     * @param ?WP_Error        $standIn  what `permission_callback` returned
     *                                   in place of the refusal;
     *                                   null where `callback` returned the response
     */
    public function record(WP_REST_Request $request, WP_REST_Response $response, ?WP_Error $standIn = null): void
    {
        $this->responses[$request] = [$response, $standIn];
    }

    public function responseTo(WP_REST_Request $request): ?WP_REST_Response
    {
        return ($this->responses[$request] ?? null)[0] ?? null;
    }

    public function standInOf(WP_REST_Request $request): ?WP_Error
    {
        return ($this->responses[$request] ?? null)[1] ?? null;
    }
}
