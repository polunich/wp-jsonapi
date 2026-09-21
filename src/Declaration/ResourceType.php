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

namespace Polunich\WpJsonApi\Declaration;

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the builder is mutable, and every method returns this object: a chain is one declaration,
 * and a call whose result is dropped still counts,
 * such as `$type->creator($implementation);` on its own line.
 * an immutable builder would lose that call without a word.
 *
 * each part is declared once, and declaring it again throws `InvalidArgumentException`;
 * a part given per kind, capability or name, such as a permission, is declared once for each.
 *
 * `attribute()` and `relationship()` build their argument at once,
 * so a later change to that builder does not reach the resource,
 * and a faulty field fails where it is declared.
 * `build()` hands the rest to `ResourceMetadata`, which refuses what no resource can be.
 * the rules spanning types, such as a related type that must be declared,
 * are checked when the API is built, never on a request
 */
final class ResourceType
{
    private const DEFAULT_MAX_INCLUDE_DEPTH = 1;

    private const DEFAULT_ID_PARAMETER = 'id';

    private ?SegmentCodec $idCodec = null;

    private ?Closure $idAccessor = null;

    private bool $globallyUniqueIds = false;

    /**
     * @var list<string>
     */
    private array $idOperators = [];

    private ?string $idParameter = null;

    private ?string $path = null;

    private ?RouteExclusion $excluded = null;

    /**
     * @var array<array-key, AttributeMetadata> name => attribute
     */
    private array $attributes = [];

    /**
     * @var array<array-key, RelationshipMetadata> name => relationship
     */
    private array $relationships = [];

    /**
     * @var array<value-of<Capability>, ImplementationReference>
     */
    private array $capabilities = [];

    /**
     * @var array<value-of<OperationKind>, ImplementationReference>
     */
    private array $permissions = [];

    /**
     * @var array<value-of<OperationKind>, string> the monitor type of each write
     *                                             that may get 202
     */
    private array $monitorTypes = [];

    private ClientIdPolicy $clientIdPolicy = ClientIdPolicy::Forbidden;

    private ?VersionPolicy $versionPolicy = null;

    private ?Sort $defaultSort = null;

    private ?PaginationPolicy $paginationPolicy = null;

    private ?int $maxIncludeDepth = null;

    private ?string $description = null;

    /**
     * @var array<value-of<OperationKind>, list<QueryParameterMetadata>>
     */
    private array $queryParameters = [];

    /**
     * @var array<value-of<OperationKind>, list<SecurityScheme>>
     */
    private array $securitySchemes = [];

    private function __construct(private string $type) {}

    /**
     * a resource whose routes lie at `/<type>` and below it, unless `path()` declares another path;
     * `build()` throws when the type is no member name
     */
    public static function make(string $type): self
    {
        return new self($type);
    }

    /**
     * how the id is read and written:
     * the codec decodes the id of a request and encodes the id of a document,
     * and its segment pattern describes the id in a path;
     * the accessor reads the id from a record.
     * every resource declares one, and `build()` throws without it
     *
     * @template TRecord of object
     *
     * @param SegmentCodec             $codec    `DigitsCodec`, `UuidCodec` or one of the project
     * @param Closure(TRecord): string $accessor
     *
     * @throws InvalidArgumentException when the resource already declares its id
     */
    public function id(SegmentCodec $codec, Closure $accessor, bool $globallyUnique = false): self
    {
        if ($this->idCodec !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an id');
        }

        $this->idCodec = $codec;
        $this->idAccessor = $accessor;
        $this->globallyUniqueIds = $globallyUnique;

        return $this;
    }

    /**
     * the operators a filter may apply to the id, under the rules of `Attribute::filter()`:
     * `filter[id][@in][]=1`, and through a relationship to the type, `filter[owner][id][@eq]=1`
     *
     * @param string ...$operators such as `@eq` or `@in`
     */
    public function filterId(string ...$operators): self
    {
        foreach ($operators as $operator) {
            $this->idOperators[] = $operator;
        }

        return $this;
    }

