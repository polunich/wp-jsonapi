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

use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Server\Server;
use Psr\Log\LoggerInterface;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * the `permission_callback` and the `callback` of every route of the API.
 *
 * `authorize()` returns `true` or the library's own `WP_Error`, never `false`,
 * which WordPress would turn into its own `rest_forbidden`.
 * the `WP_Error` carries the status of the refusal, which WordPress reads from its data,
 * and the code and title of its first error, and it is recorded as the refusal's stand-in.
 *
 * `authorize()` may also run outside the dispatch:
 * WordPress asks `permission_callback` of every method of the matched route to write `Allow`.
 * a library response carries no matched route,
 * so that pass meets only a response that is not the library's:
 * WordPress's response to `OPTIONS`, or one a filter put in its place;
 * each closure asks about the route it is bound to.
 *
 * `handle()` returns a `WP_REST_Response`, so a caller of `rest_do_request()`,
 * which skips `rest_post_dispatch`, still reads the document.
 *
 * no throwable leaves either method. uncaught, it would end in WordPress's fatal error handler,
 * outside `rest_post_dispatch`, with no document at all;
 * as a `WP_Error` 500 it reaches that filter, which converts it like any WordPress error there.
 * it is logged, and a logger that throws leaves both throwables to `error_log()`,
 * the one sink that cannot be the one that failed
 *
 * @internal
 */
final readonly class RestController
{
    public function __construct(
        private Server $server,
        private RequestFactory $requests,
        private ResponseFactory $responses,
        private RecordedResponses $recorded,
        private LoggerInterface $logger,
    ) {}

    public function authorize(Route $route, WP_REST_Request $request): bool|WP_Error
    {
        try {
            $refusal = $this->server->authorize($route, $this->requests->fromWordPress($request));
        } catch (Throwable $failure) {
            return $this->internalError($failure);
        }

        if ($refusal === null) {
            return true;
        }

        $standIn = self::errorOf($refusal);
        $this->recorded->record($request, $this->responses->toWordPress($refusal), $standIn);

        return $standIn;
    }

    public function handle(Route $route, WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        try {
            $response = $this->server->handle($route, $this->requests->fromWordPress($request));
        } catch (Throwable $failure) {
            return $this->internalError($failure);
        }

        $restResponse = $this->responses->toWordPress($response);
        $this->recorded->record($request, $restResponse);

        return $restResponse;
    }

    private static function errorOf(Response $refusal): WP_Error
    {
        $errors = $refusal->document[Member::ERRORS] ?? null;
        $first = is_array($errors) && is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = $first[Member::CODE] ?? null;
        $title = $first[Member::TITLE] ?? null;

        return new WP_Error(
            is_string($code) ? $code : ErrorCode::ofStatus($refusal->status)->value,
            is_string($title) ? $title : '',
            ['status' => $refusal->status],
        );
    }

    private function internalError(Throwable $failure): WP_Error
    {
        $message = sprintf(
            'the library returned no response, a %s left it: %s',
            $failure::class,
            $failure->getMessage(),
        );

        try {
            $this->logger->error($message, ['exception' => $failure]);
        } catch (Throwable $loggerFailure) {
            error_log($message . "\n" . $failure);
            error_log(sprintf(
                'the logger of the library failed too, a %s: %s',
                $loggerFailure::class,
                $loggerFailure->getMessage(),
            ));
        }

        return new WP_Error(ErrorCode::InternalError->value, '', ['status' => 500]);
    }
}
