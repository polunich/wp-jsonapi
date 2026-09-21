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
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Document\AtomicResultsDocument;
use Polunich\WpJsonApi\Document\DataDocument;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Document\MetaDocument;
use Polunich\WpJsonApi\Document\ResourceObject;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\ContentResult;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Routing\RouteTarget;
use UnexpectedValueException;

/**
 * a result without a document (a 204, a 304, an atomic 204) gets no body,
 * no `Content-Type` and no `Content-Language`: there is no content to describe (RFC 9110, 6.4.1).
 * a 304 keeps `ETag` and `Vary` (RFC 9110, 15.4.5).
 *
 * `Vary` stands on every response, bodyless ones included,
 * as JSON:API asks whether or not an extension or a profile is applied (Content Negotiation):
 * `Accept`, and the language fields `Metadata\LanguageSources::varyFields()` gives for the request.
 *
 * `Location` of a 201 and `Content-Location` of a 202 are read off the document just built,
 * so they cannot differ from its self link (JSON:API, Creating Resources).
 * a 201 whose type excludes the fetch of its resources has no self link and so no `Location`:
 * the field would name a URL whose GET the server refuses.
 * a 202 has no `Location`: its content is the monitor, reporting on the write (RFC 9110, 8.7).
 *
 * no `Date`, `Cache-Control` or `Content-Length`: they belong to the server sending the message
 *
 * @internal
 */
final readonly class ResponseBuilder
{
    public function __construct(
        private DocumentBuilder $documents,
        private DocumentEncoder $encoder,
        private LanguageSources $languageSources,
    ) {}

    /**
     * the `Vary` of every response to the request, an error response included:
     * the rule reads the request alone, not the step that produced the response
     */
    public static function vary(LanguageSources $languageSources, ServerRequest $request): string
    {
        return implode(', ', [FieldName::ACCEPT, ...$languageSources->varyFields($request)]);
    }

    /**
     * @param MediaType $contentType     the response media type, ext and profile included
     * @param ?string   $contentLanguage the content language tag, null when none was resolved
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when the result is of a class this library does not know,
     *                                  the route is an atomic route,
     *                                  a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    public function response(
        Result $result,
        RouteTarget $target,
        ServerRequest $request,
        MediaType $contentType,
        ?string $contentLanguage,
    ): Response {
        return $this->message(
            $result,
            $this->documents->document($result, $target, $request, $contentType),
            $request,
            $contentType,
            $contentLanguage,
        );
    }

    /**
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice,
     *                                  or the path of the request holds no value of a parameter
     *                                  of the endpoint
     * @throws LogicException           when the result is none an endpoint responds with,
     *                                  a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    public function endpointResponse(
        Result $result,
        EndpointMetadata $endpoint,
        ServerRequest $request,
        MediaType $contentType,
        ?string $contentLanguage,
    ): Response {
        if ($result instanceof ContentResult) {
            return $this->content($result, $request, $contentLanguage);
        }

        return $this->message(
            $result,
            $this->documents->endpointDocument($result, $endpoint, $request, $contentType),
            $request,
            $contentType,
            $contentLanguage,
        );
    }

    private function content(ContentResult $result, ServerRequest $request, ?string $contentLanguage): Response
    {
        $fields = [
            FieldName::CONTENT_TYPE => $result->mediaType->toHeaderValue(),
            FieldName::VARY => self::vary($this->languageSources, $request),
        ];

        if ($contentLanguage !== null) {
            $fields[FieldName::CONTENT_LANGUAGE] = $contentLanguage;
        }

        if ($result->attachmentName !== null) {
            $fields[FieldName::CONTENT_DISPOSITION] = ContentDisposition::attachment($result->attachmentName);
        }

        return new Response($result->status(), new Headers($fields), null, $result->content);
    }

    /**
     * @throws LogicException when a 202 carries a monitor without a self link
     */
    private function message(
        Result $result,
        AtomicResultsDocument|DataDocument|MetaDocument|null $document,
        ServerRequest $request,
        MediaType $contentType,
        ?string $contentLanguage,
    ): Response {
        $status = $result->status();

        // a fixed field order: one result always writes the same message
        $fields = [];

        if ($document !== null) {
            $fields[FieldName::CONTENT_TYPE] = $contentType->toHeaderValue();
        }

        $fields[FieldName::VARY] = self::vary($this->languageSources, $request);

        if ($document !== null && $contentLanguage !== null) {
            $fields[FieldName::CONTENT_LANGUAGE] = $contentLanguage;
        }

        $entityTag = $result->entityTag();

        if ($entityTag !== null) {
            $fields[FieldName::ETAG] = $entityTag->toHeaderValue();
        }

        if ($result instanceof ResourceResult && $status === 201) {
            $location = self::resourceSelfLink($document, $status);

            if ($location !== null) {
                $fields[FieldName::LOCATION] = $location;
            }
        }

        if ($result instanceof AcceptedResult) {
            $fields[FieldName::CONTENT_LOCATION] = self::resourceSelfLink($document, $status)
                ?? throw new LogicException(
                    sprintf(
                        'the %d response carries a monitor without a self link, which its Content-Location field'
                        . ' names; a monitor type keeps the fetch of its resources, or the API is not built',
                        $status,
                    ),
                );
        }

        return new Response(
            $status,
            new Headers($fields),
            $document === null ? null : $this->encoder->encode($document),
        );
    }

    /**
     * null where the resource object carries no self link.
     * the throw only narrows the type:
     * the document of a 201 or a 202 always has a resource object as its primary data
     *
     * @throws LogicException
     */
    private static function resourceSelfLink(
        AtomicResultsDocument|DataDocument|MetaDocument|null $document,
        int $status,
    ): ?string {
        $data = $document instanceof DataDocument ? $document->data : null;

        if (!$data instanceof ResourceObject) {
            throw new LogicException(sprintf(
                'the %d response carries no resource object as its primary data, and its location field'
                . ' is the self link of that object [spec, Creating Resources]',
                $status,
            ));
        }

        $self = $data->links->all()[Member::SELF] ?? null;

        // `??` also covers a null link, so no nullsafe operator is needed
        return $self->href ?? null;
    }
}
