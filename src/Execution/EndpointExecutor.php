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

namespace Polunich\WpJsonApi\Execution;

use InvalidArgumentException;
use JsonException;
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Endpoint\ResponseKind;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\ContentResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/**
 * runs an endpoint: loads the records its parameters bind, asks the second level of its permission,
 * then runs its handler and turns the response into a result.
 * a page response is paged as on the collection route,
 * from the slice the handler read for its query.
 *
 * a response must have a status the endpoint declares, in the shape declared for it,
 * and a record response must be the bound record by type and id;
 * anything else is a fault of the handler, thrown as a `LogicException`.
 *
 * a handler's `Store\VersionMismatchException` is the 412 of an `If-Match` failing in the write,
 * or a fault of the handler where the request carried no precondition or `If-Match: *`
 *
 * @internal
 */
final readonly class EndpointExecutor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Gate $gate,
        private ImplementationResolver $implementations,
        private IncludeLoader $includes,
        private Preconditions $preconditions,
        private Paginator $paginator,
    ) {}

    /**
     * each record is loaded with every field, since the handler and the permission read it,
     * and with the query parameters the endpoint declares, since it is the target of the endpoint
     *
     * @param array<string, string> $path            every parameter of the path, decoded
     * @param Values<mixed>         $queryParameters
     *
     * @return array<string, object> by the name of the parameter
     *
     * @throws NotFoundException        404 when a record is missing or hidden from the client
     * @throws JsonApiException         the 401 or 403 of the second level of the permission
     * @throws UnexpectedValueException when a loader breaks its batch contract
     * @throws LogicException           when the path holds no value of a bound parameter,
     *                                  or the type binds no resource loader
     * @throws OutOfBoundsException     when a bound type is not declared
     */
    public function loadRecords(
        EndpointMetadata $endpoint,
        array $path,
        LanguagePreferences $languages,
        Values $queryParameters,
        ExecutionContext $context,
    ): array {
        $records = [];

        foreach ($endpoint->bindings as $parameter => $type) {
            $id = $path[$parameter] ?? throw new LogicException(sprintf(
                'the endpoint "%s" binds the parameter "%s", and the path holds no value of it',
                $endpoint->name,
                $parameter,
            ));

            $records[$parameter] = $this->store->load(new LoadRequest(
                $type,
                [$id],
                new FieldHint(null),
                $languages,
                $queryParameters,
            ))[0] ?? throw new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]);
        }

        if ($records !== []) {
            $this->gate->checkEndpoint(
                $endpoint,
                EndpointAttempt::forRecords($endpoint->name, $context->request, $records),
                $context->securitySchemes,
            );
        }

        return $records;
    }

    /**
     * the query the handler of a page response reads its slice for,
     * hinted with the fields the library reads itself, as on the collection route
     *
     * @param ?CollectionReading $reading         `null` without a page response
     * @param Values<mixed>      $queryParameters
     *
     * @throws LogicException           when the reading is missing for a page response,
     *                                  or given for another
     * @throws InvalidArgumentException when the reading sort lacks `id`
     * @throws OutOfBoundsException     when the type of the page is not declared
     */
    public function collectionQuery(
        EndpointMetadata $endpoint,
        ?CollectionReading $reading,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        Values $queryParameters,
    ): ?CollectionQuery {
        if (($reading === null) !== ($endpoint->pageType() === null)) {
            throw new LogicException(sprintf(
                'the request of the endpoint "%s" carries %s, and a request carries the reading of a collection'
                . ' exactly where its endpoint responds with a page',
                $endpoint->name,
                $reading === null ? 'no reading of a collection' : 'the reading of a collection',
            ));
        }

        if ($reading === null) {
            return null;
        }

        return $this->paginator->collectionQuery(
            $reading->type,
            $reading->filter,
            $reading->readingSort,
            $reading->page,
            $this->resources->endpointPaginationPolicy($endpoint),
            $this->includes->fieldHint($reading->type, $fields, $reading->readingSort),
            $languages,
            null,
            $queryParameters,
        );
    }

    /**
     * @param ?CollectionReading $reading `null` without a page response
     *
     * @throws JsonApiException         412 for a precondition failing inside the handler's write,
     *                                  and whatever the handler raises
     * @throws LogicException           when the response is none the endpoint declares
     * @throws UnexpectedValueException when an accessor or a codec returns what it may not,
     *                                  the handler fails a precondition it was not given,
     *                                  its slice breaks the window of the query,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    public function run(
        EndpointMetadata $endpoint,
        Request $request,
        ?IncludePaths $include,
        SparseFieldsets $fields,
        ?CollectionReading $reading,
        ExecutionContext $context,
    ): Result {
        try {
            $response = $this->implementations->resolve($endpoint->handler, Handler::class)->handle($request);
        } catch (VersionMismatchException $failure) {
            if ($request->precondition === null) {
                throw new UnexpectedValueException(sprintf(
                    'the handler of the endpoint "%s" reports a failed precondition, and its request carries none, so'
                    . ' its write has no condition that could fail',
                    $endpoint->name,
                ), 0, $failure);
            }

            if ($request->precondition->versions === null) {
                throw new UnexpectedValueException(sprintf(
                    'the handler of the endpoint "%s" reports a failed precondition, and its request carries'
                    . ' If-Match: *, which admits every stored version; a record that is gone is not found instead',
                    $endpoint->name,
                ), 0, $failure);
            }

            throw Preconditions::preconditionFailed(FieldName::IF_MATCH);
        }

        $status = $response->kind()->status();
        $this->checkRepresentation($endpoint, $response, $request);

        if ($response->kind() === ResponseKind::Content || $response->kind() === ResponseKind::Json) {
            return $this->mediaResponse($endpoint, $response);
        }

        $declared = $endpoint->response($status) ?? throw new LogicException(sprintf(
            'the handler of the endpoint "%s" responds with %d, which the endpoint does not declare',
            $endpoint->name,
            $status,
        ));
        $graph = new GraphRequest($include, $fields, $request->languages);

        return match ($response->kind()) {
            ResponseKind::Resource => $this->resource($endpoint, $declared, $response, $graph, $request, $context),
            ResponseKind::Created => $this->created($endpoint, $declared, $response, $graph, $context),
            ResponseKind::Collection => $this->collection($endpoint, $declared, $response, $graph, $context),
            ResponseKind::Page => $this->page($endpoint, $declared, $response, $graph, $request, $reading, $context),
            ResponseKind::Linkage => $this->linkage($endpoint, $declared, $response, $graph, $request, $context),
            ResponseKind::Meta => $this->meta($endpoint, $declared, $response),
            ResponseKind::Accepted => $this->accepted($endpoint, $declared, $response, $graph, $context),
            ResponseKind::NoContent => new NoContentResult(),
        };
    }

    /**
     * a 200 comes in the media type the request picked, whenever the endpoint's 200 offers it;
     * otherwise the response is checked only against what its status declares
     *
     * @throws LogicException
     */
    private function checkRepresentation(EndpointMetadata $endpoint, Response $response, Request $request): void
    {
        if ($response->kind()->status() !== 200) {
            return;
        }

        $picked = MediaType::parse($request->mediaType);

        if (
            $picked === null
            || !($picked->isJsonApi() ? $endpoint->response(200) !== null : $endpoint->mediaResponse($picked) !== null)
        ) {
            return;
        }

        $text = $response->mediaType();
        $mediaType = $text === null ? MediaType::jsonApi() : MediaType::parse($text);

        if ($mediaType === null || $mediaType->toHeaderValue() !== $picked->toHeaderValue()) {
            throw new LogicException(sprintf(
                'the handler of the endpoint "%s" responds with 200 in "%s", and the request picked "%s",'
                . ' which 200 offers',
                $endpoint->name,
                Printable::of($text ?? MediaType::JSON_API),
                $request->mediaType,
            ));
        }
    }

    /**
     * the content is opened before any status is sent, so a file that cannot be opened,
     * or chunks failing on their first piece, end in an error response
     *
     * @throws LogicException           when the endpoint declares no such media response
     * @throws RuntimeException         when the file cannot be opened, or the stream was closed
     * @throws UnexpectedValueException when the first chunk is no string
     * @throws JsonException            when the encoded value is no JSON text
     */
    private function mediaResponse(EndpointMetadata $endpoint, Response $response): ContentResult
    {
        $text = $response->mediaType() ?? throw new LogicException('a media response names its media type');
        $mediaType = MediaType::parse($text);
        $declared = $mediaType === null ? null : $endpoint->mediaResponse($mediaType);
        $json = $response->kind() === ResponseKind::Json;

        if ($mediaType === null || $declared === null || ($declared->codec !== null) !== $json) {
            throw new LogicException(sprintf(
                'the handler of the endpoint "%s" responds with 200 and %s in "%s",'
                . ' which the endpoint does not declare',
                $endpoint->name,
                $json ? 'a JSON value' : 'content',
                Printable::of($text),
            ));
        }

        if ($declared->codec !== null) {
            $content = Content::bytes(json_encode(
                $declared->codec->encode($response->payload()),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $content = $response->payload();

            if (!$content instanceof Content) {
                throw new LogicException('a content response carries an Http\\Content');
            }
        }

        $content->open();

        return new ContentResult($content, $declared->mediaType, $response->attachmentName());
    }

    /**
     * a resource of the type, the bound record, or the related resource of a to-one relationship,
     * which alone may be null
     */
    private function resource(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        Request $request,
        ExecutionContext $context,
    ): ResourceResult {
        $toOne = $declared->kind === ResponseShapeKind::Related && !$declared->isList();

        if (!in_array($declared->kind, [ResponseShapeKind::Resource, ResponseShapeKind::Record], true) && !$toOne) {
            throw self::undeclared($endpoint, $declared, 'one resource');
        }

        $type = self::typeOf($declared);
        $record = $response->payload();

        if ($record === null && $toOne) {
            return new ResourceResult(
                $type,
                null,
                $this->includes->load($graph, [], $context),
                $graph->fields,
                200,
                null,
            );
        }

        if (!is_object($record)) {
            throw self::undeclared($endpoint, $declared, 'no resource');
        }

        $resource = ResourceRecord::of($this->resources->get($type), $record);
        $tag = null;

        if ($declared->kind === ResponseShapeKind::Record) {
            $parameter = (string) $declared->parameter;
            $bound = ResourceRecord::of($this->resources->get($type), $request->record($parameter));

            if ($bound->id !== $resource->id) {
                throw new LogicException(sprintf(
                    'the endpoint "%s" declares that it responds with the record bound to "%s", of id "%s", and its'
                    . ' handler returns the %s resource "%s"',
                    $endpoint->name,
                    $parameter,
                    Printable::of($bound->id),
                    $type,
                    Printable::of($resource->id),
                ));
            }

            if ($endpoint->carriesEntityTag($declared)) {
                $tag = $this->preconditions->entityTag($type, $record, $context);
            }
        }

        return new ResourceResult(
            $type,
            $resource,
            $this->includes->load($graph, [$resource], $context),
            $graph->fields,
            200,
            $tag,
        );
    }

    private function created(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        ExecutionContext $context,
    ): ResourceResult {
        $type = self::typeOf($declared);
        $record = self::object($endpoint, $declared, $response->payload());
        $resource = ResourceRecord::of($this->resources->get($type), $record);

        return new ResourceResult(
            $type,
            $resource,
            $this->includes->load($graph, [$resource], $context),
            $graph->fields,
            201,
            $endpoint->carriesEntityTag($declared) ? $this->preconditions->entityTag($type, $record, $context) : null,
        );
    }

    private function collection(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        ExecutionContext $context,
    ): CollectionResult {
        if (
            $declared->kind !== ResponseShapeKind::Collection
            && !($declared->kind === ResponseShapeKind::Related && $declared->isList())
        ) {
            throw self::undeclared($endpoint, $declared, 'a collection');
        }

        $type = self::typeOf($declared);
        $resources = $this->records($type, $response->payload());

        return CollectionResult::whole(
            $type,
            $resources,
            $this->includes->load($graph, $resources, $context),
            $graph->fields,
        );
    }

    /**
     * the slice is held to the window of the query the handler received,
     * and its cursors are written in the order the reading ran in
     *
     * @throws LogicException           when the endpoint declares no page for 200,
     *                                  or the request carries no reading
     * @throws UnexpectedValueException when the slice breaks the window,
     *                                  an accessor returns what it may not,
     *                                  or a boundary cursor cannot be written
     */
    private function page(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        Request $request,
        ?CollectionReading $reading,
        ExecutionContext $context,
    ): CollectionResult {
        if ($declared->kind !== ResponseShapeKind::Page) {
            throw self::undeclared($endpoint, $declared, 'a page');
        }

        $query = $request->collectionQuery;

        if ($reading === null || $query === null) {
            throw new LogicException(sprintf(
                'the endpoint "%s" responds with a page, and its request carries no reading of a collection, which'
                . ' the parser reads for every request of a page response',
                $endpoint->name,
            ));
        }

        $slice = $response->payload();

        if (!$slice instanceof Slice) {
            throw new LogicException('a page response carries a Store\\Slice');
        }

        StoreGateway::checkSlice($slice, $query, sprintf('the handler of the endpoint "%s"', $endpoint->name));

        $type = self::typeOf($declared);
        $page = $this->paginator->page(
            $reading->page,
            $request->languages,
            $slice,
            $this->resources->endpointPaginationPolicy($endpoint),
            $type,
            $reading->readingSort,
        );

        return CollectionResult::paged(
            $type,
            $page,
            $this->includes->load($graph, $page->records, $context),
            $graph->fields,
        );
    }

    private function linkage(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        Request $request,
        ExecutionContext $context,
    ): RelationshipResult {
        if ($declared->kind !== ResponseShapeKind::Linkage) {
            throw self::undeclared($endpoint, $declared, 'a linkage');
        }

        $parameter = (string) $declared->parameter;
        $relationship = (string) $declared->relationship;
        $parentType = $endpoint->bindings[$parameter];
        $parent = $request->record($parameter);
        $relatedType = self::typeOf($declared);
        $payload = $response->payload();

        if ($declared->isList()) {
            $related = $this->records($relatedType, $payload);
            $linkage = RecordLinkage::toManyWhole($related);
        } else {
            if ($payload !== null && !is_object($payload)) {
                throw self::undeclared($endpoint, $declared, 'a list');
            }

            $related = $payload === null ? [] : [ResourceRecord::of($this->resources->get($relatedType), $payload)];
            $linkage = RecordLinkage::toOne($related[0] ?? null);
        }

        return new RelationshipResult(
            $parentType,
            ResourceRecord::of($this->resources->get($parentType), $parent)->id,
            $relationship,
            $relatedType,
            $linkage,
            $this->includes->loadFromRelationship(
                $graph,
                $parentType,
                $relationship,
                $related,
                $context->withRecord($parent),
            ),
            $graph->fields,
            null,
        );
    }

    /**
     * "The value of each meta member MUST be an object" (JSON:API, Meta Information);
     * `[]` passes as the empty object, since PHP cannot tell it from the empty list
     */
    private function meta(EndpointMetadata $endpoint, ResponseMetadata $declared, Response $response): MetaResult
    {
        $codec = $declared->codec ?? throw self::undeclared($endpoint, $declared, 'meta');
        $encoded = $codec->encode($response->payload());

        if ($encoded instanceof stdClass) {
            return new MetaResult(get_object_vars($encoded));
        }

        if (is_array($encoded) && !array_is_list($encoded)) {
            return new MetaResult($encoded);
        }

        if ($encoded === []) {
            return new MetaResult([]);
        }

        throw new LogicException(sprintf(
            'the meta codec of the endpoint "%s" encodes %s, and the meta of a document is an object',
            $endpoint->name,
            get_debug_type($encoded),
        ));
    }

    private function accepted(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        Response $response,
        GraphRequest $graph,
        ExecutionContext $context,
    ): AcceptedResult {
        $type = self::typeOf($declared);
        $monitor = ResourceRecord::of(
            $this->resources->get($type),
            self::object($endpoint, $declared, $response->payload()),
        );

        return new AcceptedResult(
            $monitor,
            $this->includes->loadMonitor($graph->languages, $monitor, $context),
            $graph->fields,
        );
    }

    /**
     * @return list<ResourceRecord>
     *
     * @throws LogicException when the payload is no list of records
     */
    private function records(string $type, mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new LogicException(sprintf('a response of a list of %s resources carries no list', $type));
        }

        $resource = $this->resources->get($type);
        $records = [];

        foreach ($payload as $record) {
            if (!is_object($record)) {
                throw new LogicException(sprintf(
                    'a response of a list of %s resources carries %s',
                    $type,
                    get_debug_type($record),
                ));
            }

            $records[] = ResourceRecord::of($resource, $record);
        }

        return $records;
    }

    private static function object(EndpointMetadata $endpoint, ResponseMetadata $declared, mixed $payload): object
    {
        return is_object($payload) ? $payload : throw self::undeclared($endpoint, $declared, 'no resource');
    }

    private static function typeOf(ResponseMetadata $declared): string
    {
        return $declared->type ?? throw new LogicException(sprintf(
            '%s names the type of its resources',
            $declared->kind->phrase(),
        ));
    }

    private static function undeclared(
        EndpointMetadata $endpoint,
        ResponseMetadata $declared,
        string $returned,
    ): LogicException {
        return new LogicException(sprintf(
            'the handler of the endpoint "%s" returns %s with %d, and the endpoint declares %s for it',
            $endpoint->name,
            $returned,
            $declared->status(),
            $declared->kind->phrase(),
        ));
    }
}
