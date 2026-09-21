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
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Server\Server;
use Psr\Log\LoggerInterface;
use Throwable;
use WeakMap;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * puts the library's response in front of the client on its routes.
 *
 * a filter callback may change the response in place or return another value,
 * and the client gets what the filters leave.
 *
 * `filterRequestBeforeCallbacks()` leaves a body WordPress read as broken JSON to the library.
 * `dispatch()` runs `has_valid_params()` before any callback,
 * which returns `rest_invalid_json` for an `application/json` or `+json` body that is no JSON,
 * and on that error WordPress calls neither the permission nor the callback:
 * the client would get 400 before the 401, 403, 406, 415 or 412 the library responds with first.
 * on a library callback that error alone becomes `null`,
 * so the library reads the body last and responds with `invalid_json`.
 *
 * `filterRequestAfterCallbacks()` swaps the `WP_Error` stand-in of a refusal for its response,
 * so later callbacks, `rest_post_dispatch` and a caller of `rest_do_request()`
 * read the library's document, not the error shape WordPress writes for a `WP_Error`,
 * which drops the document and its header fields.
 *
 * `filterPostDispatch()`:
 *
 * - serves a kept response as it stands, without its matched route.
 *   on a response with a matched route WordPress writes `Allow`
 *   from the methods whose `permission_callback` lets this client through:
 *   a result of the gate, not the methods of the resource a 405 states,
 *   and one more permission call per method. nothing else of WordPress reads the matched route.
 * - turns an error on a route of the library into the library's error document:
 *   one a filter put in place of the response of a library callback,
 *   or one no callback returned, `rest_no_route`, an authentication error raised before dispatch
 *   or a result of `rest_pre_dispatch`, where the path lies on a route of the library
 *   (`RoutePatterns::routeFor()`), a parameter segment its codec refuses included.
 *   a conversion that throws is logged and leaves the WordPress response in place.
 * - passes everything else untouched:
 *   the response of a foreign callback, on the namespace too,
 *   an error on a path that lies on no route of the library, and WordPress's response to `OPTIONS`.
 *
 * `filterPreServeRequest()` runs after WordPress sent the status and the fields,
 * before the content, and corrects two things on a response this class served:
 *
 * - WordPress sends `Content-Type: application/json` before dispatch,
 *   and a response without content, a 204 or a 304, has no `Content-Type` to replace it,
 *   so the field is removed. a cache would copy it from a 304 onto the stored response,
 *   and RFC 9110 15.4.5 says a 304 SHOULD NOT carry such representation metadata.
 * - WordPress writes no status line for a status its table lacks, and the client would read 200.
 *   a library response may carry any status, so such a status is sent here.
 *
 * `writeContent()` writes a content in another media type, last of the callbacks of that filter:
 * `header()` must be called before any output (php.net),
 * and the callbacks before it still send header fields, WordPress's own CORS fields among them.
 * a HEAD gets no content, as WordPress sends none either.
 * a failure while it writes can no longer change the status:
 * it is logged, and the content ends where it stands.
 * it is mutable on purpose: it remembers the responses it served
 *
 * @internal
 */
final class ResponseFilter
{
    private const INVALID_JSON = 'rest_invalid_json';

    /** @var WeakMap<WP_HTTP_Response, true> the responses this class served */
    private WeakMap $served;

    /**
     * @param Closure(int): void    $sendStatus `http_response_code()`
     * @param Closure(string): void $write      `echo`
     */
    public function __construct(
        private readonly Server $server,
        private readonly RoutePatterns $patterns,
        private readonly RegisteredCallbacks $callbacks,
        private readonly RequestFactory $requests,
        private readonly ResponseFactory $responses,
        private readonly RecordedResponses $recorded,
        private readonly LoggerInterface $logger,
        private readonly Closure $sendStatus,
        private readonly Closure $write,
        private readonly OutputBuffers $buffers,
    ) {
        $this->served = new WeakMap();
    }