    /**
     * the name of the path parameter that carries the id in every route of the type,
     * such as `pet_id` in `/pets/{pet_id}` and `/pets/{pet_id}/relationships/owner`;
     * without this call it is `id`.
     * the name: a letter or `_`, then at most 31 letters, digits or `_`; `build()` throws otherwise
     *
     * @throws InvalidArgumentException when the resource already declares its id parameter
     */
    public function idParameter(string $name): self
    {
        if ($this->idParameter !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an id parameter');
        }

        $this->idParameter = $name;

        return $this;
    }

    /**
     * the path of the collection route, which every other route of the type extends:
     * `/shop/orders` lays out `/shop/orders/{id}`
     * and `/shop/orders/{id}/relationships/<relationship>`;
     * without this call the path is `/<type>`.
     * documents keep the type, and every link follows the path.
     * the path is written as the path of an endpoint with literal segments alone:
     * a link to a resource of the type from another route would have no value for a parameter.
     * `build()` throws for a path that breaks these rules
     *
     * @throws InvalidArgumentException when the resource already declares its path
     */
    public function path(string $path): self
    {
        if ($this->path !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a path');
        }

        $this->path = $path;

        return $this;
    }

    /**
     * keeps the routes of the named kinds and excludes the others of the five; see `except()`
     *
     * @throws InvalidArgumentException when the resource already declares its routes,
     *                                  or a kind is none of the five or is named twice
     */
    public function only(OperationKind $kind, OperationKind ...$kinds): self
    {
        return $this->exclude(RouteExclusion::only(
            RouteOwner::Resource,
            [$kind, ...array_values($kinds)],
            $this->declaration(),
        ));
    }

    /**
     * excludes the routes of the named kinds, out of the five of the resources of the type:
     * `FetchCollection` and `CreateResource` on the collection route,
     * `FetchResource`, `UpdateResource` and `DeleteResource` on the resource route.
     * an excluded route stays registered and responds with 403 `operation_not_supported`,
     * no document links to it, and the contract leaves it out.
     * without `FetchResource` no resource object of the type carries a self link.
     *
     * a write is enabled by binding its port,
     * so excluding a write whose port is bound fails `build()`.
     * a relationship excludes its reads with its own `only()` or `except()`,
     * so a kind that addresses a relationship throws here
     *
     * @throws InvalidArgumentException when the resource already declares its routes,
     *                                  or a kind is none of the five or is named twice
     */
    public function except(OperationKind $kind, OperationKind ...$kinds): self
    {
        return $this->exclude(RouteExclusion::except(
            RouteOwner::Resource,
            [$kind, ...array_values($kinds)],
            $this->declaration(),
        ));
    }

    /**
     * attributes keep their declaration order
     *
     * @throws InvalidArgumentException when an attribute of that name is already declared,
     *                                  or the attribute is invalid
     */
    public function attribute(Attribute $attribute): self
    {
        $metadata = $attribute->build();

        if (array_key_exists($metadata->name, $this->attributes)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf(
                'attribute "%s"',
                $metadata->name,
            ));
        }

        $this->attributes[$metadata->name] = $metadata;

