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
use Polunich\WpJsonApi\Document\Included;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Document\MetaDocument;
use Polunich\WpJsonApi\Document\ResourceObject;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\PageMeta;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * the document of one result, chosen by the class of the result alone.
 *
 * this class writes the frame:
 * primary data, `included`, the top-level links and meta, and the `jsonapi` object.
 * resource objects and identifiers come from `ResourceSerializer`,
 * pagination links and `meta.page` from `PaginationWriter`.
 *
 * the top-level self link is the route of the request with its `LinkQuery` (JSON:API, Top Level),
 * written only where `Metadata\RouteTable::fetchRoute()` finds a route that responds to its GET.
 * the library responds to a read on its own route, so it always links it;
 * the 200 of a write links the route of the read on its path, which the declaration may exclude.
 * the pagination links of a page carry the query of that self link.
 * two documents link their primary data instead of that route:
 * - a 201 links the created resource with the query its fetch reads,
 *   whose include paths and fieldsets name fields of that same type;
 *   where the type excludes the fetch of its resources, it writes no self link
 * - a 202 links the monitor with the language parameter of the request alone:
 *   the rest of the query targets the requested type, and the monitor's route may refuse it.
 *   a monitor type always keeps that route, or the API is not built
 *
 * the top-level related link carries `LinkQuery::language()`, as every link inside the data does
 *
 * @internal
 */
