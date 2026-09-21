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

namespace Polunich\WpJsonApi\Endpoint;

use OutOfBoundsException;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Text\Printable;

/**
 * each part of the request, decoded and checked as the endpoint declares:
 *
 * - `$path` holds every parameter of the path, decoded by its codec;
 * - `$records` holds the record of every parameter bound to a type, loaded before the handler runs;
 * - `$queryParameters` holds the declared query parameters the request carries,
 *   decoded by their codecs: an optional parameter the request leaves out is absent, never null;
 * - `$collectionQuery` is the reading a page response asks for,
 *   the request's `filter`, `sort` and `page` resolved as for a `Store\CollectionReader`;
 *   null where the endpoint responds with no page;
 * - `$body` is the body the request's `Content-Type` picked among the declared ones,
 *   or null for a request without content; `$bodyMediaType` names its media type;
 * - `$precondition` is the If-Match of a write on the precondition record:
 *   the handler makes it a condition of its own write
 *   and throws `Store\VersionMismatchException` for a stored version it does not admit;
 * - `$languages` holds the language preferences of the source that decided,
 *   as the store reads them;
 * - `$http` is the request as it arrived;
 * - `$mediaType` is the representation `Accept` picked among the ones the endpoint declares,
 *   such as `text/csv` or `application/vnd.api+json`
 */
final readonly class Request
{
    /**
     * @param array<string, string> $path
     * @param array<string, object> $records by the name of the parameter
     * @param Values<mixed>         $queryParameters
     */
    public function __construct(
        public string $endpointName,
        public array $path,
        public array $records,
        public Values $queryParameters,
        public ?CollectionQuery $collectionQuery,
        public ResourceBody|Linkage|MetaBody|JsonBody|FormBody|BytesBody|null $body,
        public LanguagePreferences $languages,
        public ?Precondition $precondition,
        public ServerRequest $http,
        public string $mediaType,
        public ?string $bodyMediaType,
    ) {}

    /**
     * @throws OutOfBoundsException when the parameter is bound to no type
     */
    public function record(string $parameter): object
    {
        return $this->records[$parameter] ?? throw new OutOfBoundsException(sprintf(
            'the endpoint "%s" binds no record to the parameter "%s"',
            $this->endpointName,
            Printable::of($parameter),
        ));
    }
}
