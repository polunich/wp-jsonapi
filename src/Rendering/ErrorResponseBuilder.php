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

namespace Polunich\WpJsonApi\Rendering;

use InvalidArgumentException;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\ErrorDocument;
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the response of a failed request: one error object per description of the exception, in order,
 * each worded by `ErrorFactory`, which also roots its pointer at a failed atomic operation.
 *
 * the status is `JsonApiException::status()` as it stands:
 * the choice among several problems and between 401 and 403 is made before this class.
 * a 401 without challenges is written as it is,
 * since a throw here would leave the request with no response at all.
 *
 * header fields: `Content-Type` always, as an error response always carries a document;
 * `Vary` as a success to the same request would carry; `Allow` on a 405 alone;
 * `Accept` on a 415 alone, listing the media types the route reads,
 * as the field "can be used to indicate which media types would have been accepted in the request"
 * (RFC 9110, section 15.5.16), and absent where the route reads none.
 * no `Content-Language`: a text comes from a catalog the project may replace,
 * or from the description itself, so its language is unknown.
 * no `ETag`, `Location` or `Content-Location`:
 * an error document is no representation of the target resource
 *
 * @internal
 */
final readonly class ErrorResponseBuilder
{
    private const METHOD_NOT_ALLOWED = 405;

    private const UNSUPPORTED_MEDIA_TYPE = 415;

    public function __construct(
        private ErrorFactory $errors,
        private DocumentEncoder $encoder,
        private LanguageSources $languageSources,
    ) {}

    /**
     * a null `$contentType` means the request failed before or inside the negotiation,
     * which the library responds to with the bare JSON:API media type.
     * `$operationPosition` is zero-based, and null outside an atomic request.
     * a 405 must carry `Allow` (RFC 9110, 15.5.6),
     * so without `$allowedMethods` it writes the empty value:
     * the resource allows no methods (RFC 9110, 10.2.1)
     *
     * @param ServerRequest   $request            `Vary` is read off it
     * @param list<string>    $allowedMethods     the methods of the target resource
     * @param list<MediaType> $acceptedMediaTypes the media types of the content the route reads
     *
     * @throws InvalidArgumentException when the position is negative,
     *                                  methods come with a status other than 405,
     *                                  a method is no token (RFC 9110, 9),
     *                                  a method repeats,
     *                                  or media types come with a status other than 415
     */
    public function response(
        JsonApiException $error,
        ServerRequest $request,
        ?MediaType $contentType,
        ?int $operationPosition = null,
        array $allowedMethods = [],
        array $acceptedMediaTypes = [],
    ): Response {
        $status = $error->status();

        self::checkMethods($status, $allowedMethods);

        if ($acceptedMediaTypes !== [] && $status !== self::UNSUPPORTED_MEDIA_TYPE) {
            throw new InvalidArgumentException(sprintf(
                'the Accept field is written on a 415 and on no other status, and this response carries %d'
                . ' (RFC 9110 15.5.16)',
                $status,
            ));
        }

        $objects = array_map(
            function (ErrorDescription $description) use ($status, $operationPosition): ErrorObject {
                return $this->errors->create(
                    $description,
                    $status,
                    $operationPosition,
                );
            },
            $error->descriptions(),
        );

        $document = new ErrorDocument(
            $objects,
            new JsonApiObject(
                JsonApiObject::VERSION,
                $contentType?->ext() ?? [],
                $contentType?->profile() ?? [],
            ),
        );

        $headers = new Headers([
            FieldName::CONTENT_TYPE => ($contentType ?? MediaType::jsonApi())->toHeaderValue(),
            FieldName::VARY => ResponseBuilder::vary($this->languageSources, $request),
        ]);

        if ($status === self::METHOD_NOT_ALLOWED) {
            // one line: `Allow = #method` is a comma-separated list (RFC 9110, 10.2.1),
            // unlike the challenges below
            $headers = $headers->with(FieldName::ALLOW, implode(', ', $allowedMethods));
        }

        if ($acceptedMediaTypes !== []) {
            $accepted = array_map(static function (MediaType $mediaType): string {
                return $mediaType->toHeaderValue();
            }, $acceptedMediaTypes);
            $headers = $headers->with(FieldName::ACCEPT, implode(', ', $accepted));
        }

        if ($error instanceof UnauthorizedException) {
            foreach ($error->challenges() as $challenge) {
                // a line per challenge:
                // several on one line "might not be interoperable" (RFC 9110, 11.6.1)
                $headers = $headers->withAdded(FieldName::WWW_AUTHENTICATE, $challenge->toHeaderValue());
            }
        }

        return new Response($status, $headers, $this->encoder->encode($document));
    }

    /**
     * `Allow` lists "the set of methods advertised as supported by the target resource"
     * (RFC 9110, 10.2.1), so each method is a token and stands once;
     * the library writes the field on a 405 alone
     *
     * @param list<string> $methods
     *
     * @throws InvalidArgumentException
     */
    private static function checkMethods(int $status, array $methods): void
    {
        if ($methods === []) {
            return;
        }

        if ($status !== self::METHOD_NOT_ALLOWED) {
            throw new InvalidArgumentException(sprintf(
                'the Allow field is written on a 405 and on no other status, and this response carries'
                . ' %d (RFC 9110 15.5.6)',
                $status,
            ));
        }

        $seen = [];

        foreach ($methods as $method) {
            if (!FieldValueGrammar::isToken($method)) {
                throw new InvalidArgumentException(sprintf(
                    'the Allow field lists methods, and "%s" is no token, "method = token"'
                    . ' (RFC 9110 9)',
                    Printable::of($method),
                ));
            }

            if (in_array($method, $seen, true)) {
                throw new InvalidArgumentException(sprintf(
                    'the Allow field lists the methods of the target resource, each once, and "%s" stands'
                    . ' twice (RFC 9110 10.2.1)',
                    $method,
                ));
            }

            $seen[] = $method;
        }
    }
}