        return $this;
    }

    /**
     * relationships keep their declaration order; a name that is also an attribute fails `build()`
     *
     * @throws InvalidArgumentException when a relationship of that name is already declared,
     *                                  or the relationship is invalid
     */
    public function relationship(Relationship $relationship): self
    {
        $metadata = $relationship->build();

        if (array_key_exists($metadata->name, $this->relationships)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf(
                'relationship "%s"',
                $metadata->name,
            ));
        }

        $this->relationships[$metadata->name] = $metadata;

        return $this;
    }

    /**
     * binds the `Store\CollectionReader`, which serves the collection route
     * and the related and relationship routes of every to-many relationship pointing at this type.
     * without it the collection route responds with 403.
     * beside an excluded `FetchCollection` it serves those relationships alone,
     * so the API fails to build when no to-many relationship points at the type
     *
     * @throws InvalidArgumentException when the resource already binds a collection reader
     */
    public function reader(Implementation $implementation): self
    {
        return $this->bind(Capability::CollectionReader, $implementation);
    }

    /**
     * binds the `Store\ResourceLoader`, which loads a record by id:
     * the target of the resource route and of every route below it,
     * the records a to-one relationship with a related id accessor points at,
     * and the record an endpoint binds to a parameter of the type.
     * a type binds one exactly when one of these reads through it;
     * the API fails to build otherwise, in either direction
     *
     * @throws InvalidArgumentException when the resource already binds a resource loader
     */
    public function loader(Implementation $implementation): self
    {
        return $this->bind(Capability::ResourceLoader, $implementation);
    }

    /**
     * binds the `Store\RelatedLoader`, which loads the records a relationship points at:
     * for `include`, for linkage rendered without `include`,
     * and for the routes of a to-one relationship without a related id accessor.
     * the API fails to build when a relationship needs one and none is bound
     *
     * @throws InvalidArgumentException when the resource already binds a related loader
     */
    public function relatedLoader(Implementation $implementation): self
    {
        return $this->bind(Capability::RelatedLoader, $implementation);
    }

    /**
     * binds the `Store\ResourceCreator`; without it a create gets 403
     *
     * @throws InvalidArgumentException when the resource already binds a resource creator
     */
    public function creator(Implementation $implementation): self
    {
        return $this->bind(Capability::ResourceCreator, $implementation);
    }

    /**
     * binds the `Store\ResourceUpdater`; without it an update gets 403
     *
     * @throws InvalidArgumentException when the resource already binds a resource updater
     */
    public function updater(Implementation $implementation): self
    {
        return $this->bind(Capability::ResourceUpdater, $implementation);
    }

    /**
     * binds the `Store\ResourceDeleter`; without it a delete gets 403
     *
     * @throws InvalidArgumentException when the resource already binds a resource deleter
     */
    public function deleter(Implementation $implementation): self
    {
        return $this->bind(Capability::ResourceDeleter, $implementation);
    }

    /**
     * binds the `Store\RelationshipReplacer`,
     * which carries out the update `replaceable()` declares on a relationship
     *
     * @throws InvalidArgumentException when the resource already binds a relationship replacer
     */
    public function relationshipReplacer(Implementation $implementation): self
    {
        return $this->bind(Capability::RelationshipReplacer, $implementation);
    }

    /**
     * binds the `Store\RelationshipAdder`,
     * which carries out the update `ToMany::addable()` declares
     *
     * @throws InvalidArgumentException when the resource already binds a relationship adder
     */
    public function relationshipAdder(Implementation $implementation): self
    {
        return $this->bind(Capability::RelationshipAdder, $implementation);
    }

    /**
     * binds the `Store\RelationshipRemover`,
     * which carries out the update `ToMany::removable()` declares
     *
     * @throws InvalidArgumentException when the resource already binds a relationship remover
     */
    public function relationshipRemover(Implementation $implementation): self
    {
        return $this->bind(Capability::RelationshipRemover, $implementation);
    }

    /**
     * the permission of one operation kind: an implementation of `Access\OperationPermission`,
     * whose denial the library responds to with 401 or 403.
     * every kind the resource enables needs one and no other kind may have one,
     * so a public operation declares a permission that always allows; `build()` throws otherwise
     *
     * @throws InvalidArgumentException when a permission for that kind is already declared
     */
    public function permission(OperationKind $kind, Implementation $implementation): self
    {
        if (array_key_exists($kind->value, $this->permissions)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf(
                'a permission for %s',
                $kind->value,
            ));
        }

        $this->permissions[$kind->value] = $implementation->build();

        return $this;
    }

    /**
     * a query parameter the route of `$kind` reads, declared as an endpoint declares one;
     * its value reaches the operation and the store as `$queryParameters`.
     * `build()` refuses it where the type does not enable that route,
     * and refuses `$required` on `FetchResource`, which a document links to without it
     *
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the kind addresses a relationship,
     *                                  the name breaks the rule of a custom query parameter,
     *                                  or it is declared twice for the kind
     */
    public function queryParameter(OperationKind $kind, string $name, Codec $codec, bool $required = false): self
    {
        if ($kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                '%s declares a query parameter for %s, which addresses a relationship: a relationship declares the'
                . ' query parameters of its routes with its own queryParameter()',
                $this->declaration(),
                $kind->value,
            ));
        }

        $this->queryParameters[$kind->value] = QueryParameters::added(
            $this->queryParameters[$kind->value] ?? [],
            new QueryParameterMetadata($name, $codec, $required),
            $this->declaration(),
            $kind,
        );

        return $this;
    }

    /**
     * the schemes the contract lists for the route of `$kind`, in place of the API's;
     * with none, the route lists only the empty requirement, anonymous access.
     * `build()` refuses them where the type does not enable that route
     *
     * @throws InvalidArgumentException when the kind addresses a relationship,
     *                                  or the schemes of the kind are written twice
     */
    public function securitySchemes(OperationKind $kind, SecurityScheme ...$schemes): self
    {
        if ($kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                '%s declares security schemes for %s, which addresses a relationship: a relationship declares'
                . ' the security schemes of its routes with its own securitySchemes()',
                $this->declaration(),
                $kind->value,
            ));
        }

        if (array_key_exists($kind->value, $this->securitySchemes)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf('the security schemes of %s', $kind->value));
        }

        $this->securitySchemes[$kind->value] = array_values($schemes);

        return $this;
    }

    /**
     * lets a write of that kind get 202: its implementation returns `Store\Accepted`,
     * and the response carries a status monitor of `$monitorType` as primary data,
     * with `Content-Location` set to the monitor's self link.
     * only a write the resource enables may declare one, and the monitor type must be declared too
     *
     * @throws InvalidArgumentException when a monitor type for that kind is already declared
     */
    public function monitorType(OperationKind $kind, string $monitorType): self
    {
        if (array_key_exists($kind->value, $this->monitorTypes)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf(
                'a monitor type for %s',
                $kind->value,
            ));
        }

        $this->monitorTypes[$kind->value] = $monitorType;

        return $this;
    }

    /**
     * a create may send the id of its resource, and with `$required` must.
     * without this call a create with an id gets 403, as the specification requires;
     * with `$required` a create without one gets 422 at `/data`.
     * client ids need globally unique ids:
     * an id codec of the uuid format, or `id(globallyUnique: true)`
     *
     * @throws InvalidArgumentException when the resource already declares a client id policy
     */
    public function clientIds(bool $required = false): self
    {
        if ($this->clientIdPolicy !== ClientIdPolicy::Forbidden) {
            throw WrittenTwice::refusal($this->declaration(), 'a client id policy');
        }

        $this->clientIdPolicy = $required ? ClientIdPolicy::Required : ClientIdPolicy::Allowed;

        return $this;
    }

    /**
     * how the version of a record is read: a single-resource response carries a strong `ETag`
     * built from it, and `PATCH`, `DELETE` and relationship writes check `If-Match` against it.
     * with `$preconditionsRequired` such a write without `If-Match` gets 428
     *
     * @template TRecord of object
     *
     * @param Closure(TRecord): string $accessor
     *
     * @throws InvalidArgumentException when the resource already declares a version policy
     */
    public function version(Closure $accessor, bool $preconditionsRequired = false): self
    {
        if ($this->versionPolicy !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a version policy');
        }

        $this->versionPolicy = new VersionPolicy($accessor, $preconditionsRequired);

        return $this;
    }

    /**
     * the order of the collection route, and of a page endpoint of the type without one of its own,
     * for a request that sends no `sort`;
     * written in the sort syntax of the specification, such as `-listedAt,name`.
     * without one the order is by id, and `id` is appended to any sort that does not order by it.
     * the API build checks that each path is sortable and never null,
     * and refuses a default sort that neither of the two reads
     *
     * @throws InvalidArgumentException when the resource already declares a default sort,
     *                                  or the text is no sort
     */
    public function defaultSort(string $sort): self
    {
        if ($this->defaultSort !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a default sort');
        }

        $this->defaultSort = Sort::fromString($sort);

        return $this;
    }

    /**
     * the page sizes of the collection route, and of a page endpoint of the type
     * or a to-many relationship to it that declares none of its own;
     * without this call they are 10 and 100
     *
     * @param int $defaultSize used when a request names no size; at least 1
     * @param int $maxSize     a request with a larger size gets 400 with the maximum in `meta.page`
     *
     * @throws InvalidArgumentException when the resource already declares a pagination policy,
     *                                  or the default size is below 1 or above the maximum
     */
    public function pagination(int $defaultSize, int $maxSize): self
    {
        if ($this->paginationPolicy !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a pagination policy');
        }

        $this->paginationPolicy = new PaginationPolicy($defaultSize, $maxSize);

        return $this;
    }

    /**
     * the most relationships an include path on this resource may run through, at least 1;
     * a request with a deeper path gets 400. without this call the depth is 1
     *
     * @throws InvalidArgumentException when the resource already declares an include depth
     */
    public function maxIncludeDepth(int $depth): self
    {
        if ($this->maxIncludeDepth !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'an include depth');
        }

        $this->maxIncludeDepth = $depth;

        return $this;
    }

    /**
     * the description of the resource in the contract
     *
     * @throws InvalidArgumentException when the resource already declares a description
     */
    public function description(string $description): self
    {
        if ($this->description !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a description');
        }

        $this->description = $description;

        return $this;
    }

    /**
     * @throws InvalidArgumentException when the resource declares no id, its path breaks a rule,
     *                                  or `ResourceMetadata` refuses the declaration
     *
     * @internal
     */
    public function build(): ResourceMetadata
    {
        if ($this->idCodec === null || $this->idAccessor === null) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" declares no id, and "Every resource object MUST also contain an id member" [spec]',
                Printable::of($this->type),
            ));
        }

        return new ResourceMetadata(
            $this->type,
            new IdMetadata($this->idCodec, $this->idAccessor, $this->globallyUniqueIds, $this->idOperators),
            $this->idParameter ?? self::DEFAULT_ID_PARAMETER,
            $this->attributes,
            $this->relationships,
            $this->capabilities,
            $this->permissions,
            $this->monitorTypes,
            $this->excluded ?? RouteExclusion::none(RouteOwner::Resource),
            $this->clientIdPolicy,
            $this->versionPolicy,
            $this->defaultSort,
            $this->paginationPolicy ?? PaginationPolicy::libraryDefault(),
            $this->maxIncludeDepth ?? self::DEFAULT_MAX_INCLUDE_DEPTH,
            $this->description,
            $this->queryParameters,
            $this->securitySchemes,
            $this->path === null ? null : $this->pathSegments($this->path),
        );
    }

    /**
     * @return non-empty-list<string>
     *
     * @throws InvalidArgumentException when the path holds a parameter,
     *                                  or breaks a rule of a written path
     */
    private function pathSegments(string $path): array
    {
        if (str_contains($path, '{') || str_contains($path, '}')) {
            throw new InvalidArgumentException(sprintf(
                '%s writes the path "%s" with a parameter, and the path of a type takes none: a link to a resource'
                . ' of the type from another route would have no value for it',
                $this->declaration(),
                Printable::of($path),
            ));
        }

        return array_map(static function (string|PathParameter $segment): string {
            return $segment instanceof PathParameter
                ? throw new LogicException('a path without a brace holds no parameter')
                : $segment;
        }, PathTemplate::written($path, [], $this->declaration())->segments);
    }

    /**
     * @throws InvalidArgumentException when the capability already has an implementation
     */
    private function bind(Capability $capability, Implementation $implementation): self
    {
        if (array_key_exists($capability->value, $this->capabilities)) {
            throw WrittenTwice::refusal($this->declaration(), sprintf(
                'an implementation of %s',
                $capability->value,
            ));
        }

        $this->capabilities[$capability->value] = $implementation->build();

        return $this;
    }

    /**
     * @throws InvalidArgumentException when the resource already declares its routes
     */
    private function exclude(RouteExclusion $excluded): self
    {
        if ($this->excluded !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its routes');
        }

        $this->excluded = $excluded;

        return $this;
    }

    /**
     * the type is escaped: `build()` has not checked it yet, and it may hold a control character
     */
    private function declaration(): string
    {
        return sprintf('type "%s"', Printable::of($this->type));
    }
}
