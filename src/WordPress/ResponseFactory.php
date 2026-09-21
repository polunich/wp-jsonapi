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

use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Response;
use WP_REST_Response;

/**
 * `WP_HTTP_Response` holds one string per header name,
 * and `serve_request()` sends each with one `header()` call,
 * so the lines of a field are joined with a comma:
 * RFC 9110 5.3 allows that for every field but `Set-Cookie`, which the library never writes.
 *
 * a name keeps its lower case: `header()` replaces an earlier line of the name in any case,
 * so the library's `Content-Type` replaces the one WordPress sends before dispatch.
 * a null body is sent as no content.
 *
 * a content in another media type is handed over as the data of the response,
 * for a `rest_pre_serve_request` callback to write piece by piece.
 * it carries `Content-Length` where its size is known and `OutputBuffers::keepLength()` holds:
 * RFC 9110 8.6 asks for the field whenever the size is known before the header section,
 * and lets a response to HEAD carry the value its GET would
 *
 * @internal
 */
final readonly class ResponseFactory
{
    private OutputBuffers $buffers;

    public function __construct(?OutputBuffers $buffers = null)
    {
        $this->buffers = $buffers ?? new OutputBuffers();
    }

    public function toWordPress(Response $response): WP_REST_Response
    {
        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        $content = $response->content;

        if ($content === null) {
            return new WP_REST_Response($response->document, $response->status, $headers);
        }

        $size = $content->size();

        if ($size !== null && $this->buffers->keepLength()) {
            $headers[FieldName::CONTENT_LENGTH] = (string) $size;
        }

        return new WP_REST_Response($content, $response->status, $headers);
    }
}