    /**
     * @param array<array-key, mixed> $handler
     */
    public function filterRequestBeforeCallbacks(mixed $response, array $handler, WP_REST_Request $request): mixed
    {
        if (
            $response instanceof WP_Error
            && $response->get_error_code() === self::INVALID_JSON
            && $this->callbacks->routeOf($handler['callback'] ?? null) !== null
        ) {
            return null;
        }

        return $response;
    }

    /**
     * @param array<array-key, mixed> $handler
     */
    public function filterRequestAfterCallbacks(mixed $response, array $handler, WP_REST_Request $request): mixed
    {
        $standIn = $this->recorded->standInOf($request);

        if ($standIn === null || $response !== $standIn) {
            return $response;
        }

        return $this->recorded->responseTo($request) ?? $response;
    }

    public function filterPostDispatch(mixed $response, WP_REST_Server $server, WP_REST_Request $request): mixed
    {
        $recorded = $this->recorded->responseTo($request);

        if ($recorded !== null && $response === $recorded) {
            $recorded->set_matched_route('');

            return $this->serve($recorded);
        }

        if (!$response instanceof WP_HTTP_Response || $response->get_status() < 400) {
            return $response;
        }

        $route = $this->routeOf($request);

        if ($route === null) {
            return $response;
        }

        $data = $response->get_data();
        $code = is_array($data) && is_string($data['code'] ?? null) ? $data['code'] : '';
        $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : '';

        try {
            $converted = $this->server->convertForeignError(
                $response->get_status(),
                $code,
                $message,
                $this->requests->fromWordPress($request),
                $route,
            );
        } catch (Throwable $failure) {
            $this->logger->error(
                sprintf(
                    'the WordPress error %s on the namespace was left unconverted: %s',
                    $code,
                    $failure->getMessage(),
                ),
                ['exception' => $failure],
            );

            return $response;
        }

        return $this->serve($this->responses->toWordPress($converted));
    }

    public function filterPreServeRequest(
        mixed $served,
        mixed $result,
        WP_REST_Request $request,
        WP_REST_Server $server,
    ): mixed {
        if (!$result instanceof WP_HTTP_Response || !isset($this->served[$result])) {
            return $served;
        }

        if (!self::statesContentType($result->get_headers())) {
            $server->remove_header(FieldName::CONTENT_TYPE);
        }

        $status = $result->get_status();

        if (get_status_header_desc($status) === '') {
            ($this->sendStatus)($status);
        }

        return $served;
    }

    public function writeContent(mixed $served, mixed $result, WP_REST_Request $request, WP_REST_Server $server): mixed
    {
        if (!$result instanceof WP_HTTP_Response || !isset($this->served[$result])) {
            return $served;
        }

        $content = $result->get_data();

        if (!$content instanceof Content) {
            return $served;
        }

        if ($served !== false || $request->get_method() === Method::HEAD) {
            $content->close();

            return $served;
        }

        $this->buffers->endRemovable();

        try {
            foreach ($content->pieces() as $piece) {
                ($this->write)($piece);
                $this->buffers->flush();
            }
        } catch (Throwable $failure) {
            $this->logger->error(
                sprintf(
                    'the content of the response was cut, a %s ended it: %s',
                    $failure::class,
                    $failure->getMessage(),
                ),
                ['exception' => $failure],
            );
        } finally {
            $content->close();
        }

        return true;
    }

    private function routeOf(WP_REST_Request $request): ?Route
    {
        $callback = $request->get_attributes()['callback'] ?? null;

        if ($callback !== null) {
            return $this->callbacks->routeOf($callback);
        }

        return $this->patterns->routeFor($request->get_route(), $request->get_method());
    }

    private function serve(WP_REST_Response $response): WP_REST_Response
    {
        $this->served[$response] = true;

        return $response;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private static function statesContentType(array $headers): bool
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp((string) $name, FieldName::CONTENT_TYPE) === 0) {
                return true;
            }
        }

        return false;
    }
}
