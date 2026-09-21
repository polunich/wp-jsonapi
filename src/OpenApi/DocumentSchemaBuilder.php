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

use LogicException;
use Polunich\WpJsonApi\Document\JsonApiObject;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Query\Pagination\PageMeta;
use Polunich\WpJsonApi\Routing\RouteKind;
use stdClass;

/**
 * a response schema describes what the library writes, a request schema what the server accepts,
 * including the members the spec allows and the server ignores (see `ignored()`).
 * every object refuses any other member (see `objectSchema()`),
 * so a field a write may not send is left out of the request schema:
 * `readOnly` is only an annotation, while the server responds to such a field with 422.
 *
 * inside an atomic operation a linkage or an update target may name a lid,
 * which a single request cannot,
 * so the atomic shapes stand beside the single-request ones instead of loosening them
 *
 * @internal
 */
final readonly class DocumentSchemaBuilder
{
    private const JSON_API = 'jsonapi';

    private const LINK = 'link';

    private const NULLABLE_LINK = 'nullableLink';

    private const ERROR = 'error';

    private const ERRORS = 'errors';

    private const REFERENCE = '#/components/schemas/';

    private const AT_MEMBER = '^@';

    private const ATOMIC = 'atomic';

    private const MODE_READ = 'read';

    private const MODE_CREATE = 'create';

    private const MODE_UPDATE = 'update';

    /**
     * a component key also allows `.`, which separates segments here
     */
    private const UNESCAPED = '/^[A-Za-z0-9\-_]+$/D';

    public function __construct(private ApiMetadata $api) {}

    /**
     * whether that route responds to a GET, which decides whether a link to it is written
     */
    private function fetches(RouteKind $kind, string $type, ?string $relationship): bool
    {
        return $this->api->routes->fetches($kind, $type, $relationship);
    }

    public static function reference(string $name): stdClass
    {
        return (object) ['$ref' => self::REFERENCE . $name];
    }

    public static function memberReference(string $name, string $member): stdClass
    {
        return (object) ['$ref' => self::REFERENCE . $name . '/properties/' . $member];
    }

    public static function componentName(string $segment, string ...$segments): string
    {
        $name = self::componentSegment($segment);

        foreach ($segments as $next) {
            $name .= '.' . self::componentSegment($next);
        }

        return $name;
    }

    public static function errorDocumentName(): string
    {
        return self::ERRORS;
    }

    public static function jsonApiName(): string
    {
        return self::JSON_API;
    }

    public static function documentName(string $type): string
    {
        return self::componentName($type, 'document');
    }

    public static function collectionName(string $type): string
    {
        return self::componentName($type, 'collection');
    }

    public static function createRequestName(string $type): string
    {
        return self::componentName($type, 'create');
    }

    public static function updateRequestName(string $type): string
    {
        return self::componentName($type, 'update');
    }

    public static function relationshipDocumentName(string $type, string $relationship): string
    {
        return self::componentName($type, $relationship, 'relationship');
    }

    public static function relatedDocumentName(string $type, string $relationship): string
    {
        return self::componentName($type, $relationship, 'related');
    }

    public static function relationshipRequestName(string $type, string $relationship): string
    {
        return self::componentName($type, $relationship, 'update');
    }

    public static function atomicCreateName(string $type): string
    {
        return self::componentName($type, 'create', self::ATOMIC);
    }

    public static function atomicUpdateName(string $type): string
    {
        return self::componentName($type, 'update', self::ATOMIC);
    }

    public static function atomicLinkageName(string $type, string $relationship): string
    {
        return self::componentName($type, $relationship, 'linkage', self::ATOMIC);
    }

    /**
     * every type carries the whole set, whatever routes it enables:
     * a component nothing references has no effect, while a missing one breaks a reference.
     *
     * an atomic shape is the resource object or the linkage itself, not a document around it,
     * since an operation holds its data directly
     *
     * @return array<string, stdClass>
     */
    public function schemas(): array
    {
        $schemas = [
            self::JSON_API => $this->jsonApiObject(),
            self::LINK => $this->link(),
            self::NULLABLE_LINK => $this->nullableLink(),
            self::ERROR => $this->errorObject(),
            self::ERRORS => $this->errorDocument(),
        ];
        $atomic = $this->api->atomicRoutes !== [];

        foreach ($this->api->resources->all() as $resource) {
            $type = $resource->type;
            $schemas[self::resourceName($type)] = $this->resourceObject($resource);
            $schemas[self::identifierName($type)] = $this->identifierObject($resource);
            $schemas[self::documentName($type)] = $this->resourceDocument($resource);
            $schemas[self::collectionName($type)] = $this->collectionDocument($resource);
            $schemas[self::createRequestName($type)] = $this->createRequest($resource);
            $schemas[self::updateRequestName($type)] = $this->updateRequest($resource);

            if ($atomic) {
                $schemas[self::atomicIdentifierName($type)] = $this->atomicIdentifierObject($resource);
                $schemas[self::atomicCreateName($type)] = $this->createResourceObject($resource, true);
                $schemas[self::atomicUpdateName($type)] = $this->updateResourceObject($resource, true);
            }

            foreach ($resource->relationships as $relationship) {
                $name = $relationship->name;
                $schemas[self::linkageName($type, $name)] = $this->linkage($relationship);
                $schemas[self::relationshipDocumentName(
                    $type,
                    $name,
                )] = $this->relationshipDocument($resource, $relationship);
                $schemas[self::relatedDocumentName($type, $name)] = $this->relatedDocument($relationship);
                $schemas[self::relationshipRequestName(
                    $type,
                    $name,
                )] = $this->relationshipRequest($resource, $relationship);

                if ($atomic) {
                    $schemas[self::atomicLinkageName($type, $name)] = $this->atomicLinkage($relationship);
                }
            }
        }

        return $schemas;
    }

    /**
     * the document a response of an endpoint writes, inline in its operation,
     * since the shared documents of the generated routes cannot describe it.
     * a self link stands only where the endpoint responds to a safe method,
     * or for a created resource where the resource route responds to GET,
     * and a list carries no pagination links.
     * a page and a 202 reference the shared documents of their types,
     * the collection and the resource document, as the collection route and a generated write do;
     * a 204 writes none
     */
    public function endpointDocument(EndpointMetadata $endpoint, ResponseMetadata $response): ?stdClass
    {
        $properties = [Member::JSONAPI => self::reference(self::JSON_API)];
        $required = [Member::JSONAPI];

        switch ($response->kind) {
            case ResponseShapeKind::NoContent:
                return null;

            case ResponseShapeKind::Accepted:
                return self::reference(self::documentName((string) $response->type));

            case ResponseShapeKind::Page:
                return self::reference(self::collectionName((string) $response->type));

            case ResponseShapeKind::Meta:
                $codec = $response->codec ?? throw new LogicException('a meta response carries its codec');
                $properties[Member::META] = $codec->schema()->forResponse()->toObject();
                $required[] = Member::META;

                return self::objectSchema(
                    $properties,
                    $required,
                    sprintf('a document of the endpoint "%s" with top-level meta alone', $endpoint->name),
                );

            case ResponseShapeKind::Linkage:
                return $this->endpointLinkage($endpoint, $response, $properties, $required);

            default:
                break;
        }

        $type = (string) $response->type;
        $links = $response->kind === ResponseShapeKind::Created
            ? $this->fetches(RouteKind::Resource, $type, null)
            : $endpoint->isSafe();

        if ($links) {
            $properties[Member::LINKS] = self::selfLinks();
            $required[] = Member::LINKS;
        }

        $resource = self::reference(self::resourceName($type));

        if ($response->isList()) {
            $properties[Member::DATA] = (object) ['type' => 'array', 'items' => $resource];
        } elseif ($response->kind === ResponseShapeKind::Related && $this->relatedIsNullable($endpoint, $response)) {
            $properties[Member::DATA] = (object) [
                'anyOf' => [$resource, (object) ['type' => 'null']],
                'description' => 'null where the relationship is empty [spec, Top Level]',
            ];
        } else {
            $properties[Member::DATA] = $resource;
        }

        $properties[Member::INCLUDED] = $this->included($this->api->resources->includedTypes($type, null));
        $required[] = Member::DATA;

        return self::objectSchema(
            $properties,
            $required,
            sprintf(
                'a document of the endpoint "%s" whose primary data is %s of type "%s"',
                $endpoint->name,
                $response->isList() ? 'a collection of resources' : 'one resource',
                $type,
            ),
        );
    }

    public function endpointBody(BodyMetadata $body): stdClass
    {
        return match ($body->kind) {
            BodyShapeKind::Resource => self::reference(self::createRequestName((string) $body->type)),
            BodyShapeKind::Record => self::reference(self::updateRequestName((string) $body->type)),
            BodyShapeKind::ToOne => self::requestDocument(
                (object) ['anyOf' => [
                    self::reference(self::identifierName((string) $body->type)),
                    (object) ['type' => 'null'],
                ]],
                sprintf('the document of one resource identifier of type "%s", or null', (string) $body->type),
            ),
            BodyShapeKind::ToMany => self::requestDocument(
                (object) ['type' => 'array', 'items' => self::reference(self::identifierName((string) $body->type))],
                sprintf('the document of a list of resource identifiers of type "%s"', (string) $body->type),
            ),
            BodyShapeKind::Meta => self::objectSchema(
                [
                    Member::META => ($body->codec ?? throw new LogicException('a meta body carries its codec'))
                        ->schema()
                        ->forRequest()
                        ->toObject(),
                    Member::JSONAPI => self::ignored('object', 'the object describing the implementation of the client;'
                        . ' the library reads none of it [spec, Top Level]'),
                    Member::LINKS => self::ignored('object', 'the links of the document; the library reads none of them'
                        . ' [spec, Top Level]'),
                ],
                [Member::META],
                'a document with top-level meta alone',
            ),
        };
    }

    /**
     * @param array<string, stdClass> $properties
     * @param list<string>            $required
     */
    private function endpointLinkage(
        EndpointMetadata $endpoint,
        ResponseMetadata $response,
        array $properties,
        array $required,
    ): stdClass {
        $parentType = $endpoint->bindings[(string) $response->parameter];
        $relationship = (string) $response->relationship;
        $self = $endpoint->isSafe();
        $related = $this->fetches(RouteKind::Related, $parentType, $relationship);

        if ($self || $related) {
            $properties[Member::LINKS] = self::relationshipDocumentLinks($self, $related);
            $required[] = Member::LINKS;
        }

        $properties[Member::DATA] = self::reference(self::linkageName($parentType, $relationship));
        $properties[Member::INCLUDED] = $this->included($this->api->resources->includedTypes(
            $parentType,
            $relationship,
        ));
        $required[] = Member::DATA;

        return self::objectSchema(
            $properties,
            $required,
            sprintf(
                'a document of the endpoint "%s" whose primary data is the linkage of the relationship "%s" of'
                . ' type "%s"',
                $endpoint->name,
                $relationship,
                $parentType,
            ),
        );
    }

    private function relatedIsNullable(EndpointMetadata $endpoint, ResponseMetadata $response): bool
    {
        $parentType = $endpoint->bindings[(string) $response->parameter];

        return $this->api->resources->relationshipOf($parentType, (string) $response->relationship)->nullable;
    }

    /**
     * `attributes` and `relationships` are optional: a sparse fieldset may leave out every field
     */
    private function resourceObject(ResourceMetadata $resource): stdClass
    {
        $properties = [
            Member::TYPE => self::constant($resource->type),
            Member::ID => self::idMember($resource),
        ];
        $attributes = $this->attributeProperties($resource, self::MODE_READ);

        if ($attributes !== []) {
            $properties[Member::ATTRIBUTES] = self::objectSchema(
                $attributes,
                [],
                'the attributes of the resource a sparse fieldset left in the response',
            );
        }

        $relationships = [];

        foreach ($resource->relationships as $relationship) {
            $relationships[$relationship->name] = $this->relationshipObject($resource, $relationship);
        }

        if ($relationships !== []) {
            $properties[Member::RELATIONSHIPS] = self::objectSchema(
                $relationships,
                [],
                'the relationships of the resource a sparse fieldset left in the response',
            );
        }

        $required = [Member::TYPE, Member::ID];

        if ($this->fetches(RouteKind::Resource, $resource->type, null)) {
            $properties[Member::LINKS] = self::objectSchema(
                [Member::SELF => self::reference(self::LINK)],
                [Member::SELF],
                'the links of the resource: its self link, which responds to a GET with the resource',
            );
            $required[] = Member::LINKS;
        }

        return self::objectSchema(
            $properties,
            $required,
            $resource->description ?? sprintf('one resource of type "%s" as a response carries it', $resource->type),
        );
    }

    /**
     * names a resource by `id` alone:
     * the server refuses a `lid` in the linkage of a single request.
     * one schema serves responses and requests,
     * so it describes the `meta` a request may carry, which the server ignores
     */
    private function identifierObject(ResourceMetadata $resource): stdClass
    {
        return self::objectSchema(
            [
                Member::TYPE => self::constant($resource->type),
                Member::ID => self::idMember($resource),
                Member::META => self::ignored('object', 'non-standard information about the resource this identifier'
                    . ' names; the library reads none of it and writes none [spec, Resource Identifier Objects]'),
            ],
            [Member::TYPE, Member::ID],
            sprintf(
                'the identifier of one resource of type "%s" [spec, Resource Identifier Objects]',
                $resource->type,
            ),
        );
    }

    private function atomicIdentifierObject(ResourceMetadata $resource): stdClass
    {
        return self::objectSchema(
            [
                Member::TYPE => self::constant($resource->type),
                Member::ID => self::idMember($resource),
                Member::LID => self::lid(sprintf(
                    'the lid a prior operation of the request assigned to the resource of type "%s" this'
                    . ' linkage names [ext-atomic]',
                    $resource->type,
                )),
                Member::META => self::ignored('object', 'non-standard information about the resource this identifier'
                    . ' names; the library reads none of it [spec, Resource Identifier Objects]'),
            ],
            [Member::TYPE],
            sprintf(
                'the identifier of one resource of type "%s" inside an atomic operation: its id, or the lid'
                . ' a prior operation assigned it [spec, Resource Identifier Objects] [ext-atomic]',
                $resource->type,
            ),
            constraints: self::idOrLid(),
        );
    }

    /**
     * `data` is required only where the linkage is always written,
     * which a relationship with neither link always is; otherwise it appears when included
     */
    private function relationshipObject(ResourceMetadata $resource, RelationshipMetadata $relationship): stdClass
    {
        $toMany = $relationship->cardinality === Cardinality::ToMany;
        $self = $this->fetches(RouteKind::Relationship, $resource->type, $relationship->name);
        $related = $this->fetches(RouteKind::Related, $resource->type, $relationship->name);
        $links = [];

        if ($self) {
            $links[Member::SELF] = self::reference(self::LINK);
        }

        if ($related) {
            $links[Member::RELATED] = self::reference(self::LINK);
        }

        // the API is not built where a to-many linkage reaches a document
        // without its relationship route
        if ($toMany && $self) {
            foreach ([Member::FIRST, Member::LAST, Member::PREV, Member::NEXT] as $page) {
                $links[$page] = self::reference(self::NULLABLE_LINK);
            }
        }

        $properties = [];
        $required = [];

        if ($links !== []) {
            $properties[Member::LINKS] = self::objectSchema(
                $links,
                array_values(array_intersect([Member::SELF, Member::RELATED], array_keys($links))),
                self::relationshipLinksText($self, $related, $toMany),
            );
            $required[] = Member::LINKS;
        }

        $properties[Member::DATA] = self::reference(self::linkageName($resource->type, $relationship->name));

        if ($relationship->linkageAlwaysPresent) {
            $required[] = Member::DATA;
        }

        return self::objectSchema(
            $properties,
            $required,
            $relationship->description ?? sprintf('the relationship "%s" of the resource', $relationship->name),
            $relationship->deprecated,
        );
    }

    private static function relationshipLinksText(bool $self, bool $related, bool $toMany): string
    {
        $text = match (true) {
            $self && $related => 'the self link and the related link of the relationship',
            $self => 'the self link of the relationship',
            default => 'the related link of the relationship',
        };

        return $toMany && $self
            ? $text . ', and the pagination links of its own route, which "MUST paginate the relationship data, not'
                . ' the related resources" [spec]'
            : $text;
    }

    private function linkage(RelationshipMetadata $relationship): stdClass
    {
        return self::linkageOf($relationship, self::identifierName($relationship->relatedType), '');
    }

    private function atomicLinkage(RelationshipMetadata $relationship): stdClass
    {
        return self::linkageOf(
            $relationship,
            self::atomicIdentifierName($relationship->relatedType),
            '. an identifier of it may name the lid a prior operation assigned [ext-atomic]',
        );
    }

    private static function linkageOf(
        RelationshipMetadata $relationship,
        string $identifierName,
        string $note,
    ): stdClass {
        $identifier = self::reference($identifierName);

        if ($relationship->cardinality === Cardinality::ToMany) {
            return (object) [
                'type' => 'array',
                'items' => $identifier,
                'description' => sprintf(
                    'the linkage of the to-many relationship "%s": an array of resource identifier objects, empty'
                    . ' when the relationship is empty [spec]',
                    $relationship->name,
                ) . $note,
            ];
        }

        if (!$relationship->nullable) {
            return (object) [
                '$ref' => self::REFERENCE . $identifierName,
                'description' => sprintf(
                    'the linkage of the to-one relationship "%s", which is never empty',
                    $relationship->name,
                ) . $note,
            ];
        }

        return (object) [
            'anyOf' => [$identifier, (object) ['type' => 'null']],
            'description' => sprintf(
                'the linkage of the to-one relationship "%s": a resource identifier object, or null when the'
                . ' relationship is empty [spec]',
                $relationship->name,
            ) . $note,
        ];
    }

    /**
     * the self link leads to the route of the resource, which the type may exclude;
     * a monitor type keeps it, so a 202 always links its monitor
     */
    private function resourceDocument(ResourceMetadata $resource): stdClass
    {
        $properties = [Member::JSONAPI => self::reference(self::JSON_API)];
        $required = [Member::JSONAPI];

        if ($this->fetches(RouteKind::Resource, $resource->type, null)) {
            $properties[Member::LINKS] = self::selfLinks();
            $required[] = Member::LINKS;
        }

        $properties[Member::DATA] = self::reference(self::resourceName($resource->type));
        $properties[Member::INCLUDED] = $this->included($this->api->resources->includedTypes($resource->type, null));
        $required[] = Member::DATA;

        return self::objectSchema(
            $properties,
            $required,
            sprintf('a document whose primary data is one resource of type "%s"', $resource->type),
        );
    }

    private function collectionDocument(ResourceMetadata $resource): stdClass
    {
        return self::objectSchema(
            [
                Member::JSONAPI => self::reference(self::JSON_API),
                Member::LINKS => self::pagedLinks(),
                Member::DATA => (object) [
                    'type' => 'array',
                    'items' => self::reference(self::resourceName($resource->type)),
                ],
                Member::INCLUDED => $this->included($this->api->resources->includedTypes($resource->type, null)),
                Member::META => $this->pageMeta(),
            ],
            [Member::JSONAPI, Member::LINKS, Member::DATA],
            sprintf(
                'a document whose primary data is a collection of resources of type "%s"',
                $resource->type,
            ),
        );
    }

    /**
     * wherever a route responds with this document,
     * a to-many relationship keeps its relationship route, so its pagination links always stand
     */
    private function relationshipDocument(ResourceMetadata $resource, RelationshipMetadata $relationship): stdClass
    {
        $toMany = $relationship->cardinality === Cardinality::ToMany;
        $self = $this->fetches(RouteKind::Relationship, $resource->type, $relationship->name);
        $related = $this->fetches(RouteKind::Related, $resource->type, $relationship->name);
        $properties = [Member::JSONAPI => self::reference(self::JSON_API)];
        $required = [Member::JSONAPI];

        if ($self || $related) {
            $properties[Member::LINKS] = $toMany && $self
                ? self::pagedLinks($related)
                : self::relationshipDocumentLinks($self, $related);
            $required[] = Member::LINKS;
        }

        $properties[Member::DATA] = self::reference(self::linkageName($resource->type, $relationship->name));
        $properties[Member::INCLUDED] = $this->included($this->api->resources->includedTypes(
            $resource->type,
            $relationship->name,
        ));
        $required[] = Member::DATA;

        if ($toMany) {
            $properties[Member::META] = $this->pageMeta();
        }

        return self::objectSchema(
            $properties,
            $required,
            sprintf(
                'a document whose primary data is the linkage of the relationship "%s" of type "%s"',
                $relationship->name,
                $resource->type,
            ),
        );
    }

    /**
     * unlike the relationship route, an include path here starts at the related type,
     * whose resources are the primary data, and counts against that type's include depth
     */
    private function relatedDocument(RelationshipMetadata $relationship): stdClass
    {
        $resource = self::reference(self::resourceName($relationship->relatedType));
        $toMany = $relationship->cardinality === Cardinality::ToMany;
        $properties = [
            Member::JSONAPI => self::reference(self::JSON_API),
            Member::LINKS => $toMany ? self::pagedLinks() : self::selfLinks(),
        ];

        if ($toMany) {
            $properties[Member::DATA] = (object) ['type' => 'array', 'items' => $resource];
        } elseif ($relationship->nullable) {
            $properties[Member::DATA] = (object) [
                'anyOf' => [$resource, (object) ['type' => 'null']],
                'description' => 'null where the relationship is empty [spec, Top Level]',
            ];
        } else {
            $properties[Member::DATA] = $resource;
        }

        $properties[Member::INCLUDED] = $this->included($this->api->resources->includedTypes(
            $relationship->relatedType,
            null,
        ));

        if ($toMany) {
            $properties[Member::META] = $this->pageMeta();
        }

        return self::objectSchema(
            $properties,
            [Member::JSONAPI, Member::LINKS, Member::DATA],
            sprintf(
                'a document whose primary data is what the relationship "%s" points at, resources of type "%s"',
                $relationship->name,
                $relationship->relatedType,
            ),
        );
    }

    private function createRequest(ResourceMetadata $resource): stdClass
    {
        return self::requestDocument(
            $this->createResourceObject($resource, false),
            sprintf('the document a create of type "%s" reads', $resource->type),
        );
    }

    /**
     * a type that forbids a client-generated id gets no `id` member;
     * the server responds to one with 403.
     * `lid` is always described, since any create may carry one,
     * though it takes effect only inside an atomic request.
     * `attributes` or `relationships` is itself required once a field in it is,
     * since a nested `required` applies only when the object is present.
     * the `not` mirrors the server's refusal of `id` together with `lid`
     *
     * @param bool $atomic whether a linkage may name a `lid`
     */
    private function createResourceObject(ResourceMetadata $resource, bool $atomic): stdClass
    {
        $properties = [Member::TYPE => self::constant($resource->type)];
        $required = [Member::TYPE];

        if ($resource->clientIdPolicy !== ClientIdPolicy::Forbidden) {
            $properties[Member::ID] = self::idMember($resource);

            if ($resource->clientIdPolicy === ClientIdPolicy::Required) {
                $required[] = Member::ID;
            }
        }

        $properties[Member::LID] = self::lid(
            'the lid of the resource inside an atomic request [ext-atomic]',
        );
        $attributes = $this->attributeProperties($resource, self::MODE_CREATE);
        $requiredAttributes = $this->requiredFields($resource->attributes);

        if ($attributes !== []) {
            $properties[Member::ATTRIBUTES] = self::objectSchema(
                $attributes,
                $requiredAttributes,
                'the attributes a create may send',
            );

            if ($requiredAttributes !== []) {
                $required[] = Member::ATTRIBUTES;
            }
        }

        $relationships = $this->relationshipProperties($resource, self::MODE_CREATE, $atomic);
        $requiredRelationships = $this->requiredFields($resource->relationships);

        if ($relationships !== []) {
            $properties[Member::RELATIONSHIPS] = self::objectSchema(
                $relationships,
                $requiredRelationships,
                'the relationships a create may send, each with its linkage',
            );

            if ($requiredRelationships !== []) {
                $required[] = Member::RELATIONSHIPS;
            }
        }

        $properties += self::ignoredOfAResourceObject();

        return self::objectSchema(
            $properties,
            $required,
            $atomic
                ? sprintf(
                    'the resource of type "%s" an "add" operation creates, the data of that operation'
                    . ' [ext-atomic]',
                    $resource->type,
                )
                : sprintf('the resource of type "%s" to create', $resource->type),
            constraints: $resource->clientIdPolicy === ClientIdPolicy::Forbidden
                ? []
                : ['not' => (object) ['required' => [Member::ID, Member::LID]]],
        );
    }

    private function updateRequest(ResourceMetadata $resource): stdClass
    {
        return self::requestDocument(
            $this->updateResourceObject($resource, false),
            sprintf('the document an update of type "%s" reads', $resource->type),
        );
    }

    /**
     * an atomic "update" without a `ref` names its target by its data,
     * by `id` or by the `lid` a prior operation assigned, so `id` is not required there
     */
    private function updateResourceObject(ResourceMetadata $resource, bool $atomic): stdClass
    {
        $properties = [
            Member::TYPE => self::constant($resource->type),
            Member::ID => self::idMember($resource),
        ];

        if ($atomic) {
            $properties[Member::LID] = self::lid(sprintf(
                'the lid a prior operation of the request assigned to the resource of type "%s" this'
                . ' operation updates [ext-atomic]',
                $resource->type,
            ));
        }

        $attributes = $this->attributeProperties($resource, self::MODE_UPDATE);

        if ($attributes !== []) {
            $properties[Member::ATTRIBUTES] = self::objectSchema(
                $attributes,
                [],
                'the attributes an update may send; the ones it leaves out keep their value',
            );
        }

        $relationships = $this->relationshipProperties($resource, self::MODE_UPDATE, $atomic);

        if ($relationships !== []) {
            $properties[Member::RELATIONSHIPS] = self::objectSchema(
                $relationships,
                [],
                'the relationships an update may send; the ones it leaves out keep their value',
            );
        }

        $properties += self::ignoredOfAResourceObject();

        if (!$atomic) {
            return self::objectSchema(
                $properties,
                [Member::TYPE, Member::ID],
                sprintf('the resource of type "%s" to update', $resource->type),
            );
        }

        return self::objectSchema(
            $properties,
            [Member::TYPE],
            sprintf(
                'the resource of type "%s" an "update" operation writes, the data of that operation: it names its'
                . ' target by its id, or by the lid a prior operation assigned it'
                . ' [ext-atomic]',
                $resource->type,
            ),
            constraints: self::idOrLid(),
        );
    }

    /**
     * one schema for every write of the relationship route: replace, add and remove all read `data`
     */
    private function relationshipRequest(ResourceMetadata $resource, RelationshipMetadata $relationship): stdClass
    {
        return self::requestDocument(
            self::reference(self::linkageName($resource->type, $relationship->name)),
            sprintf(
                'the document an update of the relationship "%s" of type "%s" reads',
                $relationship->name,
                $resource->type,
            ),
        );
    }

    private function jsonApiObject(): stdClass
    {
        return self::objectSchema(
            [
                Member::VERSION => (object) ['type' => 'string', 'const' => JsonApiObject::VERSION],
                Member::EXT => (object) [
                    'type' => 'array',
                    'items' => (object) ['type' => 'string', 'format' => 'uri'],
                ],
                Member::PROFILE => (object) [
                    'type' => 'array',
                    'items' => (object) ['type' => 'string', 'format' => 'uri'],
                ],
            ],
            [Member::VERSION],
            'the object describing the implementation, with the extensions and the profiles applied to the'
            . ' document',
        );
    }

    /**
     * the library writes a link with nothing but its target as a string, any other as a link object
     */
    private function link(): stdClass
    {
        return (object) [
            'anyOf' => [
                (object) ['type' => 'string', 'format' => 'uri-reference'],
                self::objectSchema(
                    [
                        Member::HREF => (object) ['type' => 'string', 'format' => 'uri-reference'],
                        Member::REL => (object) ['type' => 'string'],
                        Member::DESCRIBEDBY => self::reference(self::LINK),
                        Member::TITLE => (object) ['type' => 'string'],
                        Member::TYPE => (object) ['type' => 'string'],
                        Member::HREFLANG => (object) [
                            'anyOf' => [
                                (object) ['type' => 'string'],
                                (object) ['type' => 'array', 'items' => (object) ['type' => 'string']],
                            ],
                        ],
                        Member::META => (object) ['type' => 'object'],
                    ],
                    [Member::HREF],
                ),
            ],
            'description' => 'one link of a links object: the URL of the target, or a link object that describes it',
        ];
    }

    private function nullableLink(): stdClass
    {
        return (object) [
            'anyOf' => [self::reference(self::LINK), (object) ['type' => 'null']],
            'description' => 'a pagination link, null where there is no such page [spec, Pagination]',
        ];
    }

    private function errorObject(): stdClass
    {
        return self::objectSchema(
            [
                Member::STATUS => (object) ['type' => 'string', 'pattern' => '^[45][0-9]{2}$'],
                Member::CODE => (object) ['type' => 'string'],
                Member::TITLE => (object) ['type' => 'string'],
                Member::DETAIL => (object) ['type' => 'string'],
                Member::SOURCE => (object) [
                    'type' => 'object',
                    'properties' => (object) [
                        Member::POINTER => (object) ['type' => 'string', 'format' => 'json-pointer'],
                        Member::PARAMETER => (object) ['type' => 'string'],
                        Member::HEADER => (object) ['type' => 'string'],
                    ],
                    'minProperties' => 1,
                    'maxProperties' => 1,
                    'additionalProperties' => false,
                    'description' => 'what caused the error; the object "SHOULD include one of the following members'
                        . ' or be omitted" [spec, Error Objects]',
                ],
                Member::LINKS => self::objectSchema(
                    [Member::TYPE => self::reference(self::LINK)],
                    [],
                    'the type link a profile or an extension defines for the error',
                ),
                Member::META => (object) ['type' => 'object'],
            ],
            [Member::STATUS, Member::CODE, Member::TITLE],
            'one problem of a failed request',
        );
    }

    private function errorDocument(): stdClass
    {
        return self::objectSchema(
            [
                Member::JSONAPI => self::reference(self::JSON_API),
                Member::ERRORS => (object) [
                    'type' => 'array',
                    'items' => self::reference(self::ERROR),
                    'minItems' => 1,
                ],
                Member::META => (object) ['type' => 'object'],
            ],
            [Member::JSONAPI, Member::ERRORS],
            'the document of a failed request',
        );
    }

    /**
     * the member is present whenever `include` is sent, even empty.
     * where no type is reachable, `maxItems: 0` still admits it,
     * since a client may send an empty `include`
     *
     * @param list<string> $types
     */
    private function included(array $types): stdClass
    {
        if ($types === []) {
            return (object) [
                'type' => 'array',
                'maxItems' => 0,
                'description' => 'the resources include asked for; no relationship this document reaches is'
                    . ' includable, so it holds none',
            ];
        }

        $items = count($types) === 1
            ? self::reference(self::resourceName($types[0]))
            : (object) [
                'oneOf' => array_map(
                    static function (string $type): stdClass {
                        return self::reference(self::resourceName($type));
                    },
                    $types,
                ),
            ];

        return (object) [
            'type' => 'array',
            'items' => $items,
            'description' => 'the resources include asked for, present whenever include is supplied, even when it'
                . ' holds none',
        ];
    }

    private static function selfLinks(): stdClass
    {
        return self::objectSchema(
            [Member::SELF => self::reference(self::LINK)],
            [Member::SELF],
            'the links of the document: its self link, which responds to a GET with this document',
        );
    }

    private static function relationshipDocumentLinks(bool $self, bool $related): stdClass
    {
        $links = [];

        if ($self) {
            $links[Member::SELF] = self::reference(self::LINK);
        }

        if ($related) {
            $links[Member::RELATED] = self::reference(self::LINK);
        }

        return self::objectSchema(
            $links,
            array_keys($links),
            'the links of the document: its self link and "a related resource link when the primary data'
            . ' represents a resource relationship" [spec, Top Level], each where its route responds to a GET',
        );
    }

    private static function pagedLinks(bool $withRelated = false): stdClass
    {
        $links = [Member::SELF => self::reference(self::LINK)];

        if ($withRelated) {
            $links[Member::RELATED] = self::reference(self::LINK);
        }

        foreach ([Member::FIRST, Member::LAST, Member::PREV, Member::NEXT] as $page) {
            $links[$page] = self::reference(self::NULLABLE_LINK);
        }

        return self::objectSchema(
            $links,
            $withRelated ? [Member::SELF, Member::RELATED] : [Member::SELF],
            $withRelated
                ? 'the links of the document: its self link, "a related resource link when the primary data'
                    . ' represents a resource relationship" [spec, Top Level], and the pagination links of the'
                    . ' primary data [spec, Pagination]'
                : 'the links of the document: its self link and the pagination links of the primary data'
                    . ' [spec, Pagination]',
        );
    }

    private function pageMeta(): stdClass
    {
        $naming = $this->api->naming;

        return self::objectSchema(
            [
                PageMeta::MEMBER => self::objectSchema(
                    [
                        PageMeta::Total->nameIn($naming) => (object) ['type' => 'integer', 'minimum' => 0],
                        PageMeta::EstimatedTotal->nameIn($naming) => self::objectSchema(
                            [PageMeta::BestGuess->nameIn($naming) => (object) ['type' => 'integer', 'minimum' => 0]],
                            [],
                            'an estimate of the size of the full results list [profile-cursor]',
                        ),
                        PageMeta::RangeTruncated->nameIn($naming) => (object) ['type' => 'boolean'],
                    ],
                    [],
                    'the pagination metadata of the collection [profile-cursor]',
                ),
            ],
            [],
            'the non-standard information of the document',
        );
    }

    /**
     * the server reads `data` alone and ignores the other top-level members the spec allows;
     * `errors` is not among them, since it must not coexist with `data`
     */
    private static function requestDocument(stdClass $data, string $description): stdClass
    {
        return self::objectSchema(
            [
                Member::DATA => $data,
                Member::META => self::ignored('object', 'non-standard information of the document; the library reads'
                    . ' none of it [spec, Meta Information]'),
                Member::JSONAPI => self::ignored('object', 'the object describing the implementation of the client;'
                    . ' the library reads none of it [spec, Top Level]'),
                Member::LINKS => self::ignored('object', 'the links of the document; the library reads none of them'
                    . ' [spec, Top Level]'),
                Member::INCLUDED => self::ignored('array', 'the resources related to the primary data; the library'
                    . ' reads none of them, and a request creates or updates what its data names alone'
                    . ' [spec, Compound Documents]'),
            ],
            [Member::DATA],
            $description,
        );
    }

    /**
     * `withAnnotations()` keeps the codec's own keyword where it gets `null`,
     * hence `null` rather than `false` for an attribute that is not deprecated
     *
     * @param self::MODE_* $mode
     *
     * @return array<array-key, stdClass>
     */
    private function attributeProperties(ResourceMetadata $resource, string $mode): array
    {
        $properties = [];

        foreach ($resource->attributes as $attribute) {
            if (!self::carries($attribute, $mode)) {
                continue;
            }

            $schema = $attribute->codec->schema();
            $directed = $mode === self::MODE_READ ? $schema->forResponse() : $schema->forRequest();
            $properties[$attribute->name] = $directed->withAnnotations(
                description: $attribute->description,
                deprecated: $attribute->deprecated ? true : null,
            )->toObject();
        }

        return $properties;
    }

    /**
     * an update leaves out a to-many relationship that does not accept replacement:
     * the server refuses such a member with 403, whatever its write rule says.
     * a create replaces nothing, so there the write rule alone decides
     *
     * @param self::MODE_CREATE|self::MODE_UPDATE $mode
     * @param bool                                $atomic whether a linkage may name a `lid`
     *
     * @return array<array-key, stdClass>
     */
    private function relationshipProperties(ResourceMetadata $resource, string $mode, bool $atomic): array
    {
        $properties = [];

        foreach ($resource->relationships as $relationship) {
            $rule = $relationship->writeRule;

            if (!($mode === self::MODE_CREATE ? $rule->creatable : $rule->updatable)) {
                continue;
            }

            if (
                $mode === self::MODE_UPDATE
                && $relationship->cardinality === Cardinality::ToMany
                && !$relationship->accepts(RelationshipUpdate::Replace)
            ) {
                continue;
            }

            $properties[$relationship->name] = self::objectSchema(
                [
                    Member::DATA => self::reference($atomic
                        ? self::atomicLinkageName($resource->type, $relationship->name)
                        : self::linkageName($resource->type, $relationship->name)),
                    Member::LINKS => self::ignored('object', 'the links of the relationship; the library reads none'
                        . ' of them in a request [spec, Relationships]'),
                    Member::META => self::ignored('object', 'non-standard information about the relationship; the'
                        . ' library reads none of it [spec, Relationships]'),
                ],
                [Member::DATA],
                $relationship->description ?? sprintf('the relationship "%s" of the resource', $relationship->name),
                $relationship->deprecated,
            );
        }

        return $properties;
    }

    /**
     * @param array<array-key, AttributeMetadata|RelationshipMetadata> $fields
     *
     * @return list<string>
     */
    private function requiredFields(array $fields): array
    {
        $names = [];

        foreach ($fields as $field) {
            if ($field->writeRule->required) {
                $names[] = $field->name;
            }
        }

        return $names;
    }

    /**
     * @param self::MODE_* $mode
     */
    private static function carries(AttributeMetadata $attribute, string $mode): bool
    {
        return match ($mode) {
            self::MODE_READ => $attribute->hasAccessor(),
            self::MODE_CREATE => $attribute->writeRule->creatable,
            self::MODE_UPDATE => $attribute->writeRule->updatable,
        };
    }

    /**
     * every object admits @-members besides its named ones:
     * the spec allows them anywhere, and the server skips them.
     * a member `patternProperties` matches is not additional,
     * so `additionalProperties: false` still refuses every other member
     *
     * @param array<array-key, stdClass> $properties
     * @param list<string>               $required
     * @param array<string, mixed>       $constraints
     *
     * @internal
     */
    public static function objectSchema(
        array $properties,
        array $required = [],
        ?string $description = null,
        bool $deprecated = false,
        array $constraints = [],
    ): stdClass {
        $schema = ['type' => 'object'];

        if ($properties !== []) {
            $schema['properties'] = (object) $properties;
        }

        if ($required !== []) {
            $schema['required'] = $required;
        }

        $schema['additionalProperties'] = false;
        $schema['patternProperties'] = (object) [self::AT_MEMBER => true];

        foreach ($constraints as $keyword => $value) {
            $schema[$keyword] = $value;
        }

        if ($description !== null) {
            $schema['description'] = $description;
        }

        if ($deprecated) {
            $schema['deprecated'] = true;
        }

        return (object) $schema;
    }

    private static function constant(string $value): stdClass
    {
        return (object) ['type' => 'string', 'const' => $value];
    }

    /**
     * a `lid` is a name the client gives a resource for the length of the request, not an id,
     * so the id codec does not describe it;
     * an empty one identifies nothing, and the server responds to it with 422
     */
    private static function lid(string $description): stdClass
    {
        return (object) ['type' => 'string', 'minLength' => 1, 'description' => $description];
    }

    /**
     * `minLength` is at least 1: an empty id identifies no resource, and the server accepts none
     */
    private static function idMember(ResourceMetadata $resource): stdClass
    {
        $schema = $resource->id->codec->schema();
        $object = $schema->toObject();

        if ($schema->minLength === null || $schema->minLength < 1) {
            $object->minLength = 1;
        }

        return $object;
    }

    /**
     * @return array<string, stdClass>
     */
    private static function ignoredOfAResourceObject(): array
    {
        return [
            Member::LINKS => self::ignored('object', 'the links of the resource; the library reads none of them in a'
                . ' request [spec, Resource Objects]'),
            Member::META => self::ignored('object', 'non-standard information about the resource; the library reads'
                . ' none of it [spec, Resource Objects]'),
        ];
    }

    /**
     * a member the spec allows in a request and the server ignores.
     * the contract must admit it: under `additionalProperties: false`,
     * leaving it out would publish a refusal the server never makes.
     * response schemas omit such members, since the library never writes them
     *
     * @param 'array'|'object' $type
     *
     * @internal
     */
    public static function ignored(string $type, string $description): stdClass
    {
        return (object) ['type' => $type, 'description' => $description];
    }

    /**
     * exactly one of `id` and `lid`: the server refuses an object with both or neither
     *
     * @return array<string, mixed>
     */
    private static function idOrLid(): array
    {
        return ['oneOf' => [(object) ['required' => [Member::ID]], (object) ['required' => [Member::LID]]]];
    }

    private static function resourceName(string $type): string
    {
        return self::componentName($type, 'resource');
    }

    private static function identifierName(string $type): string
    {
        return self::componentName($type, 'identifier');
    }

    private static function atomicIdentifierName(string $type): string
    {
        return self::componentName($type, 'identifier', self::ATOMIC);
    }

    private static function linkageName(string $type, string $relationship): string
    {
        return self::componentName($type, $relationship, 'linkage');
    }

    /**
     * a component key allows only `[A-Za-z0-9._-]`,
     * while a member name may hold a space or any character above U+007F.
     * such a segment becomes an empty segment followed by the upper-case hex of its UTF-8 bytes:
     * `a b` becomes `.612062`. only an escaped segment starts with a dot,
     * so two distinct names never escape to the same key
     */
    private static function componentSegment(string $segment): string
    {
        if (preg_match(self::UNESCAPED, $segment) === 1) {
            return $segment;
        }

        return '.' . strtoupper(bin2hex($segment));
    }
}
