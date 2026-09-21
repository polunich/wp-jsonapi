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
use OutOfBoundsException;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the resources of an API, in declaration order, and the rules that span them.
 * the constructor checks what no single resource can know:
 *
 * - every related type and every monitor type is declared;
 * - the related type of a to-many relationship binds a collection reader,
 *   which reads its related and relationship routes;
 *   a reader beside an excluded collection route serves such a relationship, or nothing;
 * - a type binds a related loader when one of its to-one relationships has no related id accessor,
 *   or one of its to-many relationships is includable or always linked.
 *   a related load names the type of the parents, so the loader is bound on the declaring type;
 * - every path of a declared default sort resolves against the type whose records it orders,
 *   and none is nullable.
 *
 * which path a sort, an include or a filter may name,
 * and how a paginated reading is sized and ordered, is decided here and nowhere else,
 * so every reader of the declarations gets one result
 *
 * @internal
 */
final readonly class ResourceRegistry
{
    /**
     * the most relationships a sort path runs through,
     * one as in the spec's own example `author.name`.
     * some bound is needed: a to-one relationship may point at its own type,
     * which would make the paths of an unbounded rule infinite,
     * and the include depth bounds only include and filter paths
     */
    private const MAX_SORT_DEPTH = 1;

    /**
     * @var array<array-key, ResourceMetadata> type => resource, in declaration order
     */
    private array $resources;

    /**
     * @var array<array-key, array<array-key, PaginationPolicy>> type => relationship => policy
     */
    private array $relationshipPolicies;

    /**
     * @param list<ResourceMetadata> $resources in declaration order
     *
     * @throws InvalidArgumentException when a type is declared twice;
     *                                  a related or monitor type is not declared;
     *                                  a capability a relationship requires is not bound;
     *                                  a collection reader beside an excluded collection route
     *                                  serves no to-many relationship;
     *                                  or a default sort names a path `sortPath()` refuses,
     *                                  or one that may be null
     */
    public function __construct(array $resources)
    {
        $byType = [];

        foreach ($resources as $resource) {
            if (array_key_exists($resource->type, $byType)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" is declared twice, and each type is declared once',
                    $resource->type,
                ));
            }

            $byType[$resource->type] = $resource;
        }

        $this->resources = $byType;

        // every reference is checked before a default sort path follows one,
        // so a path to an undeclared type fails as an undeclared related type, whatever the order
        foreach ($byType as $resource) {
            $this->checkReferences($resource);
        }

        $toManyTargets = [];

        foreach ($byType as $resource) {
            foreach ($resource->relationships as $relationship) {
                if ($relationship->cardinality === Cardinality::ToMany) {
                    $toManyTargets[$relationship->relatedType] = true;
                }
            }
        }

        $policies = [];

        foreach ($byType as $resource) {
            $this->checkCapabilities($resource);
            self::checkCollectionReader($resource, $toManyTargets);

            if ($resource->defaultSort !== null) {
                $this->checkDefaultSort($resource->defaultSort, $resource->type, sprintf(
                    'the default sort of type "%s"',
                    $resource->type,
                ));
            }

            foreach ($resource->relationships as $relationship) {
                if ($relationship->cardinality !== Cardinality::ToMany) {
                    continue;
                }

                if ($relationship->defaultSort !== null) {
                    $this->checkDefaultSort($relationship->defaultSort, $relationship->relatedType, sprintf(
                        'the default sort of relationship "%s" of type "%s"',
                        $relationship->name,
                        $resource->type,
                    ));
                }

                $policies[$resource->type][$relationship->name] = $relationship->paginationPolicy
                    ?? $this->get($relationship->relatedType)->paginationPolicy;
            }
        }

        $this->relationshipPolicies = $policies;
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->resources);
    }

    /**
     * @throws OutOfBoundsException when the type is not declared,
     *                              a caller bug: no route names an undeclared type
     */
    public function get(string $type): ResourceMetadata
    {
        if (!array_key_exists($type, $this->resources)) {
            throw new OutOfBoundsException(sprintf(
                'type "%s" is not declared',
                Printable::of($type),
            ));
        }

        return $this->resources[$type];
    }

    /**
     * @throws OutOfBoundsException when the type or the relationship is not declared,
     *                              a caller bug: no route names an undeclared one
     */
    public function relationshipOf(string $type, string $name): RelationshipMetadata
    {
        $resource = $this->get($type);

        if (!array_key_exists($name, $resource->relationships)) {
            throw new OutOfBoundsException(sprintf(
                'type "%s" declares no relationship "%s"',
                $resource->type,
                Printable::of($name),
            ));
        }

        return $resource->relationships[$name];
    }

    /**
     * @param ?string $relationship given exactly for a kind that addresses a relationship
     *
     * @return list<QueryParameterMetadata> in declaration order
     *
     * @throws InvalidArgumentException when the relationship is missing for a relationship kind,
     *                                  or given for another
     * @throws OutOfBoundsException     when the type or the relationship is not declared
     */
    public function queryParametersOf(OperationKind $kind, string $type, ?string $relationship): array
    {
        if ($kind->targetsRelationship() !== ($relationship !== null)) {
            throw new InvalidArgumentException(sprintf(
                'the route of %s %s, and got %s',
                $kind->value,
                $kind->targetsRelationship() ? 'addresses a relationship' : 'addresses no relationship',
                $relationship === null ? 'none' : sprintf('"%s"', Printable::of($relationship)),
            ));
        }

        return $relationship === null
            ? $this->get($type)->queryParametersOf($kind)
            : $this->relationshipOf($type, $relationship)->queryParametersOf($kind);
    }

    /**
     * in declaration order
     *
     * @return list<ResourceMetadata>
     */
    public function all(): array
    {
        return array_values($this->resources);
    }

    /**
     * null for a reading of a to-one relationship, which has no page
     *
     * @throws InvalidArgumentException when the operation is of another kind
     * @throws OutOfBoundsException     when its type or its relationship is not declared
     */
    public function paginationPolicy(Operation $operation): ?PaginationPolicy
    {
        return match ($operation->kind()) {
            OperationKind::FetchCollection => $this->get($operation->type())->paginationPolicy,
            OperationKind::FetchRelated, OperationKind::FetchRelationship => $this->routePolicy($operation),
            default => throw new InvalidArgumentException(sprintf(
                'a %s operation reads no page, and a pagination policy belongs to fetch_collection, fetch_related'
                . ' and fetch_relationship',
                $operation->kind()->value,
            )),
        };
    }

    /**
     * the order the reading of an operation runs in, by `readingSortOf()`;
     * `FetchRelationship` reads no sort of the client
     *
     * @throws InvalidArgumentException when the operation reads a to-one relationship,
     *                                  which no route sorts
     * @throws OutOfBoundsException     when its type or its relationship is not declared
     */
    public function readingSort(FetchCollection|FetchRelated|FetchRelationship $operation): Sort
    {
        if ($operation instanceof FetchCollection) {
            return self::readingSortOf($operation->sort, $this->get($operation->type)->defaultSort);
        }

        $default = $this->relationshipReadingSort($operation->parent->type, $operation->relationship);

        return $operation instanceof FetchRelated ? self::readingSortOf($operation->sort, $default) : $default;
    }

    /**
     * the order a reading runs in: the sort of the request, else the default sort, else `id`,
     * made total with `Sort::withId()`.
     * it is the one rule for that order,
     * so a cursor is checked against the very order the reading pages through
     *
     * @param Sort  $sort    the sort of the request, empty without `sort`
     * @param ?Sort $default the declared default sort, null where none is declared
     */
    public static function readingSortOf(Sort $sort, ?Sort $default): Sort
    {
        return ($sort->isEmpty() ? $default ?? Sort::byId() : $sort)->withId();
    }

    /**
     * the page sizes of the page an endpoint responds with: its own, else those of its type
     *
     * @throws InvalidArgumentException when the endpoint responds with no page
     * @throws OutOfBoundsException     when the type of the page is not declared
     */
    public function endpointPaginationPolicy(EndpointMetadata $endpoint): PaginationPolicy
    {
        $type = self::pageTypeOf($endpoint);

        return $endpoint->paginationPolicy ?? $this->get($type)->paginationPolicy;
    }

    /**
     * the default sort of the page an endpoint responds with: its own, else the one of its type;
     * null where neither declares one
     *
     * @throws InvalidArgumentException when the endpoint responds with no page
     * @throws OutOfBoundsException     when the type of the page is not declared
     */
    public function endpointDefaultSort(EndpointMetadata $endpoint): ?Sort
    {
        $type = self::pageTypeOf($endpoint);

        return $endpoint->defaultSort ?? $this->get($type)->defaultSort;
    }

    /**
     * @throws InvalidArgumentException when the endpoint responds with no page
     */
    private static function pageTypeOf(EndpointMetadata $endpoint): string
    {
        return $endpoint->pageType() ?? throw new InvalidArgumentException(sprintf(
            'the endpoint "%s" responds with no page, and a pagination policy and a default sort serve a page',
            $endpoint->name,
        ));
    }

    /**
     * the policy the relationship declares, or else the one of its related type,
     * resolved once when the registry is built
     *
     * @param string $type the type that declares the relationship, not the related type
     *
     * @throws InvalidArgumentException when the relationship is to-one, which no route paginates
     * @throws OutOfBoundsException     when the type or the relationship is not declared
     */
    public function relationshipPaginationPolicy(string $type, string $relationship): PaginationPolicy
    {
        $declared = $this->relationshipOf($type, $relationship);

        if ($declared->cardinality !== Cardinality::ToMany) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" of type "%s" is to-one, and no route paginates a to-one relationship',
                $declared->name,
                $type,
            ));
        }

        return $this->relationshipPolicies[$type][$declared->name];
    }

    /**
     * a document renders the linkage in this sort, at the default size of its policy,
     * so the linkage is the first page of the relationship route
     *
     * @param string $type the type that declares the relationship, not the related type
     *
     * @throws InvalidArgumentException when the relationship is to-one, which no route sorts
     * @throws OutOfBoundsException     when the type or the relationship is not declared
     */
    public function relationshipReadingSort(string $type, string $relationship): Sort
    {
        $declared = $this->relationshipOf($type, $relationship);

        if ($declared->cardinality !== Cardinality::ToMany) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" of type "%s" is to-one, and no route sorts a to-one relationship',
                $declared->name,
                $type,
            ));
        }

        return ($declared->defaultSort ?? Sort::byId())->withId();
    }

    /**
     * a sort path is at most `MAX_SORT_DEPTH` to-one relationships followed by a sortable attribute
     * or by `id`: `name`, `id`, `owner.name`, `owner.id`.
     * whether a nullable path may be sorted by is the caller's decision
     *
     * @param string $type the type whose records the sort orders
     *
     * @throws InvalidArgumentException when the path is no path a sort may name;
     *                                  the message names the path and the reason,
     *                                  ready for the caller to prefix
     * @throws OutOfBoundsException     when the type or a type on the path is not declared
     */
    public function sortPath(string $type, FieldPath $path): SortPath
    {
        $resource = $this->get($type);

        if ($path->depth() > self::MAX_SORT_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'sort path "%s" runs through %d relationships, and a sort path runs through %d at most',
                $path->toString(),
                $path->depth(),
                self::MAX_SORT_DEPTH,
            ));
        }

        $relationships = [];

        foreach ($path->relationships() as $name) {
            $relationship = $resource->relationships[$name] ?? null;

            if ($relationship === null || $relationship->cardinality !== Cardinality::ToOne) {
                throw new InvalidArgumentException(sprintf(
                    'sort path "%s" runs through "%s", which is no to-one relationship of type "%s", and a sort path'
                    . ' runs through to-one relationships alone',
                    $path->toString(),
                    $name,
                    $resource->type,
                ));
            }

            $relationships[] = $relationship;
            $resource = $this->get($relationship->relatedType);
        }

        if ($path->endsAtId()) {
            return new SortPath($path, $relationships, $resource, null);
        }

        $attribute = $resource->attributes[$path->name()] ?? null;

        if ($attribute === null || !$attribute->sortable) {
            throw new InvalidArgumentException(sprintf(
                'sort path "%s" ends at "%s", which is no sortable attribute of type "%s", and a sort path ends at a'
                . ' sortable attribute or at the id',
                $path->toString(),
                $path->name(),
                $resource->type,
            ));
        }

        return new SortPath($path, $relationships, $resource, $attribute);
    }

    /**
     * exactly the paths `sortPath()` resolves, in declaration order: `id`, the sortable attributes,
     * then the same through each to-one relationship while `MAX_SORT_DEPTH` allows
     *
     * @return list<SortPath>
     *
     * @throws OutOfBoundsException when the type or a type a relationship points at is not declared
     */
    public function sortPaths(string $type): array
    {
        return $this->sortPathsThrough($this->get($type), []);
    }

    /**
     * @param list<RelationshipMetadata> $chain the relationships that lead to `$resource`,
     *                                          empty at the type the sort orders
     *
     * @return list<SortPath>
     *
     * @throws OutOfBoundsException when a type a relationship points at is not declared
     */
    private function sortPathsThrough(ResourceMetadata $resource, array $chain): array
    {
        $prefix = array_map(
            static function (RelationshipMetadata $relationship): string {
                return $relationship->name;
            },
            $chain,
        );
        $paths = [new SortPath(new FieldPath([...$prefix, ...FieldPath::id()->segments()]), $chain, $resource, null)];

        foreach ($resource->attributes as $attribute) {
            if ($attribute->sortable) {
                $paths[] = new SortPath(new FieldPath([...$prefix, $attribute->name]), $chain, $resource, $attribute);
            }
        }

        if (count($chain) >= self::MAX_SORT_DEPTH) {
            return $paths;
        }

        foreach ($resource->relationships as $relationship) {
            if ($relationship->cardinality === Cardinality::ToOne) {
                $paths = [
                    ...$paths,
                    ...$this->sortPathsThrough($this->get($relationship->relatedType), [...$chain, $relationship]),
                ];
            }
        }

        return $paths;
    }

    /**
     * whether a route admits the include path: a chain of includable relationships
     * no longer than the include depth of the type.
     * on a relationship route the path starts with the relationship of the route,
     * whose identifiers are the primary data every included resource chains from,
     * and its depth still counts from the declaring type
     *
     * @param string  $type         where paths start, the declaring type on a relationship route
     * @param ?string $relationship the relationship of a relationship route
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function isIncludable(string $type, ?string $relationship, IncludePath $path): bool
    {
        $resource = $this->get($type);
        $segments = $path->relationships();

        if (($relationship !== null && $segments[0] !== $relationship) || $path->depth() > $resource->maxIncludeDepth) {
            return false;
        }

        foreach ($segments as $segment) {
            $declared = $resource->relationships[$segment] ?? null;

            if ($declared === null || !$declared->includable) {
                return false;
            }

            $resource = $this->get($declared->relatedType);
        }

        return true;
    }

    /**
     * exactly the paths `isIncludable()` admits, each followed by the paths through it,
     * in declaration order
     *
     * @param string  $type         where paths start, the declaring type on a relationship route
     * @param ?string $relationship the relationship of a relationship route
     *
     * @return list<IncludePath>
     *
     * @throws OutOfBoundsException when the type or the relationship is not declared
     */
    public function includePaths(string $type, ?string $relationship): array
    {
        $resource = $this->get($type);

        if ($relationship === null) {
            return $this->includePathsThrough($resource, [], $resource->maxIncludeDepth);
        }

        $declared = $this->relationshipOf($type, $relationship);

        if (!$declared->includable) {
            return [];
        }

        return [
            new IncludePath([$declared->name]),
            ...$this->includePathsThrough(
                $this->get($declared->relatedType),
                [$declared->name],
                $resource->maxIncludeDepth,
            ),
        ];
    }

    /**
     * the types the paths of `includePaths()` end at, each once, those of the shorter paths first:
     * the types an included resource of the route may be of
     *
     * @param string  $type         where paths start, the declaring type on a relationship route
     * @param ?string $relationship the relationship of a relationship route
     *
     * @return list<string>
     *
     * @throws OutOfBoundsException when the type or the relationship is not declared
     */
    public function includedTypes(string $type, ?string $relationship): array
    {
        $paths = $this->includePaths($type, $relationship);
        // a stable sort, so paths of one depth keep their declaration order
        usort($paths, static function (IncludePath $one, IncludePath $other): int {
            return $one->depth() <=> $other->depth();
        });
        $types = [];

        foreach ($paths as $path) {
            $resource = $this->get($type);

            foreach ($path->relationships() as $segment) {
                $resource = $this->get($this->relationshipOf($resource->type, $segment)->relatedType);
            }

            if (!in_array($resource->type, $types, true)) {
                $types[] = $resource->type;
            }
        }

        return $types;
    }

    /**
     * the member of the filter object of the type that the name reads:
     * an attribute that declares operators, the id where the type declares operators for it,
     * or a relationship, whose value is a filter object of its related type.
     * null for any other name, a dotted path included:
     * a condition through a relationship is written nested
     *
     * @param string $type the type whose filter object holds the member
     * @param string $name as the client wrote it
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function filterMember(string $type, string $name): ?FilterMember
    {
        $resource = $this->get($type);

        if ($name === FieldPath::id()->name()) {
            return $resource->id->operators === [] ? null : new FilterMember($name, $resource, null, null);
        }

        $attribute = $resource->attributes[$name] ?? null;

        if ($attribute !== null) {
            return $attribute->operators === [] ? null : new FilterMember($name, $resource, $attribute, null);
        }

        $relationship = $resource->relationships[$name] ?? null;

        return $relationship === null ? null : new FilterMember($name, $resource, null, $relationship);
    }

    /**
     * exactly the members `filterMember()` resolves, in declaration order
     *
     * @return list<FilterMember>
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function filterMembers(string $type): array
    {
        $resource = $this->get($type);
        $members = [];

        if ($resource->id->operators !== []) {
            $members[] = new FilterMember(FieldPath::id()->name(), $resource, null, null);
        }

        foreach ($resource->attributes as $attribute) {
            if ($attribute->operators !== []) {
                $members[] = new FilterMember($attribute->name, $resource, $attribute, null);
            }
        }

        foreach ($resource->relationships as $relationship) {
            $members[] = new FilterMember($relationship->name, $resource, null, $relationship);
        }

        return $members;
    }

    /**
     * @param list<string> $prefix the relationships that lead to `$resource`
     * @param int          $depth  the include depth, counted from the start of the path
     *
     * @return list<IncludePath>
     *
     * @throws OutOfBoundsException when a type a relationship points at is not declared
     */
    private function includePathsThrough(ResourceMetadata $resource, array $prefix, int $depth): array
    {
        if (count($prefix) >= $depth) {
            return [];
        }

        $paths = [];

        foreach ($resource->relationships as $relationship) {
            if (!$relationship->includable) {
                continue;
            }

            $path = [...$prefix, $relationship->name];
            $paths = [
                ...$paths,
                new IncludePath($path),
                ...$this->includePathsThrough($this->get($relationship->relatedType), $path, $depth),
            ];
        }

        return $paths;
    }

    /**
     * @throws OutOfBoundsException when the type or the relationship is not declared
     */
    private function routePolicy(Operation $operation): ?PaginationPolicy
    {
        $type = $operation->type();
        $name = (string) $operation->relationship();

        if ($this->relationshipOf($type, $name)->cardinality !== Cardinality::ToMany) {
            return null;
        }

        return $this->relationshipPaginationPolicy($type, $name);
    }

    /**
     * @throws InvalidArgumentException when a related or a monitor type is not declared
     */
    private function checkReferences(ResourceMetadata $resource): void
    {
        foreach ($resource->relationships as $relationship) {
            if (!$this->has($relationship->relatedType)) {
                throw new InvalidArgumentException(sprintf(
                    'relationship "%s" of type "%s" points at type "%s", which is not declared',
                    $relationship->name,
                    $resource->type,
                    $relationship->relatedType,
                ));
            }
        }

        foreach ($resource->monitorTypes as $kind => $monitorType) {
            if (!$this->has($monitorType)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" responds to %s with 202 and the monitor type "%s", which is not declared',
                    $resource->type,
                    $kind,
                    $monitorType,
                ));
            }
        }
    }

    /**
     * @param array<array-key, true> $toManyTargets the related types of every to-many relationship
     *
     * @throws InvalidArgumentException when a reader beside an excluded collection route
     *                                  serves no to-many relationship
     */
    private static function checkCollectionReader(ResourceMetadata $resource, array $toManyTargets): void
    {
        if (
            !array_key_exists(Capability::CollectionReader->value, $resource->capabilities)
            || $resource->enables(OperationKind::FetchCollection)
            || array_key_exists($resource->type, $toManyTargets)
        ) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'type "%s" binds a collection reader and excludes its collection route, and no to-many relationship'
            . ' points at it, so nothing reads through the reader',
            $resource->type,
        ));
    }

    /**
     * @throws InvalidArgumentException when the related type of a to-many relationship
     *                                  binds no collection reader,
     *                                  or the resource binds no related loader it needs
     */
    private function checkCapabilities(ResourceMetadata $resource): void
    {
        foreach ($resource->relationships as $relationship) {
            if ($relationship->cardinality === Cardinality::ToMany) {
                $related = $this->get($relationship->relatedType);

                if (!array_key_exists(Capability::CollectionReader->value, $related->capabilities)) {
                    throw new InvalidArgumentException(sprintf(
                        'to-many relationship "%s" of type "%s" is read through the collection reader of type "%s",'
                        . ' which binds none',
                        $relationship->name,
                        $resource->type,
                        $related->type,
                    ));
                }
            }

            if (array_key_exists(Capability::RelatedLoader->value, $resource->capabilities)) {
                continue;
            }

            if ($relationship->cardinality === Cardinality::ToOne && !$relationship->readsRelatedId()) {
                throw new InvalidArgumentException(sprintf(
                    'to-one relationship "%s" of type "%s" has no related id accessor, so its linkage is loaded'
                    . ' through a related loader, and the type binds none',
                    $relationship->name,
                    $resource->type,
                ));
            }

            if (
                $relationship->cardinality === Cardinality::ToMany
                && ($relationship->includable || $relationship->linkageAlwaysPresent)
            ) {
                throw new InvalidArgumentException(sprintf(
                    'to-many relationship "%s" of type "%s" is includable or always linked, so its related resources'
                    . ' are loaded through a related loader, and the type binds none',
                    $relationship->name,
                    $resource->type,
                ));
            }
        }
    }

    /**
     * a default sort may key a cursor, which holds no null,
     * so a nullable path would fail every cursor request of the reading
     *
     * @param string $type  the type whose records the sort orders
     * @param string $owner what declares the sort, for the message
     *
     * @throws InvalidArgumentException when a path does not resolve or may be null
     * @throws OutOfBoundsException     when the type or a type on a path is not declared
     */
    public function checkDefaultSort(Sort $sort, string $type, string $owner): void
    {
        foreach ($sort->fields() as $field) {
            try {
                $path = $this->sortPath($type, $field->field);
            } catch (InvalidArgumentException $refusal) {
                throw new InvalidArgumentException(sprintf('%s: %s', $owner, $refusal->getMessage()), 0, $refusal);
            }

            // the type that declares the current relationship of the path, for the message
            $declaring = $type;

            foreach ($path->relationships as $relationship) {
                if ($relationship->nullable) {
                    throw new InvalidArgumentException(sprintf(
                        '%s orders by "%s" through relationship "%s" of type "%s", which may be empty, and a default'
                        . ' sort path is never null, since a cursor holds no null',
                        $owner,
                        $path->path->toString(),
                        $relationship->name,
                        $declaring,
                    ));
                }

                $declaring = $relationship->relatedType;
            }

            if ($path->attribute !== null && $path->attribute->nullable) {
                throw new InvalidArgumentException(sprintf(
                    '%s orders by "%s", and attribute "%s" of type "%s" may be null, and a default sort path is never'
                    . ' null, since a cursor holds no null',
                    $owner,
                    $path->path->toString(),
                    $path->attribute->name,
                    $path->resource->type,
                ));
            }
        }
    }
}
