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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one endpoint a project declares, resolved against the resource types.
 *
 * the name is the operationId of the contract, under the rule of `OperationName`.
 *
 * a GET endpoint also responds to HEAD,
 * as RFC 9110 (section 9.1) requires of a general-purpose server.
 * a safe method reads no body and creates nothing,
 * so an endpoint of one declares no body and no 201:
 * content of a GET "has no generally defined semantics" (RFC 9110, section 9.3.1).
 *
 * the precondition record is the bound record whose entity tag the preconditions read,
 * and which the response tags where the response is that record.
 *
 * beside its JSON:API responses, one per status,
 * an endpoint may respond with 200 in other media types, its media responses,
 * and read bodies in other media types, one per media type.
 *
 * `$defaultSort` and `$paginationPolicy` hold only what the endpoint declares;
 * a page reads the ones `ResourceRegistry` resolves
 *
 * @internal
 */
final readonly class EndpointMetadata
{
    private const METHODS = [Method::GET, Method::POST, Method::PUT, Method::PATCH, Method::DELETE];

    /** @var non-empty-list<string> upper case, as in `Http\ServerRequest::$method` */
    public array $methods;

    /**
     * @param array<string, string>        $bindings              parameter => bound type
     * @param list<QueryParameterMetadata> $queryParameters
     * @param array<int, ResponseMetadata> $responses             status => response
     * @param ?string                      $preconditionParameter names the precondition record
     * @param bool                         $leavesUnchanged       whether a write leaves it intact
     * @param list<MediaResponseMetadata>  $mediaResponses
     * @param int                          $jsonApiRank           media responses ahead of JSON:API
     * @param list<MediaBodyMetadata>      $mediaBodies
     * @param ?list<SecurityScheme>        $securitySchemes       in place of the API's unless null
     * @param ?Sort                        $defaultSort           the order of a page without `sort`
     * @param ?PaginationPolicy            $paginationPolicy      the page sizes of a page
     *
     * @throws InvalidArgumentException when a part is refused; the message says which and why
     */
    public function __construct(
        public string $name,
        public string $method,
        public PathTemplate $template,
        public array $bindings,
        public array $queryParameters,
        public ?BodyMetadata $body,
        public array $responses,
        public ImplementationReference $handler,
        public ImplementationReference $permission,
        public ?string $tag,
        public ?string $description,
        public ?string $preconditionParameter,
        public bool $leavesUnchanged,
        public array $mediaResponses = [],
        public int $jsonApiRank = 0,
        public array $mediaBodies = [],
        public ?array $securitySchemes = null,
        public ?Sort $defaultSort = null,
        public ?PaginationPolicy $paginationPolicy = null,
    ) {
        $this->checkName();

        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" responds to %s, and an endpoint responds to one of %s',
                $name,
                Printable::of($method),
                implode(', ', self::METHODS),
            ));
        }

        $this->methods = Method::served($method);

        $this->checkBindings();
        $this->checkQueryParameters();
        $this->checkSafeReadsNoBody();
        $this->checkBody();
        $this->checkMediaBodies();
        $this->checkResponses();
        $this->checkPage();
        $this->checkMediaResponses();
        $this->checkPreconditions();

        SecuritySchemes::checkDistinct($securitySchemes ?? [], sprintf('the endpoint "%s"', $name));
        DeclaredText::check($tag, sprintf('the endpoint "%s"', $name), 'tag');
        DeclaredText::check($description, sprintf('the endpoint "%s"', $name), 'description');
    }

    public function isSafe(): bool
    {
        return Method::isSafe($this->method);
    }

    public function writesPreconditionRecord(): bool
    {
        return $this->preconditionParameter !== null && !$this->isSafe() && !$this->leavesUnchanged;
    }

    /**
     * whether the response carries the entity tag of its resource, if that type declares a version:
     * the response of the precondition record, whose tag the preconditions compare,
     * and a created resource.
     * a response to PUT carries none: RFC 9110 9.3.4 forbids a validator
     * unless the content was stored as sent, which the library cannot know
     */
    public function carriesEntityTag(ResponseMetadata $response): bool
    {
        if ($this->method === Method::PUT) {
            return false;
        }

        return $response->kind === ResponseShapeKind::Created
            || ($response->kind === ResponseShapeKind::Record && $response->parameter === $this->preconditionParameter);
    }

    public function response(int $status): ?ResponseMetadata
    {
        return $this->responses[$status] ?? null;
    }

    /**
     * the type of the page the endpoint responds with, null where it responds with none
     */
    public function pageType(): ?string
    {
        $response = $this->response(200);

        return $response?->kind === ResponseShapeKind::Page ? $response->type : null;
    }

    public function mediaResponse(MediaType $mediaType): ?MediaResponseMetadata
    {
        foreach ($this->mediaResponses as $mediaResponse) {
            if ($mediaResponse->mediaType->toHeaderValue() === $mediaType->toHeaderValue()) {
                return $mediaResponse;
            }
        }

        return null;
    }

    /**
     * what `Accept` picks among, in declaration order: the media responses,
     * with the JSON:API media type after the first `$jsonApiRank` of them
     * where a response writes a document, or where the endpoint declares no media response at all
     *
     * @return non-empty-list<MediaType>
     */
    public function representations(): array
    {
        $mediaTypes = array_map(static function (MediaResponseMetadata $mediaResponse): MediaType {
            return $mediaResponse->mediaType;
        }, $this->mediaResponses);
        $document = $mediaTypes === [];

        foreach ($this->responses as $response) {
            $document = $document || $response->kind !== ResponseShapeKind::NoContent;
        }

        if ($document) {
            array_splice($mediaTypes, min($this->jsonApiRank, count($mediaTypes)), 0, [MediaType::jsonApi()]);
        }

        /** @var non-empty-list<MediaType> $mediaTypes one of the branches above always adds one */
        return $mediaTypes;
    }

    /**
     * @return list<MediaType>
     */
    public function readableMediaTypes(): array
    {
        return [
            ...($this->body === null ? [] : [MediaType::jsonApi()]),
            ...array_map(static function (MediaBodyMetadata $body): MediaType {
                return $body->mediaType;
            }, $this->mediaBodies),
        ];
    }

    /**
     * the media body whose type, subtype and parameters the request's content type carries;
     * null where the endpoint reads none
     */
    public function mediaBody(MediaType $mediaType): ?MediaBodyMetadata
    {
        foreach ($this->mediaBodies as $body) {
            if ($body->mediaType->admits($mediaType)) {
                return $body;
            }
        }

        return null;
    }

    /**
     * where `include` and `fields` start: the type of the resources a response carries,
     * or for a linkage response the bound type and the relationship,
     * whose paths start with that relationship as on the relationship route.
     * null where no response carries resources.
     * responses that start at two places are refused when declared
     *
     * @return ?array{string, ?string}
     */
    public function includeRoot(): ?array
    {
        foreach ($this->responses as $response) {
            if (!$response->kind->carriesResources()) {
                continue;
            }

            if ($response->kind === ResponseShapeKind::Linkage) {
                return [$this->bindings[(string) $response->parameter], $response->relationship];
            }

            return [(string) $response->type, null];
        }

        return null;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function checkName(): void
    {
        OperationName::check($this->name, 'an endpoint');
    }

    /**
     * @throws InvalidArgumentException when a bound parameter is no parameter of the path
     */
    private function checkBindings(): void
    {
        $parameters = array_map(static function (PathParameter $parameter): string {
            return $parameter->name;
        }, $this->template->parameters());

        foreach (array_keys($this->bindings) as $parameter) {
            if (!in_array((string) $parameter, $parameters, true)) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" binds the parameter "%s", which its path "%s" does not hold',
                    $this->name,
                    $parameter,
                    $this->template->path,
                ));
            }
        }
    }

    /**
     * @throws InvalidArgumentException when a name is declared twice
     */
    private function checkQueryParameters(): void
    {
        $seen = [];

        foreach ($this->queryParameters as $parameter) {
            if (array_key_exists($parameter->name, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" declares the query parameter "%s" twice',
                    $this->name,
                    $parameter->name,
                ));
            }

            $seen[$parameter->name] = true;
        }
    }

    /**
     * @throws InvalidArgumentException when a safe endpoint reads a body of any media type
     */
    private function checkSafeReadsNoBody(): void
    {
        if ($this->isSafe() && ($this->body !== null || $this->mediaBodies !== [])) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" responds to GET and reads a body, and "content received in a GET request has no'
                . ' generally defined semantics" (RFC 9110, section 9.3.1)',
                $this->name,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a record body names an unbound parameter
     */
    private function checkBody(): void
    {
        if ($this->body === null) {
            return;
        }

        if ($this->body->parameter !== null) {
            $this->checkBound($this->body->parameter, 'its body');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function checkResponses(): void
    {
        if ($this->responses === [] && $this->mediaResponses === []) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" declares no response,'
                . ' and an endpoint declares the response of each status it gives',
                $this->name,
            ));
        }

        foreach ($this->responses as $status => $response) {
            if ($response->status() !== $status) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" holds %s under the status %d, and that response has the status %d',
                    $this->name,
                    $response->kind->phrase(),
                    $status,
                    $response->status(),
                ));
            }

            if ($response->kind === ResponseShapeKind::Created && $this->isSafe()) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" responds to GET with 201, and a safe method requests no state change (RFC 9110,'
                    . ' section 9.2.1), so it creates nothing',
                    $this->name,
                ));
            }

            if ($response->parameter !== null) {
                $this->checkBound($response->parameter, sprintf('its response of %d', $status));
            }
        }
    }

    /**
     * a client follows the pagination links of a page with GET,
     * which reaches the endpoint only where it responds to GET
     *
     * @throws InvalidArgumentException when a page responds to another method,
     *                                  a default sort or a pagination policy comes without one,
     *                                  or the default sort is the empty sort
     */
    private function checkPage(): void
    {
        $type = $this->pageType();

        if ($type !== null && $this->method !== Method::GET) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" responds to %s with a page, and a client follows the pagination links of a page'
                . ' with GET, which does not reach this endpoint',
                $this->name,
                $this->method,
            ));
        }

        if ($type === null && ($this->defaultSort !== null || $this->paginationPolicy !== null)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" declares %s and responds with no page, which alone reads it',
                $this->name,
                $this->defaultSort !== null ? 'a default sort' : 'a pagination policy',
            ));
        }

        if ($this->defaultSort?->isEmpty() === true) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" declares a default sort without a sort field, and a default sort that is not'
                . ' declared is null',
                $this->name,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a media type is declared twice
     */
    private function checkMediaResponses(): void
    {
        $seen = [];

        foreach ($this->mediaResponses as $mediaResponse) {
            $mediaType = $mediaResponse->mediaType->toHeaderValue();

            if (in_array($mediaType, $seen, true)) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" responds with 200 in "%s" twice, and each representation is declared once',
                    $this->name,
                    $mediaType,
                ));
            }

            $seen[] = $mediaType;
        }

        if ($this->jsonApiRank < 0 || $this->jsonApiRank > count($this->mediaResponses)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" places JSON:API after %d of its %d media responses',
                $this->name,
                $this->jsonApiRank,
                count($this->mediaResponses),
            ));
        }
    }

    /**
     * `multipart/form-data` is read for POST alone:
     * PHP fills its fields and files for no other method,
     * and `request_parse_body()`, which would, exists from PHP 8.4 on
     *
     * @throws InvalidArgumentException
     */
    private function checkMediaBodies(): void
    {
        foreach ($this->mediaBodies as $body) {
            $mediaType = $body->mediaType->toHeaderValue();

            if ($body->mediaType->essence() === MediaType::MULTIPART_FORM_DATA && $this->method !== Method::POST) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" reads multipart/form-data with %s, and PHP stores the fields and files of such a'
                    . ' body for POST alone',
                    $this->name,
                    $this->method,
                ));
            }

            foreach ($this->mediaBodies as $other) {
                if (
                    $other !== $body
                    && ($other->mediaType->admits($body->mediaType) || $body->mediaType->admits($other->mediaType))
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'the endpoint "%s" reads "%s" and "%s", and a request of one of them would name both',
                        $this->name,
                        $mediaType,
                        $other->mediaType->toHeaderValue(),
                    ));
                }
            }
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function checkPreconditions(): void
    {
        if ($this->preconditionParameter !== null) {
            $this->checkBound($this->preconditionParameter, 'its preconditions');
        }

        if ($this->leavesUnchanged && ($this->isSafe() || $this->preconditionParameter === null)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" declares that it leaves its precondition record unchanged, and only a write on a'
                . ' record of a versioned type has one',
                $this->name,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when the parameter is not bound to a type
     */
    private function checkBound(string $parameter, string $part): void
    {
        if (!array_key_exists($parameter, $this->bindings)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" names the parameter "%s" in %s, and binds it to no type',
                $this->name,
                Printable::of($parameter),
                $part,
            ));
        }
    }
}
