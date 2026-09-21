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

namespace Polunich\WpJsonApi\OpenApi;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\QueryText;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\RouteKind;
use stdClass;

/**
 * a method is written only where the declaration enables it: any other kind gets 403,
 * so it has no successful response to document.
 * likewise a status is written only where a request the contract documents can produce it
 *
 * @internal
 */
final readonly class PathBuilder
{
    private ParameterBuilder $parameters;

    private DocumentSchemaBuilder $documents;

    public function __construct(private ApiMetadata $api)
    {
        $this->parameters = new ParameterBuilder($api);
        $this->documents = new DocumentSchemaBuilder($api);
    }

    /**
     * @return array<string, stdClass>
     */
    public function paths(): array
    {
        /** @var array<string, array<string, mixed>> $items */
        $items = [];

        foreach ($this->api->routes->all() as $route) {
            $path = $route->template->path;

            if (!$this->api->routes->enables($route)) {
                continue;
            }

            $items[$path] ??= [];

            if ($route instanceof OperationRoute && !array_key_exists('parameters', $items[$path])) {
                $items[$path] = self::pathParameters($route) + $items[$path];
            }

            $items[$path][self::fieldOf($route)] = $this->operationOf($route);
        }

        return array_map(static function (array $item): stdClass {
            return (object) $item;
        }, $items);
    }

    /**
     * @throws LogicException when a route of a relationship kind names no relationship,
     *                        which `Metadata\OperationRoute` refuses
     */
    private function operationOf(Route $route): stdClass
    {
        if ($route instanceof EndpointRoute) {
            return $this->endpointOperation($route->endpoint);
        }

        if ($route instanceof AtomicRoute) {
            return $this->atomicOperation($route->metadata);
        }

        $resource = $this->api->resources->get($route->type);

        if ($route->relationship === null) {
            return match ($route->operationKind) {
                OperationKind::FetchCollection => $this->fetchCollection($resource),
                OperationKind::CreateResource => $this->createResource($resource),
                OperationKind::FetchResource => $this->fetchResource($resource),
                OperationKind::UpdateResource => $this->updateResource($resource),
                OperationKind::DeleteResource => $this->deleteResource($resource),
                default => throw new LogicException(sprintf(
                    'the %s route of type "%s" names no relationship, and a route of its kind names one',
                    $route->operationKind->value,
                    $route->type,
                )),
            };
        }

        $relationship = $this->api->resources->relationshipOf($route->type, $route->relationship);

        return match ($route->operationKind) {
            OperationKind::FetchRelated => $this->fetchRelated($resource, $relationship),
            OperationKind::FetchRelationship => $this->fetchRelationship($resource, $relationship),
            default => $this->relationshipWrite($route->operationKind, $resource, $relationship),
        };
    }

    private function fetchCollection(ResourceMetadata $resource): stdClass
    {
        $responses = [
            200 => self::documentResponse(
                sprintf('the resources of type "%s" the request selected', $resource->type),
                DocumentSchemaBuilder::collectionName($resource->type),
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::FetchCollection, $resource->type, null),
            sprintf('reads the collection of type "%s"', $resource->type),
            sprintf(
                'reads the resources of type "%s" the filter selects, in the order of the sort and in the page'
                . ' the request asks for',
                $resource->type,
            ),
            false,
            $this->parameters->parameters(OperationKind::FetchCollection, $resource->type),
            null,
            $responses + $this->errorResponses(OperationKind::FetchCollection, $resource),
            $resource->securitySchemesOf(OperationKind::FetchCollection),
        );
    }

    private function fetchResource(ResourceMetadata $resource): stdClass
    {
        $versioned = $resource->versionPolicy !== null;
        $responses = [
            200 => self::documentResponse(
                sprintf('the resource of type "%s" the URL names', $resource->type),
                DocumentSchemaBuilder::documentName($resource->type),
                $versioned ? [FieldName::ETAG] : [],
            ),
        ];

        if ($versioned) {
            $responses[304] = self::notModified();
        }

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::FetchResource, $resource->type, null),
            sprintf('reads one resource of type "%s"', $resource->type),
            sprintf('reads the resource of type "%s" the URL names', $resource->type),
            false,
            array_merge(
                $this->parameters->parameters(OperationKind::FetchResource, $resource->type),
                $versioned ? [self::ifNoneMatchParameter()] : [],
            ),
            null,
            $responses + $this->errorResponses(OperationKind::FetchResource, $resource),
            $resource->securitySchemesOf(OperationKind::FetchResource),
        );
    }

    /**
     * the 201 of a versioned type carries `ETag` too: in a 201 it names the created representation,
     * which a later conditional write can send back.
     * `Location` is the self link of the created resource,
     * which a type that excludes the fetch of its resources does not write
     */
    private function createResource(ResourceMetadata $resource): stdClass
    {
        $headers = $this->api->routes->fetches(RouteKind::Resource, $resource->type, null) ? [FieldName::LOCATION] : [];

        if ($resource->versionPolicy !== null) {
            $headers[] = FieldName::ETAG;
        }

        $responses = [
            201 => self::documentResponse(
                sprintf('the resource of type "%s" the request created [spec]', $resource->type),
                DocumentSchemaBuilder::documentName($resource->type),
                $headers,
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::CreateResource, $resource->type, null),
            sprintf('creates one resource of type "%s"', $resource->type),
            sprintf(
                'creates a resource of type "%s" from the resource object of the request document',
                $resource->type,
            ),
            false,
            $this->parameters->parameters(OperationKind::CreateResource, $resource->type),
            self::requestBody(
                DocumentSchemaBuilder::createRequestName($resource->type),
                sprintf('the resource of type "%s" to create', $resource->type),
            ),
            $responses
                + self::acceptedResponse($resource, OperationKind::CreateResource)
                + $this->errorResponses(OperationKind::CreateResource, $resource),
            $resource->securitySchemesOf(OperationKind::CreateResource),
        );
    }

    private function updateResource(ResourceMetadata $resource): stdClass
    {
        $responses = [
            200 => self::documentResponse(
                sprintf('the resource of type "%s" as the update left it', $resource->type),
                DocumentSchemaBuilder::documentName($resource->type),
                $resource->versionPolicy !== null ? [FieldName::ETAG] : [],
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::UpdateResource, $resource->type, null),
            sprintf('updates one resource of type "%s"', $resource->type),
            sprintf(
                'updates the resource of type "%s" the URL names; a field the document leaves out keeps its'
                . ' value',
                $resource->type,
            ),
            false,
            array_merge(
                $this->parameters->parameters(OperationKind::UpdateResource, $resource->type),
                [self::ifMatchParameter($resource)],
            ),
            self::requestBody(
                DocumentSchemaBuilder::updateRequestName($resource->type),
                sprintf('the resource of type "%s" to update', $resource->type),
            ),
            $responses
                + self::acceptedResponse($resource, OperationKind::UpdateResource)
                + $this->errorResponses(OperationKind::UpdateResource, $resource),
            $resource->securitySchemesOf(OperationKind::UpdateResource),
        );
    }

    private function deleteResource(ResourceMetadata $resource): stdClass
    {
        $responses = [
            204 => self::emptyResponse(
                sprintf(
                    'the resource of type "%s" is deleted and the response carries no content',
                    $resource->type,
                ),
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::DeleteResource, $resource->type, null),
            sprintf('deletes one resource of type "%s"', $resource->type),
            sprintf('deletes the resource of type "%s" the URL names', $resource->type),
            false,
            array_merge(
                $this->parameters->parameters(OperationKind::DeleteResource, $resource->type),
                [self::ifMatchParameter($resource)],
            ),
            null,
            $responses
                + self::acceptedResponse($resource, OperationKind::DeleteResource)
                + $this->errorResponses(OperationKind::DeleteResource, $resource),
            $resource->securitySchemesOf(OperationKind::DeleteResource),
        );
    }

    private function fetchRelated(ResourceMetadata $resource, RelationshipMetadata $relationship): stdClass
    {
        $responses = [
            200 => self::documentResponse(
                sprintf(
                    'the resources of type "%s" the relationship "%s" points at',
                    $relationship->relatedType,
                    $relationship->name,
                ),
                DocumentSchemaBuilder::relatedDocumentName($resource->type, $relationship->name),
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::FetchRelated, $resource->type, $relationship->name),
            sprintf('reads what the relationship "%s" points at', $relationship->name),
            sprintf(
                'reads the resources of type "%s" the relationship "%s" of the named resource points at; a'
                . ' to-many relationship carries the whole feature set of a collection here',
                $relationship->relatedType,
                $relationship->name,
            ),
            $relationship->deprecated,
            $this->parameters->parameters(OperationKind::FetchRelated, $resource->type, $relationship->name),
            null,
            $responses + $this->errorResponses(OperationKind::FetchRelated, $resource),
            $relationship->securitySchemesOf(OperationKind::FetchRelated),
        );
    }

    private function fetchRelationship(ResourceMetadata $resource, RelationshipMetadata $relationship): stdClass
    {
        $versioned = $resource->versionPolicy !== null;
        $responses = [
            200 => self::documentResponse(
                sprintf('the linkage of the relationship "%s" of the named resource', $relationship->name),
                DocumentSchemaBuilder::relationshipDocumentName($resource->type, $relationship->name),
                $versioned ? [FieldName::ETAG] : [],
            ),
        ];

        if ($versioned) {
            $responses[304] = self::notModified();
        }

        return $this->operation(
            $resource->type,
            self::operationId(OperationKind::FetchRelationship, $resource->type, $relationship->name),
            sprintf('reads the linkage of the relationship "%s"', $relationship->name),
            sprintf(
                'reads the resource linkage of the relationship "%s" of the named resource; an include path of'
                . ' this route is written from type "%s" and starts with the name of the relationship',
                $relationship->name,
                $resource->type,
            ),
            $relationship->deprecated,
            array_merge(
                $this->parameters->parameters(OperationKind::FetchRelationship, $resource->type, $relationship->name),
                $versioned ? [self::ifNoneMatchParameter()] : [],
            ),
            null,
            $responses + $this->errorResponses(OperationKind::FetchRelationship, $resource),
            $relationship->securitySchemesOf(OperationKind::FetchRelationship),
        );
    }

    private function relationshipWrite(
        OperationKind $kind,
        ResourceMetadata $resource,
        RelationshipMetadata $relationship,
    ): stdClass {
        $responses = [
            200 => self::documentResponse(
                sprintf('the linkage of the relationship "%s" as the write left it [spec]', $relationship->name),
                DocumentSchemaBuilder::relationshipDocumentName($resource->type, $relationship->name),
                $resource->versionPolicy !== null ? [FieldName::ETAG] : [],
            ),
        ];

        return $this->operation(
            $resource->type,
            self::operationId($kind, $resource->type, $relationship->name),
            sprintf('%s the relationship "%s"', self::verbOf($kind), $relationship->name),
            self::relationshipWriteText($kind, $relationship),
            $relationship->deprecated,
            array_merge(
                $this->parameters->parameters($kind, $resource->type, $relationship->name),
                [self::ifMatchParameter($resource)],
            ),
            self::requestBody(
                DocumentSchemaBuilder::relationshipRequestName($resource->type, $relationship->name),
                sprintf('the linkage the relationship "%s" is written with', $relationship->name),
            ),
            $responses
                + self::acceptedResponse($resource, $kind)
                + $this->errorResponses($kind, $resource),
            $relationship->securitySchemesOf($kind),
        );
    }

    private function atomicOperation(AtomicRouteMetadata $atomicRoute): stdClass
    {
        $mediaType = MediaType::jsonApi([MediaType::ATOMIC_EXTENSION])->toHeaderValue();
        $responses = [
            200 => self::documentResponse(
                'one result per operation: "The results array MUST be the same length as the requested'
                . ' operations array and each result MUST correspond positionally to its associated'
                . ' operation." [ext-atomic]',
                AtomicSchemaBuilder::resultsName($atomicRoute),
                [],
                $mediaType,
            ),
            204 => self::emptyResponse(
                'no operation of the request returns data, so the response carries no content'
                . ' [ext-atomic]',
            ),
        ];

        return $this->operation(
            TagBuilder::atomicTag($atomicRoute),
            $atomicRoute->name,
            'performs a series of operations atomically',
            $atomicRoute->description ?? 'performs the operations of the request in order and all or nothing: "A'
            . ' server MUST perform all operations atomically, so that a failure to perform any operation MUST'
            . ' invalidate any effects of preceding operations." [ext-atomic] an operation may name a resource a'
            . ' prior operation created by the lid that operation assigned it',
            false,
            $this->parameters->atomicParameters(),
            self::requestBody(
                AtomicSchemaBuilder::operationsName($atomicRoute),
                'the operations to perform, in the order they are performed [ext-atomic]',
                $mediaType,
            ),
            $responses + $this->errorResponses(null, null, $atomicRoute),
            $atomicRoute->securitySchemes,
        );
    }

    private function endpointOperation(EndpointMetadata $endpoint): stdClass
    {
        $precondition = $endpoint->preconditionParameter === null
            ? null
            : $this->api->resources->get($endpoint->bindings[$endpoint->preconditionParameter]);
        $parameters = array_merge(
            self::endpointPathParameters($endpoint),
            $this->parameters->endpointParameters($endpoint),
        );

        if ($precondition !== null) {
            $parameters[] = $endpoint->isSafe()
                ? self::ifNoneMatchParameter()
                : $this->endpointIfMatch($endpoint, $precondition);
        }

        $responses = [];

        foreach ($endpoint->responses as $status => $declared) {
            $responses[$status] = $this->endpointResponse($endpoint, $declared);
        }

        if ($endpoint->mediaResponses !== []) {
            $responses[200] = $this->withMediaResponses($endpoint, $responses[200] ?? null);
        }

        if ($precondition !== null && $endpoint->isSafe()) {
            $responses[304] = self::notModified();
        }

        $responses += $this->endpointErrorResponses($endpoint, $precondition);
        ksort($responses);

        return $this->operation(
            $endpoint->tag ?? ($endpoint->bindings === [] ? null : array_values($endpoint->bindings)[0]),
            $endpoint->name,
            null,
            $endpoint->description,
            false,
            $parameters,
            $this->endpointRequestBody($endpoint),
            $responses,
            $endpoint->securitySchemes,
        );
    }

    /**
     * bytes get an empty media type object:
     * "Content transferred in binary (octet-stream) MAY omit schema" (OAS 3.1.2, 4.8.14.3).
     * a request without content is read as the empty content of the first body,
     * so the body is required unless that first body is a form that requires nothing
     */
    private function endpointRequestBody(EndpointMetadata $endpoint): ?stdClass
    {
        $content = [];
        $first = null;

        if ($endpoint->body !== null) {
            $content[MediaType::JSON_API] = (object) ['schema' => $this->documents->endpointBody($endpoint->body)];
        }

        foreach ($endpoint->mediaBodies as $body) {
            $first ??= $content === [] ? $body : null;
            $content[$body->mediaType->toHeaderValue()] = match ($body->kind) {
                MediaBodyShapeKind::Json => (object) [
                    'schema' => ($body->codec ?? throw new LogicException('a JSON body carries its codec'))
                        ->schema()
                        ->forRequest()
                        ->toObject(),
                ],
                MediaBodyShapeKind::Form => self::formMediaType($body),
                MediaBodyShapeKind::Bytes => new stdClass(),
            };
        }

        if ($content === []) {
            return null;
        }

        $body = [
            'description' => sprintf(
                'the body the endpoint "%s" reads, picked by the Content-Type of the request',
                $endpoint->name,
            ),
            'content' => $content,
        ];

        if ($first === null || $first->kind !== MediaBodyShapeKind::Form || self::requiresPart($first)) {
            $body['required'] = true;
        }

        return (object) $body;
    }

    /**
     * a form as an object of its fields and files: a field by the schema of its codec,
     * written as a query value is, and a file as a schema without type,
     * which a multipart body sends as `application/octet-stream` (OAS 3.1.2).
     * `additionalProperties: false`: the server refuses a field or a file the form does not declare
     */
    private static function formMediaType(MediaBodyMetadata $body): stdClass
    {
        $properties = [];
        $required = [];
        $encoding = [];

        foreach ($body->fields as $field) {
            $schema = $field->codec->schema()->forRequest();
            $properties[$field->name] = $schema->toObject();

            if ($field->required) {
                $required[] = $field->name;
            }

            if (QueryText::shape($schema) !== QueryText::SCALAR) {
                $encoding[$field->name] = (object) ['style' => 'deepObject', 'explode' => true];
            }
        }

        foreach ($body->files as $name => $fileRequired) {
            $properties[(string) $name] = (object) ['description' => 'a file of the form'];

            if ($fileRequired) {
                $required[] = (string) $name;
            }
        }

        $schema = ['type' => 'object', 'properties' => (object) $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $schema['additionalProperties'] = false;
        $mediaType = ['schema' => (object) $schema];

        if ($encoding !== []) {
            $mediaType['encoding'] = (object) $encoding;
        }

        return (object) $mediaType;
    }

    private static function requiresPart(MediaBodyMetadata $body): bool
    {
        foreach ($body->fields as $field) {
            if ($field->required) {
                return true;
            }
        }

        return in_array(true, $body->files, true);
    }

    /**
     * the media responses share the 200 with the JSON:API response;
     * `Content-Disposition` and `Content-Length` apply where a media response is served
     */
    private function withMediaResponses(EndpointMetadata $endpoint, ?stdClass $jsonApi): stdClass
    {
        $content = $jsonApi !== null && isset($jsonApi->content) && is_array($jsonApi->content)
            ? $jsonApi->content
            : [];

        foreach ($endpoint->mediaResponses as $representation) {
            $mediaType = $representation->mediaType;
            $content[$mediaType->toHeaderValue()] = match (true) {
                $representation->codec !== null => (object) [
                    'schema' => $representation->codec->schema()->forResponse()->toObject(),
                ],
                $mediaType->type === 'text' => (object) ['schema' => (object) ['type' => 'string']],
                default => new stdClass(),
            };
        }

        $headers = [];
        $declared = $jsonApi !== null && isset($jsonApi->headers) && is_array($jsonApi->headers)
            ? $jsonApi->headers
            : [];

        foreach ([
            FieldName::CONTENT_LANGUAGE,
            FieldName::VARY,
            ...array_keys($declared),
            FieldName::CONTENT_DISPOSITION,
            FieldName::CONTENT_LENGTH,
        ] as $header) {
            $headers[(string) $header] = (object) ['$ref' => HeaderBuilder::REFERENCE . $header];
        }

        return (object) [
            'description' => $jsonApi->description
                ?? sprintf('the media response of the endpoint "%s"', $endpoint->name),
            'headers' => $headers,
            'content' => $content,
        ];
    }

    private function endpointResponse(EndpointMetadata $endpoint, ResponseMetadata $declared): stdClass
    {
        $schema = $this->documents->endpointDocument($endpoint, $declared);
        $headers = [];
        $type = $declared->type === null ? null : $this->api->resources->get($declared->type);

        if ($endpoint->carriesEntityTag($declared) && $type?->versionPolicy !== null) {
            $headers[] = FieldName::ETAG;
        }

        if ($declared->kind === ResponseShapeKind::Created) {
            if ($this->api->routes->fetches(RouteKind::Resource, (string) $declared->type, null)) {
                $headers[] = FieldName::LOCATION;
            }
        }

        if ($declared->kind === ResponseShapeKind::Accepted) {
            $headers[] = FieldName::CONTENT_LOCATION;
        }

        $description = sprintf('the %s response of the endpoint "%s"', $declared->kind->term(), $endpoint->name);

        if ($schema === null) {
            return self::emptyResponse($description, $headers);
        }

        return self::response(
            $description,
            array_merge([FieldName::CONTENT_LANGUAGE, FieldName::VARY], $headers),
            $schema,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function endpointIfMatch(EndpointMetadata $endpoint, ResourceMetadata $precondition): array
    {
        if ($endpoint->leavesUnchanged) {
            return [
                'name' => FieldName::IF_MATCH,
                'in' => 'header',
                'description' => 'the entity tag the request is conditional on, or "*" for any current'
                    . ' representation; a request whose condition does not hold gets 412',
                'schema' => (object) ['type' => 'string'],
            ];
        }

        return self::ifMatchParameter($precondition);
    }

    /**
     * @return array<int, stdClass>
     */
    private function endpointErrorResponses(EndpointMetadata $endpoint, ?ResourceMetadata $precondition): array
    {
        $form = false;

        foreach ($endpoint->mediaBodies as $mediaBody) {
            $form = $form || $mediaBody->kind === MediaBodyShapeKind::Form;
        }

        $responses = [
            400 => $this->badRequest('endpoint', $form),
            401 => self::errorResponse(
                'the request carries no valid credentials and a challenge applies to it [primary: RFC 9110 15.5.2]',
                [FieldName::WWW_AUTHENTICATE],
            ),
            403 => self::errorResponse('the request is denied'),
        ];

        if ($endpoint->template->parameters() !== []) {
            $responses[404] = self::errorResponse('a parameter of the path names nothing, or the record it names does'
                . ' not exist or is hidden from the client');
        }

        $responses[406] = self::errorResponse('no representation the endpoint can respond with is acceptable to the'
            . ' Accept field of the request [spec, Content Negotiation]');

        $body = $endpoint->body;

        if ($body !== null && $body->kind !== BodyShapeKind::Meta) {
            $responses[409] = self::errorResponse('the document of the request names a type or an id the endpoint does'
                . ' not accept at that place');
        }

        if ($precondition !== null && !$endpoint->isSafe()) {
            $responses[412] = self::errorResponse('the If-Match field of the request is malformed, or no entity tag it'
                . ' lists is the current one [primary: RFC 9110 13.1.1]');
        }

        $reads = $endpoint->readableMediaTypes() !== [];

        if ($form) {
            $responses[413] = self::errorResponse('the form of the request, or a file of it, is larger than the site'
                . ' takes; an error of a file names its field in the member field of its meta');
        }

        $responses[415] = $reads
            ? self::errorResponse(
                'the content of the request is in a media type the endpoint reads no body of, or a JSON:API media'
                . ' type that carries a parameter other than ext and profile [spec, Content Negotiation]',
                [FieldName::ACCEPT],
            )
            : self::errorResponse('the request carries content, and the endpoint reads none');

        if ($reads) {
            $responses[422] = self::errorResponse('the body of the request names an unknown field, a field its write'
                . ' rule refuses, or a value a codec refuses'
                    . ($form ? '; an error of a form field names the field in the member'
                . ' field of its meta, since no member of source names one' : ''));
        }

        if ($endpoint->writesPreconditionRecord() && $precondition?->requiresPreconditions() === true) {
            $responses[428] = self::errorResponse('the endpoint writes a resource that is changed only by a conditional'
                . ' request, and the request carries no If-Match [primary: RFC 6585 3]');
        }

        $responses[500] = self::errorResponse('the request could not be carried out and the reason is written to the'
            . ' log of the site');

        return $responses;
    }

    /**
     * the language sources follow the operation's own parameters, since every route reads them
     *
     * @param list<array<string, mixed>> $parameters
     * @param array<int, stdClass>       $responses       keyed by status, in ascending order
     * @param ?list<SecurityScheme>      $securitySchemes those the operation declares in place of
     *                                                    the API's, which it inherits where null
     */
    private function operation(
        ?string $tag,
        string $operationId,
        ?string $summary,
        ?string $description,
        bool $deprecated,
        array $parameters,
        ?stdClass $requestBody,
        array $responses,
        ?array $securitySchemes,
    ): stdClass {
        $operation = array_filter([
            'tags' => $tag === null ? null : [$tag],
            'summary' => $summary,
            'description' => $description,
        ], static function (mixed $member): bool {
            return $member !== null;
        });
        $operation['operationId'] = $operationId;

        if ($deprecated) {
            $operation['deprecated'] = true;
        }

        $parameters = [...$parameters, ...$this->parameters->languageReferences()];

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($requestBody !== null) {
            $operation['requestBody'] = $requestBody;
        }

        $operation['responses'] = (object) $responses;

        if ($securitySchemes !== null) {
            $operation['security'] = SecurityBuilder::requirements($securitySchemes);
        }

        return (object) $operation;
    }

    /**
     * a null kind stands for an atomic route, which may run a write of any of its types
     *
     * @param ?AtomicRouteMetadata $atomicRoute given exactly with a null kind
     *
     * @return array<int, stdClass>
     */
    private function errorResponses(
        ?OperationKind $kind,
        ?ResourceMetadata $resource,
        ?AtomicRouteMetadata $atomicRoute = null,
    ): array {
        $writes = $kind === null || !$kind->isFetch();
        $document = $kind === null || in_array($kind, [
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ], true);
        $responses = [
            400 => $this->badRequest('route', false),
            401 => self::errorResponse(
                'the request carries no valid credentials and a challenge applies to it [primary: RFC'
                . ' 9110 15.5.2]',
                [FieldName::WWW_AUTHENTICATE],
            ),
            403 => self::errorResponse(
                'the request is denied, or it asks for an operation the declaration does not enable',
            ),
        ];

        if ($kind !== OperationKind::FetchCollection) {
            $responses[404] = self::errorResponse(
                $document
                    ? 'the target of the request, or a related resource its document names, does not exist'
                    : 'the target of the request does not exist, or the client may not see it',
            );
        }

        $responses[406] = self::errorResponse(
            'no representation the route can respond with is acceptable to the Accept field of the request'
            . ' [spec, Content Negotiation]',
        );

        if ($writes) {
            $responses[409] = self::errorResponse($kind === null
                ? 'the request conflicts with the state of the API: an operation that targets a type the route does'
                    . ' not take, an id that already exists, or a server constraint a write violates'
                : 'the request conflicts with the state of the API: a type or an id that does not match the URL,'
                    . ' an id that already exists, or a server constraint the write violates');
        }

        if ($kind?->isConditionalWrite() === true) {
            $responses[412] = self::errorResponse(
                'the If-Match field of the request is malformed, or no entity tag it lists is the current one'
                . ' [primary: RFC 9110 13.1.1]',
            );
        }

        if ($document) {
            $responses[415] = self::errorResponse(
                'the content of the request is not the JSON:API media type, or that media type carries a'
                . ' parameter other than ext and profile [spec, Content Negotiation]',
                [FieldName::ACCEPT],
            );
            $responses[422] = self::errorResponse(
                'the document of the request names an unknown field, a field its write rule refuses, or a'
                . ' value a codec refuses',
            );
        }

        if ($this->respondsWithPreconditionRequired($kind, $resource, $atomicRoute)) {
            $responses[428] = self::errorResponse(
                'the write targets a resource that is changed only by a conditional request, and the request'
                . ' carries no If-Match [primary: RFC 6585 3]',
            );
        }

        $responses[500] = self::errorResponse(
            'the request could not be carried out and the reason is written to the log of the site',
        );

        return $responses;
    }

    /**
     * an atomic operation carries no precondition,
     * so an atomic route can respond with 428 once any type it takes requires preconditions
     */
    private function respondsWithPreconditionRequired(
        ?OperationKind $kind,
        ?ResourceMetadata $resource,
        ?AtomicRouteMetadata $atomicRoute,
    ): bool {
        if ($kind === null) {
            foreach ($atomicRoute->types ?? [] as $type) {
                if ($this->api->resources->get($type)->requiresPreconditions()) {
                    return true;
                }
            }

            return false;
        }

        return $kind->isConditionalWrite() && $resource?->requiresPreconditions() === true;
    }

    /**
     * the 400 of a route and of an endpoint: one text,
     * as both read their query and their content alike
     *
     * @param 'endpoint'|'route' $subject
     * @param bool               $form    whether the request may carry a form,
     *                                    whose file may arrive cut
     */
    private function badRequest(string $subject, bool $form): stdClass
    {
        $fault = $this->parameters->languageFault();

        return self::errorResponse(sprintf(
            'the request names a query parameter the %s does not process, lacks one it requires%s, or carries a'
            . ' value or a document it cannot read%s',
            $subject,
            $fault === null ? '' : ', ' . $fault,
            $form ? ', or a file of its form arrived cut' : '',
        ));
    }

    private static function notModified(): stdClass
    {
        return self::emptyResponse(
            'no entity tag the request carries in If-None-Match names another representation than the current'
            . ' one, so the response carries no content [primary: RFC 9110 13.1.2, 15.4.5]',
            [FieldName::ETAG, FieldName::VARY],
        );
    }

    /**
     * the asynchronous processing of the JSON:API recommendations:
     * 202 with the monitor resource as primary data and its link in `Content-Location`
     *
     * @return array<int, stdClass>
     */
    private static function acceptedResponse(ResourceMetadata $resource, OperationKind $kind): array
    {
        $monitor = $resource->monitorTypes[$kind->value] ?? null;

        if ($monitor === null) {
            return [];
        }

        return [
            202 => self::documentResponse(
                sprintf(
                    'the write is accepted for later processing, and the resource of type "%s" monitors it',
                    $monitor,
                ),
                DocumentSchemaBuilder::documentName($monitor),
                [FieldName::CONTENT_LOCATION],
            ),
        ];
    }

    /**
     * the parameters of the whole path item, which every method of the path shares:
     * the id of a generated route, under the name its type gives it.
     * they come from a generated route even where an endpoint stands first on the path:
     * an endpoint or an atomic route writes none here, and an endpoint's operation carries its own
     *
     * @return array<string, mixed> the `parameters` member, empty for a path without parameters
     */
    private static function pathParameters(OperationRoute $route): array
    {
        $parameters = array_map(static function (PathParameter $parameter) use ($route): array {
            return [
                'name' => $parameter->name,
                'in' => 'path',
                'required' => true,
                'description' => sprintf('the id of the resource of type "%s"', $route->type),
                'schema' => $parameter->codec->schema()->toObject(),
            ];
        }, $route->template->parameters());

        return $parameters === [] ? [] : ['parameters' => $parameters];
    }

    /**
     * the parameters of the path of an endpoint, which its operation carries:
     * a path item takes the parameters of its first generated route,
     * and where the endpoint shares the path with a route of another codec,
     * the parameter of the operation overrides it,
     * as "the new definition will override it" (OpenAPI 3.1.2)
     *
     * @return list<array<string, mixed>>
     */
    private static function endpointPathParameters(EndpointMetadata $endpoint): array
    {
        return array_map(static function (PathParameter $parameter) use ($endpoint): array {
            $type = $endpoint->bindings[$parameter->name] ?? null;

            return [
                'name' => $parameter->name,
                'in' => 'path',
                'required' => true,
                'description' => $type === null
                    ? sprintf('the parameter "%s" of the path of the endpoint "%s"', $parameter->name, $endpoint->name)
                    : sprintf('the id of the resource of type "%s" the endpoint loads; a missing one gets 404', $type),
                'schema' => $parameter->codec->schema()->toObject(),
            ];
        }, $endpoint->template->parameters());
    }

    /**
     * @return array<string, mixed>
     */
    private static function ifMatchParameter(ResourceMetadata $resource): array
    {
        if ($resource->requiresPreconditions()) {
            return [
                'name' => FieldName::IF_MATCH,
                'in' => 'header',
                'required' => true,
                'description' => 'the entity tag the write is conditional on. this resource is changed only by'
                    . ' a conditional request, so a write without the field gets 428',
                'schema' => (object) ['type' => 'string'],
            ];
        }

        return [
            'name' => FieldName::IF_MATCH,
            'in' => 'header',
            'description' => 'the entity tag the write is conditional on, or "*" for any current'
                . ' representation; a write whose condition does not hold gets 412',
            'schema' => (object) ['type' => 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ifNoneMatchParameter(): array
    {
        return [
            'name' => FieldName::IF_NONE_MATCH,
            'in' => 'header',
            'description' => 'the entity tags the client already holds, or "*" for any current representation;'
                . ' a reading whose condition does not hold gets 304',
            'schema' => (object) ['type' => 'string'],
        ];
    }

    private static function requestBody(
        string $schema,
        string $description,
        string $mediaType = MediaType::JSON_API,
    ): stdClass {
        return (object) [
            'description' => $description,
            'content' => [$mediaType => (object) ['schema' => DocumentSchemaBuilder::reference($schema)]],
            'required' => true,
        ];
    }

    /**
     * @param list<string> $headers
     */
    private static function documentResponse(
        string $description,
        string $schema,
        array $headers = [],
        string $mediaType = MediaType::JSON_API,
    ): stdClass {
        return self::response(
            $description,
            array_merge([FieldName::CONTENT_LANGUAGE, FieldName::VARY], $headers),
            $schema,
            $mediaType,
        );
    }

    /**
     * no `Content-Language`: without content there is no representation to name the language of
     *
     * @param list<string> $headers
     */
    private static function emptyResponse(string $description, array $headers = []): stdClass
    {
        return self::response($description, $headers, null);
    }

    /**
     * no `Content-Language`: the server writes none on an error, whose language it cannot know
     *
     * @param list<string> $headers
     */
    private static function errorResponse(string $description, array $headers = []): stdClass
    {
        return self::response(
            $description,
            array_merge([FieldName::VARY], $headers),
            DocumentSchemaBuilder::errorDocumentName(),
        );
    }

    /**
     * @param list<string> $headers
     */
    private static function response(
        string $description,
        array $headers,
        string|stdClass|null $schema,
        string $mediaType = MediaType::JSON_API,
    ): stdClass {
        $response = ['description' => $description];

        if ($headers !== []) {
            $references = [];

            foreach ($headers as $header) {
                $references[$header] = (object) ['$ref' => HeaderBuilder::REFERENCE . $header];
            }

            $response['headers'] = $references;
        }

        if ($schema !== null) {
            $response['content'] = [$mediaType => (object) [
                'schema' => is_string($schema) ? DocumentSchemaBuilder::reference($schema) : $schema,
            ]];
        }

        return (object) $response;
    }

    /**
     * kind, type and relationship identify an operation, and the component name keeps them apart,
     * so every id is unique
     */
    private static function operationId(OperationKind $kind, string $type, ?string $relationship): string
    {
        if ($relationship === null) {
            return DocumentSchemaBuilder::componentName($kind->value, $type);
        }

        return DocumentSchemaBuilder::componentName($kind->value, $type, $relationship);
    }

    /**
     * @throws InvalidArgumentException when the kind is no write of a relationship route
     */
    private static function verbOf(OperationKind $kind): string
    {
        return match ($kind) {
            OperationKind::ReplaceRelationship => 'replaces',
            OperationKind::AddToRelationship => 'adds members to',
            OperationKind::RemoveFromRelationship => 'removes members from',
            default => throw new InvalidArgumentException(sprintf(
                'a %s operation is no write of a relationship route',
                $kind->value,
            )),
        };
    }

    /**
     * @throws InvalidArgumentException when the kind is no write of a relationship route
     */
    private static function relationshipWriteText(OperationKind $kind, RelationshipMetadata $relationship): string
    {
        return match ($kind) {
            OperationKind::ReplaceRelationship => sprintf(
                'replaces the linkage of the relationship "%s" of the named resource with the linkage of the'
                . ' request document',
                $relationship->name,
            ),
            OperationKind::AddToRelationship => sprintf(
                'adds the members of the request document to the relationship "%s" of the named resource;'
                . ' "the server MUST add the specified members to the relationship unless they are already'
                . ' present" [spec]',
                $relationship->name,
            ),
            OperationKind::RemoveFromRelationship => sprintf(
                'removes the members of the request document from the relationship "%s" of the named'
                . ' resource; an identifier that names no member of it is skipped',
                $relationship->name,
            ),
            default => throw new InvalidArgumentException(sprintf(
                'a %s operation is no write of a relationship route',
                $kind->value,
            )),
        };
    }

    /**
     * the method a route is declared with stands first among its methods
     */
    private static function fieldOf(Route $route): string
    {
        return strtolower($route->methods[0]);
    }
}