final readonly class DocumentBuilder
{
    public function __construct(
        private ResourceSerializer $serializer,
        private PaginationWriter $paginationWriter,
        private RouteUrls $urls,
        private RouteTable $routes,
        private LinkQuery $linkQueries,
    ) {}

    /**
     * @param RouteTarget $target      the route the request was dispatched to
     * @param MediaType   $contentType the media type of the response
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when the result is of a class this builder does not know,
     *                                  the route is an atomic route,
     *                                  a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    public function document(
        Result $result,
        RouteTarget $target,
        ServerRequest $request,
        MediaType $contentType,
    ): AtomicResultsDocument|DataDocument|null {
        $languageQuery = $this->linkQueries->language($request);

        return match (true) {
            $result instanceof NoContentResult, $result instanceof NotModifiedResult => null,
            $result instanceof ResourceResult => $this->resourceDocument(
                $result,
                $this->selfLinks($target, $request, $contentType),
                $request,
                $contentType,
                $languageQuery,
            ),
            $result instanceof CollectionResult => $this->collectionDocument(
                $result,
                $result->page === null
                    ? $this->selfLinks($target, $request, $contentType)
                    : $this->paginationWriter->links(
                        $this->selfLinks($target, $request, $contentType),
                        $result->page,
                        $target,
                        $this->routeQuery($target, $request),
                    ),
                $contentType,
                $languageQuery,
            ),
            $result instanceof RelationshipResult => $this->relationshipDocument(
                $result,
                $this->selfLinks($target, $request, $contentType),
                self::idOf($target),
                $target,
                $request,
                $contentType,
                $languageQuery,
            ),
            $result instanceof AcceptedResult => $this->acceptedDocument($result, $contentType, $languageQuery),
            $result instanceof AtomicResult => $this->atomicDocument($result, $contentType, $languageQuery),
            default => throw new LogicException(sprintf(
                'a result of class %s is no result this library writes a document of; the results are'
                . ' the ones of Execution\Result, and an interface seals no implementation',
                $result::class,
            )),
        };
    }

    /**
     * the self link, the URL of the endpoint with the query `LinkQuery` keeps,
     * is written only for an endpoint that responds to GET, as a self link must.
     * the pagination links of a page carry the same query to the same URL.
     * a 201 and a 202 link their primary data, as on a route
     *
     * @param ServerRequest $request     its path parameters fill the template
     *                                   as the client wrote them
     * @param MediaType     $contentType the media type of the response
     *
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
    public function endpointDocument(
        Result $result,
        EndpointMetadata $endpoint,
        ServerRequest $request,
        MediaType $contentType,
    ): DataDocument|MetaDocument|null {
        $query = $this->linkQueries->toEndpoint($endpoint, $request);
        $links = $endpoint->isSafe()
            ? new Links([Member::SELF => $this->topLevelSelf(
                $this->urls->endpointUrl($endpoint, $request->path, $query),
                $contentType,
            )])
            : new Links([]);
        $languageQuery = $this->linkQueries->language($request);

        return match (true) {
            $result instanceof NoContentResult, $result instanceof NotModifiedResult => null,
            $result instanceof ResourceResult => $this->resourceDocument(
                $result,
                $links,
                $request,
                $contentType,
                $languageQuery,
            ),
            $result instanceof CollectionResult => $this->collectionDocument(
                $result,
                $result->page === null
                    ? $links
                    : $this->paginationWriter->endpointLinks($links, $result->page, $endpoint, $request->path, $query),
                $contentType,
                $languageQuery,
            ),
            $result instanceof RelationshipResult => $this->relationshipDocument(
                $result,
                $links,
                $result->parentId,
                null,
                $request,
                $contentType,
                $languageQuery,
            ),
            $result instanceof MetaResult => new MetaDocument($result->meta, $this->jsonApi($contentType)),
            $result instanceof AcceptedResult => $this->acceptedDocument($result, $contentType, $languageQuery),
            default => throw new LogicException(sprintf(
                'a result of class %s is none an endpoint responds with',
                $result::class,
            )),
        };
    }

    /**
     * the primary data is null on the related route of an empty to-one relationship
     *
     * @param Links                   $self          the top-level self link,
     *                                               where it responds to a GET
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function resourceDocument(
        ResourceResult $result,
        Links $self,
        ServerRequest $request,
        MediaType $contentType,
        array $languageQuery,
    ): DataDocument {
        $record = $result->resource;

        if ($result->status() === 201) {
            $created = $record ?? throw new LogicException(
                'the 201 of a create carries no resource, and the self link of the document is the'
                . ' resource route of the resource that was created [spec, Creating Resources]',
            );

            $fetchRoute = $this->routes->fetchRoute(RouteKind::Resource, $created->type, null);
            $links = $fetchRoute === null
                ? new Links([])
                : new Links([Member::SELF => $this->topLevelSelf(
                    $this->urls->fetchUrl(
                        new RouteTarget(
                            RouteKind::Resource,
                            $created->type,
                            $this->serializer->documentId($created),
                            null,
                        ),
                        $this->linkQueries->to($fetchRoute, $request),
                    ),
                    $contentType,
                )]);
        } else {
            $links = $self;
        }

        return new DataDocument(
            $record === null
                ? null
                : $this->serializer->resource($record, $result->graph, $result->fields, $languageQuery),
            $this->jsonApi($contentType),
            $links,
            $this->included($result->graph, $result->fields, $languageQuery),
        );
    }

    /**
     * a list of resources: a page of a route or of an endpoint, or the whole list of an endpoint
     *
     * @param Links                   $links         the top-level self link where GET reaches it,
     *                                               and the pagination links of a page
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function collectionDocument(
        CollectionResult $result,
        Links $links,
        MediaType $contentType,
        array $languageQuery,
    ): DataDocument {
        $page = $result->page;

        return new DataDocument(
            array_map(
                function (ResourceRecord $record) use ($result, $languageQuery): ResourceObject {
                    return $this->serializer->resource(
                        $record,
                        $result->graph,
                        $result->fields,
                        $languageQuery,
                    );
                },
                $result->records,
            ),
            $this->jsonApi($contentType),
            $links,
            $this->included($result->graph, $result->fields, $languageQuery),
            $page === null ? null : $this->pageMetadata($page),
        );
    }

    /**
     * the one document with a top-level `related` link (JSON:API, Fetching Relationships),
     * written while the related route responds.
     * a to-many linkage pages through the relationship route,
     * which the API keeps wherever a write of the relationship responds with one;
     * the linkage an endpoint responds with is a whole list without pages
     *
     * @param Links                   $self          the top-level self link,
     *                                               where it responds to a GET
     * @param string                  $parentId      the id the related link names
     * @param ?RouteTarget            $target        the route a page links,
     *                                               null for a linkage without pages
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when the route is an atomic route,
     *                                  a resource carries no record,
     *                                  a page lacks a member its strategy needs,
     *                                  or a paged linkage stands
     *                                  where the relationship route does not respond
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function relationshipDocument(
        RelationshipResult $result,
        Links $self,
        string $parentId,
        ?RouteTarget $target,
        ServerRequest $request,
        MediaType $contentType,
        array $languageQuery,
    ): DataDocument {
        $members = $self->all();
        $type = $result->parentType;
        $relationship = $result->relationship;

        if ($this->routes->fetches(RouteKind::Related, $type, $relationship)) {
            $members[Member::RELATED] = $this->urls->fetchUrl(
                new RouteTarget(RouteKind::Related, $type, $parentId, $relationship),
                $languageQuery,
            );
        }

        $links = new Links($members);
        $meta = null;

        if ($result->linkage->isToMany()) {
            $data = array_map($this->serializer->identifier(...), $result->linkage->records());

            if ($result->linkage->isPaged()) {
                if (!$this->routes->fetches(RouteKind::Relationship, $type, $relationship)) {
                    throw new LogicException(sprintf(
                        'the library responds with the linkage of the to-many relationship "%s" of type "%s" where its'
                        . ' relationship route, which its pagination links lead to, is excluded; the API is not built'
                        . ' with such a declaration',
                        $relationship,
                        $type,
                    ));
                }

                $page = $result->linkage->page();
                $route = $target ?? throw new LogicException('a page of a linkage links the route it was read on');
                $links = $this->paginationWriter->links($links, $page, $route, $this->routeQuery($route, $request));
                $meta = $this->pageMetadata($page);
            }
        } else {
            $identifier = $result->linkage->record();
            $data = $identifier === null ? null : $this->serializer->identifier($identifier);
        }

        return new DataDocument(
            $data,
            $this->jsonApi($contentType),
            $links,
            $this->included($result->graph, $result->fields, $languageQuery),
            $meta,
        );
    }

    /**
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws InvalidArgumentException when the primary data holds one type and id pair twice
     * @throws LogicException           when a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function acceptedDocument(
        AcceptedResult $result,
        MediaType $contentType,
        array $languageQuery,
    ): DataDocument {
        $monitor = $result->monitor;

        if (!$this->routes->fetches(RouteKind::Resource, $monitor->type, null)) {
            throw new LogicException(sprintf(
                'the monitor type "%s" excludes the fetch of its resources, through which a client checks the'
                . ' status of the write; the API is not built with such a declaration',
                $monitor->type,
            ));
        }

        return new DataDocument(
            $this->serializer->resource($monitor, $result->graph, $result->fields, $languageQuery),
            $this->jsonApi($contentType),
            new Links([
                Member::SELF => $this->topLevelSelf(
                    $this->urls->fetchUrl(
                        new RouteTarget(
                            RouteKind::Resource,
                            $monitor->type,
                            $this->serializer->documentId($monitor),
                            null,
                        ),
                        $languageQuery,
                    ),
                    $contentType,
                ),
            ]),
            $this->included($result->graph, $result->fields, $languageQuery),
        );
    }

    /**
     * null when every operation returned nothing, the 204 of an atomic result.
     * an entry is the resource of its operation, written with that operation's own graph
     * and fieldsets, or null for the empty result object
     *
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws LogicException           when a resource carries no record,
     *                                  or a page lacks a member its strategy needs
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function atomicDocument(
        AtomicResult $result,
        MediaType $contentType,
        array $languageQuery,
    ): ?AtomicResultsDocument {
        if ($result->status() === 204) {
            return null;
        }

        return new AtomicResultsDocument(
            array_map(
                function (NoContentResult|ResourceResult $entry) use ($languageQuery): ?ResourceObject {
                    return $entry instanceof ResourceResult
                        ? $this->serializer->resource(
                            $entry->resource ?? throw new LogicException(sprintf(
                                'the result of an operation on type "%s" carries no resource, and a result'
                                . ' object either carries the resource of its operation or is the empty'
                                . ' result object [ext-atomic]',
                                Printable::of($entry->type),
                            )),
                            $entry->graph,
                            $entry->fields,
                            $languageQuery,
                        )
                        : null;
                },
                $result->results,
            ),
            $this->jsonApi($contentType),
        );
    }

    /**
     * null unless the graph is compound; a compound document keeps `included` even when empty
     * (JSON:API, Inclusion of Related Resources).
     *
     * included resources take the same fieldsets and graph as the primary data:
     * `fields[TYPE]` applies to every type in the response.
     * repeated and primary resources are dropped by `DataDocument` and `Included`, not here
     *
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws LogicException           when a page lacks a member its strategy needs;
     *                                  `RecordGraph` already refuses a record-less resource
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     */
    private function included(RecordGraph $graph, SparseFieldsets $fields, array $languageQuery): ?Included
    {
        if (!$graph->isCompound()) {
            return null;
        }

        return new Included(array_map(
            function (ResourceRecord $record) use ($graph, $fields, $languageQuery): ResourceObject {
                return $this->serializer->resource($record, $graph, $fields, $languageQuery);
            },
            $graph->included(),
        ));
    }

    /**
     * `meta.page` alone, or null when the page reports nothing: an empty meta object is left out
     *
     * @return array<string, mixed>|null
     */
    private function pageMetadata(Page $page): ?array
    {
        $metadata = $this->paginationWriter->metadata($page);

        return $metadata === [] ? null : [PageMeta::MEMBER => $metadata];
    }

    /**
     * a link object naming the media type once an extension or a profile is applied,
     * as JSON:API advises for the top-level self link (Top Level)
     */
    private function topLevelSelf(string $href, MediaType $contentType): Link|string
    {
        if ($contentType->ext() === [] && $contentType->profile() === []) {
            return $href;
        }

        return new Link($href, type: $contentType->toHeaderValue());
    }

    private function jsonApi(MediaType $contentType): JsonApiObject
    {
        return new JsonApiObject(JsonApiObject::VERSION, $contentType->ext(), $contentType->profile());
    }

    /**
     * @throws LogicException       when the route is an atomic route,
     *                              which carries no primary data
     * @throws OutOfBoundsException when the type or the relationship of the route is not declared
     */
    private function selfLinks(RouteTarget $target, ServerRequest $request, MediaType $contentType): Links
    {
        if ($target->kind === RouteKind::Atomic) {
            throw self::noPrimaryData();
        }

        $fetchRoute = $this->routes->fetchRoute($target->kind, self::typeOf($target), $target->relationship);

        if ($fetchRoute === null) {
            return new Links([]);
        }

        return new Links([Member::SELF => $this->topLevelSelf(
            $this->urls->fetchUrl($target, $this->linkQueries->to($fetchRoute, $request)),
            $contentType,
        )]);
    }

    /**
     * the query of the self link of the route;
     * none where the route does not respond to GET, which then reads no page
     *
     * @return array<array-key, mixed>
     *
     * @throws LogicException       when the route is an atomic route,
     *                              which carries no primary data
     * @throws OutOfBoundsException when the type or the relationship of the route is not declared
     */
    private function routeQuery(RouteTarget $target, ServerRequest $request): array
    {
        if ($target->kind === RouteKind::Atomic) {
            throw self::noPrimaryData();
        }

        $fetchRoute = $this->routes->fetchRoute($target->kind, self::typeOf($target), $target->relationship);

        return $fetchRoute === null ? [] : $this->linkQueries->to($fetchRoute, $request);
    }

    private static function noPrimaryData(): LogicException
    {
        return new LogicException(
            'an atomic route carries a document of results and no primary data, so no'
            . ' top-level self link is written for it [ext-atomic]',
        );
    }

    /**
     * the throw only narrows the type, here and in `idOf()`:
     * `RouteTarget` already refuses a missing member its kind addresses
     */
    private static function typeOf(RouteTarget $target): string
    {
        if ($target->type === null) {
            throw new LogicException('the ' . $target->kind->value . ' route addresses no type');
        }

        return $target->type;
    }

    private static function idOf(RouteTarget $target): string
    {
        if ($target->id === null) {
            throw new LogicException('the ' . $target->kind->value . ' route addresses no id');
        }

        return $target->id;
    }
}
