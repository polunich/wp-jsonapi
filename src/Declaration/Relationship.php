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
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Text\Printable;

/**
 * what a to-one and a to-many relationship declare alike.
 *
 * a mutable builder, as `ResourceType` is:
 * the flags may be repeated, and every other part is declared once.
 * `build()` hands the rest to `RelationshipMetadata`.
 * a declared update needs its port bound on the resource,
 * and the related type must be declared in the API
 *
 * @phpstan-sealed ToOne|ToMany
 */
abstract class Relationship
{
    private bool $linkageAlwaysPresent = false;

    private bool $includable = true;

    private ?RouteExclusion $excluded = null;

    private bool $replaceable = false;

    private bool $creatable = false;

    private bool $updatable = false;

    private bool $required = false;

    private ?string $description = null;

    private bool $deprecated = false;

    /**
     * @var array<value-of<OperationKind>, list<QueryParameterMetadata>>
     */
    private array $queryParameters = [];

    /**
     * @var array<value-of<OperationKind>, list<SecurityScheme>>
     */
    private array $securitySchemes = [];

    final protected function __construct(private readonly string $name, private readonly string $relatedType) {}

    /**
     * every document renders the linkage, loaded through the related loader,
     * even without `include`; a to-many linkage holds at most the default page size of identifiers
     */
    public function linkageAlwaysPresent(): static
    {
        $this->linkageAlwaysPresent = true;

        return $this;
    }

    /**
     * `include` may not name the relationship; a request that does gets 400
     */
    public function notIncludable(): static
    {
        $this->includable = false;

        return $this;
    }

    /**
     * keeps the routes of the named reads, `FetchRelated` and `FetchRelationship`,
     * and excludes the other; see `except()`
     *
     * @throws InvalidArgumentException when the relationship already declares its routes,
     *                                  or a kind is no read of a relationship or is named twice
     */
    public function only(OperationKind $kind, OperationKind ...$kinds): static
    {
        return $this->exclude(RouteExclusion::only(
            RouteOwner::Relationship,
            [$kind, ...array_values($kinds)],
            $this->declaration(),
        ));
    }

    /**
     * excludes the routes of the named reads:
     * `FetchRelated` on the related route, `FetchRelationship` on the relationship route.
     * an excluded route responds with 403 `relationship_fetch_not_supported`,
     * and no document links to it.
     * the writes are enabled by declaring them, so a write kind here throws.
     *
     * with both reads excluded no link is left, so every document carries the linkage,
     * loaded as by `linkageAlwaysPresent()`.
     * a to-many linkage is a page that links to its relationship route,
     * so a to-many relationship keeps that route
     * while it is includable, always present or declares an update,
     * and excluding both of its reads fails the resource type's `build()`
     *
     * @throws InvalidArgumentException when the relationship already declares its routes,
     *                                  or a kind is no read of a relationship or is named twice
     */
    public function except(OperationKind $kind, OperationKind ...$kinds): static
    {
        return $this->exclude(RouteExclusion::except(
            RouteOwner::Relationship,
            [$kind, ...array_values($kinds)],
            $this->declaration(),
        ));
    }

    /**
     * accepts replace: `PATCH` on the relationship route, which otherwise responds with 403,
     * and for a to-many relationship its member inside a `PATCH` of the resource.
     * requires `ResourceType::relationshipReplacer()`
     */
    public function replaceable(): static
    {
        $this->replaceable = true;

        return $this;
    }

    /**
     * a create may send the relationship; without this call one that does gets 422 at the member
     */
    public function creatable(): static
    {
        $this->creatable = true;

        return $this;
    }

    /**
     * an update may send the relationship;
     * without this call one that does gets 422 at the member.
     * a to-many relationship is sent as a full replacement, which also needs `replaceable()`
     */
    public function updatable(): static
    {
        $this->updatable = true;

        return $this;
    }

    /**
     * a create must send the relationship; one that does not gets 422 at the enclosing object.
     * requires `creatable()` as well, or `build()` throws
     */
    public function required(): static
    {
        $this->required = true;

        return $this;
    }

    /**
     * the description of the relationship in the contract
     *
     * @throws InvalidArgumentException when the relationship already declares a description
     */
    public function description(string $description): static
    {
        if ($this->description !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a description');
        }

        $this->description = $description;

        return $this;
    }

    /**
     * marks the relationship deprecated in the contract
     */
    public function deprecated(): static
    {
        $this->deprecated = true;

        return $this;
    }

    /**
     * a query parameter the route of `$kind` of this relationship reads,
     * as `ResourceType::queryParameter()` declares one for a route of the type.
     * `build()` refuses it where the relationship does not enable that route,
     * and refuses `$required` on a read, which a document links to without it
     *
     * @param Codec<mixed> $codec
     *
     * @throws InvalidArgumentException when the kind addresses no relationship,
     *                                  the name breaks the rule of a custom query parameter,
     *                                  or it is declared twice for the kind
     */
    public function queryParameter(OperationKind $kind, string $name, Codec $codec, bool $required = false): static
    {
        if (!$kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                '%s declares a query parameter for %s, which addresses no relationship: a type declares the query'
                . ' parameters of its resource routes with its own queryParameter()',
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
     * the schemes the contract lists for the route of `$kind` of this relationship,
     * as `ResourceType::securitySchemes()` lists those of a route of the type.
     * `build()` refuses them where the relationship does not enable that route
     *
     * @throws InvalidArgumentException when the kind addresses no relationship,
     *                                  or the schemes of the kind are written twice
     */
    public function securitySchemes(OperationKind $kind, SecurityScheme ...$schemes): static
    {
        if (!$kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                '%s declares security schemes for %s, which addresses no relationship: a type declares the'
                . ' security schemes of its resource routes with its own securitySchemes()',
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
     * @throws InvalidArgumentException when `RelationshipMetadata` or `WriteRule` refuses it
     *
     * @internal
     */
    abstract public function build(): RelationshipMetadata;

    /**
     * the relationship with what its cardinality adds; replace comes first among the updates
     *
     * @param list<RelationshipUpdate> $updates the updates beside replace
     *
     * @throws InvalidArgumentException when `RelationshipMetadata` or `WriteRule` refuses it
     *
     * @internal
     */
    protected function metadata(
        Cardinality $cardinality,
        ?Closure $relatedId,
        bool $nullable,
        array $updates,
        ?Sort $defaultSort,
        ?PaginationPolicy $paginationPolicy,
    ): RelationshipMetadata {
        $excluded = $this->excluded ?? RouteExclusion::none(RouteOwner::Relationship);

        return new RelationshipMetadata(
            $this->name,
            $cardinality,
            $this->relatedType,
            $relatedId,
            $nullable,
            $this->linkageAlwaysPresent || $excluded->excludesAll(),
            $this->includable,
            $this->replaceable ? [RelationshipUpdate::Replace, ...$updates] : $updates,
            $excluded,
            new WriteRule($this->creatable, $this->updatable, $this->required),
            $defaultSort,
            $paginationPolicy,
            $this->description,
            $this->deprecated,
            $this->queryParameters,
            $this->securitySchemes,
        );
    }

    /**
     * the name is escaped: `build()` has not checked it yet
     *
     * @internal
     */
    protected function declaration(): string
    {
        return sprintf('relationship "%s"', Printable::of($this->name));
    }

    /**
     * @throws InvalidArgumentException when the relationship already declares its routes
     */
    private function exclude(RouteExclusion $excluded): static
    {
        if ($this->excluded !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'its routes');
        }

        $this->excluded = $excluded;

        return $this;
    }
}
