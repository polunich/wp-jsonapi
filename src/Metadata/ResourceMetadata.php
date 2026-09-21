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
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\SegmentPattern;
use Polunich\WpJsonApi\Text\Printable;

/**
 * attributes and relationships are keyed by name, in declaration order;
 * PHP stores a name of digits under an int key, hence the `(string)` casts.
 *
 * `$permissions` holds one permission per enabled kind,
 * and `$monitorTypes` the type of the status monitor per write kind that may get 202.
 * no key of a map keyed by a case value is checked here: PHPStan at level 10 holds it to its type,
 * reporting a wrong key where a map is passed and a check of one here as always false.
 *
 * whether the resource loader is bound where something reads through it,
 * and whether anything reads the default sort, depend on other types and on the endpoints,
 * so they are checked across the whole API.
 *
 * `$maxIncludeDepth` bounds the relationships an include path or a filter path may run through.
 *
 * a client id policy other than `Forbidden` needs globally unique ids,
 * since the spec advises a UUID unless the id strategy surely yields globally unique ones:
 * ids declared so, or read by a codec whose schema has the uuid format.
 * the schema is read, not the codec class, because a codec enforces every keyword its schema writes
 *
 * @internal
 */
final readonly class ResourceMetadata
{
    /**
     * @var non-empty-list<string>
     */
    public array $path;

    /**
     * @param array<array-key, AttributeMetadata>                     $attributes
     * @param array<array-key, RelationshipMetadata>                  $relationships
     * @param array<value-of<Capability>, ImplementationReference>    $capabilities
     * @param array<value-of<OperationKind>, ImplementationReference> $permissions
     * @param array<value-of<OperationKind>, string>                  $monitorTypes
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     * @param ?list<string> $path the literal segments of the path of the collection route,
     *                            below which every route of the type lies; `[$type]` when null
     *
     * @throws InvalidArgumentException when the type is no member name;
     *                                  the path is empty or holds an empty segment;
     *                                  the id codec breaks a rule of `SegmentCodec`;
     *                                  the id parameter breaks the rule of a group name;
     *                                  a field is not held under its name,
     *                                  or a name is an attribute and a relationship;
     *                                  the exclusion is not one of a type,
     *                                  or excludes a write whose port is bound;
     *                                  an update is declared without its port;
     *                                  a to-many relationship whose linkage a document carries
     *                                  excludes its relationship route;
     *                                  a permission is missing for an enabled kind
     *                                  or present for another;
     *                                  a monitor type is declared for a kind that reads
     *                                  or is not enabled, or is no member name;
     *                                  client ids are accepted for ids not globally unique;
     *                                  a default sort is the empty sort;
     *                                  the include depth is below 1;
     *                                  a query parameter is declared twice for one kind,
     *                                  for a kind of no resource route, for one not enabled,
     *                                  or as required on the fetch of one resource;
     *                                  security schemes are declared
     *                                  for a kind of no resource route or one not enabled,
     *                                  or name a scheme twice;
     *                                  or the description is empty
     */
    public function __construct(
        public string $type,
        public IdMetadata $id,
        public string $idParameterName,
        public array $attributes,
        public array $relationships,
        public array $capabilities,
        public array $permissions,
        public array $monitorTypes,
        public RouteExclusion $excluded,
        public ClientIdPolicy $clientIdPolicy,
        public ?VersionPolicy $versionPolicy,
        public ?Sort $defaultSort,
        public PaginationPolicy $paginationPolicy,
        public int $maxIncludeDepth,
        public ?string $description,
        public array $queryParameters = [],
        public array $securitySchemes = [],
        ?array $path = null,
    ) {
        if (!MemberName::isValid($type)) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" is no member name [spec]',
                Printable::of($type),
            ));
        }

        if ($path === [] || in_array('', $path ?? [], true)) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" lies at a path without a segment or with an empty one, and a route has a segment'
                . ' at least and never an empty one',
                $type,
            ));
        }

        $this->path = $path ?? [$type];

        SegmentPattern::check($id->codec, sprintf('the id of type "%s"', $type));
        PathParameter::checkName($idParameterName, sprintf('the id parameter of type "%s"', $type));
        DeclaredText::check($description, sprintf('type "%s"', $type), 'description');

        $this->checkFields();
        $this->checkExclusion();
        $this->checkCapabilities();
        $this->checkRelationshipRoutes();
        $this->checkPermissions();
        $this->checkMonitorTypes();

        if (
            $clientIdPolicy !== ClientIdPolicy::Forbidden
            && !$id->globallyUnique
            && $id->codec->schema()->format !== Format::Uuid
        ) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" accepts client ids with the policy %s, and its ids are neither declared globally unique'
                . ' nor read by a codec of the uuid format',
                $type,
                $clientIdPolicy->value,
            ));
        }

        if ($defaultSort?->isEmpty() === true) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" declares a default sort without a sort field, and a default sort that is not declared'
                . ' is null',
                $type,
            ));
        }

        if ($maxIncludeDepth < 1) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" declares the include depth %d, and an include depth is at least 1',
                $type,
                $maxIncludeDepth,
            ));
        }

        RouteQueryParameters::check(
            $queryParameters,
            RouteOwner::Resource->kinds(),
            $this->enables(...),
            sprintf('type "%s"', $type),
            'a relationship declares the query parameters of its routes with its own queryParameter()',
        );
        RouteSecuritySchemes::check(
            $securitySchemes,
            RouteOwner::Resource->kinds(),
            $this->enables(...),
            sprintf('type "%s"', $type),
            'a relationship declares the security schemes of its routes with its own securitySchemes()',
        );
    }

    /**
     * @return list<QueryParameterMetadata> in declaration order
     */
    public function queryParametersOf(OperationKind $kind): array
    {
        return $this->queryParameters[$kind->value] ?? [];
    }

    /**
     * @return ?list<SecurityScheme> null where the route of the kind takes the schemes of the API
     */
    public function securitySchemesOf(OperationKind $kind): ?array
    {
        return $this->securitySchemes[$kind->value] ?? null;
    }

    /**
     * the parameter that carries the id in every route of the type that addresses one,
     * read by the id codec
     */
    public function idParameter(): PathParameter
    {
        return new PathParameter($this->idParameterName, $this->id->codec);
    }

    public function requiresPreconditions(): bool
    {
        return $this->versionPolicy?->preconditionsRequired === true;
    }

    /**
     * a kind of the resource routes is enabled where its route is kept
     * and its port, where it has one, is bound;
     * a kind of a relationship route where any relationship offers its route.
     * whether the relationship a request addresses offers it is `RelationshipMetadata::enables()`
     */
    public function enables(OperationKind $kind): bool
    {
        if ($kind->targetsRelationship()) {
            foreach ($this->relationships as $relationship) {
                if ($relationship->enables($kind)) {
                    return true;
                }
            }

            return false;
        }

        if ($this->excluded->excludes($kind)) {
            return false;
        }

        $capability = self::capabilityOfKind($kind);

        return $capability === null || $this->binds($capability);
    }

    private function binds(Capability $capability): bool
    {
        return array_key_exists($capability->value, $this->capabilities);
    }

    /**
     * the port whose binding enables a kind of the resource routes;
     * null for the fetch of one resource, whose route is kept unless it is excluded
     */
    private static function capabilityOfKind(OperationKind $kind): ?Capability
    {
        return match ($kind) {
            OperationKind::FetchCollection => Capability::CollectionReader,
            OperationKind::CreateResource => Capability::ResourceCreator,
            OperationKind::UpdateResource => Capability::ResourceUpdater,
            OperationKind::DeleteResource => Capability::ResourceDeleter,
            default => null,
        };
    }

    private static function capabilityOfUpdate(RelationshipUpdate $update): Capability
    {
        return match ($update) {
            RelationshipUpdate::Replace => Capability::RelationshipReplacer,
            RelationshipUpdate::Add => Capability::RelationshipAdder,
            RelationshipUpdate::Remove => Capability::RelationshipRemover,
        };
    }

    /**
     * @throws InvalidArgumentException when a key is not the name of its field, or a name is both
     */
    private function checkFields(): void
    {
        foreach ($this->attributes as $key => $attribute) {
            if ((string) $key !== $attribute->name) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" holds attribute "%s" under the key "%s", and a field is held under its name',
                    $this->type,
                    $attribute->name,
                    Printable::of((string) $key),
                ));
            }
        }

        foreach ($this->relationships as $key => $relationship) {
            if ((string) $key !== $relationship->name) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" holds relationship "%s" under the key "%s", and a field is held under its name',
                    $this->type,
                    $relationship->name,
                    Printable::of((string) $key),
                ));
            }

            if (array_key_exists($key, $this->attributes)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares "%s" as an attribute and as a relationship, and a resource can not have an'
                    . ' attribute and relationship with the same name [spec]',
                    $this->type,
                    $relationship->name,
                ));
            }
        }
    }

    /**
     * binding a port enables its write,
     * so excluding that write while binding the port would say two things of one route.
     * the collection reader is no such port: it also serves the to-many relationships
     * that point at the type, which `ResourceRegistry` checks
     *
     * @throws InvalidArgumentException when the exclusion is not one of a type,
     *                                  or excludes a write whose port is bound
     */
    private function checkExclusion(): void
    {
        if ($this->excluded->owner !== RouteOwner::Resource) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" takes the exclusion of a relationship, and a type excludes the routes of its resources',
                $this->type,
            ));
        }

        foreach ($this->excluded->kinds as $kind) {
            $capability = self::capabilityOfKind($kind);

            if ($kind->isFetch() || $capability === null || !$this->binds($capability)) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'type "%s" excludes %s and binds its %s, and binding the port of a write enables it: declare one'
                . ' of the two',
                $this->type,
                $kind->value,
                $capability->value,
            ));
        }
    }

    /**
     * a declared update enables its kind, so the port that carries it out must be bound
     *
     * @throws InvalidArgumentException when an update is declared without its port
     */
    private function checkCapabilities(): void
    {
        foreach ($this->relationships as $relationship) {
            foreach ($relationship->updates as $update) {
                $capability = self::capabilityOfUpdate($update);

                if (!$this->binds($capability)) {
                    throw new InvalidArgumentException(sprintf(
                        'relationship "%s" of type "%s" declares the update %s, and the type binds no %s',
                        $relationship->name,
                        $this->type,
                        $update->value,
                        $capability->value,
                    ));
                }
            }
        }
    }

    /**
     * a document carries the linkage of a to-many relationship as a page
     * whose links lead to the relationship route:
     * "Any pagination links in a relationship object MUST paginate the relationship data"
     * (JSON:API, Relationships), and a page without them reads as the whole relationship.
     * so that route stays wherever a document can carry the linkage:
     * an include path names it, it is always present,
     * or it is the response to a write of the relationship,
     * whose 200 is the first page of that route
     *
     * @throws InvalidArgumentException when such a relationship excludes its relationship route
     */
    private function checkRelationshipRoutes(): void
    {
        foreach ($this->relationships as $relationship) {
            if (
                $relationship->cardinality !== Cardinality::ToMany
                || $relationship->enables(OperationKind::FetchRelationship)
            ) {
                continue;
            }

            $carrier = match (true) {
                $relationship->linkageAlwaysPresent => 'every document carries its linkage',
                $relationship->includable => 'an include path may bring its linkage into a document',
                $relationship->updates !== [] => 'each write of it gets its linkage with a 200',
                default => null,
            };

            if ($carrier === null) {
                continue;
            }

            throw new InvalidArgumentException(sprintf(
                'type "%s" declares the to-many relationship "%s", and %s: a document carries a to-many linkage'
                . ' in pages that link to the relationship route, so that route cannot be excluded',
                $this->type,
                $relationship->name,
                $carrier,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a permission is present for a kind that is not enabled,
     *                                  or missing for one that is
     */
    private function checkPermissions(): void
    {
        foreach (OperationKind::cases() as $kind) {
            $declared = array_key_exists($kind->value, $this->permissions);

            if ($declared && !$this->enables($kind)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares a permission for %s, which it does not enable',
                    $this->type,
                    $kind->value,
                ));
            }

            if (!$declared && $this->enables($kind)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" enables %s and declares no permission for it, and a public operation declares a'
                    . ' permission that returns true',
                    $this->type,
                    $kind->value,
                ));
            }
        }
    }

    /**
     * @throws InvalidArgumentException when a monitor type is declared for a kind that reads
     *                                  or that is not enabled, or is no member name
     */
    private function checkMonitorTypes(): void
    {
        foreach ($this->monitorTypes as $key => $monitorType) {
            $kind = OperationKind::from($key);

            if ($kind->isFetch()) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares a monitor type for %s, and only a write gets 202',
                    $this->type,
                    $kind->value,
                ));
            }

            if (!$this->enables($kind)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares a monitor type for %s, which it does not enable',
                    $this->type,
                    $kind->value,
                ));
            }

            if (!MemberName::isValid($monitorType)) {
                throw new InvalidArgumentException(sprintf(
                    'type "%s" declares the monitor type "%s" for %s, which is no member name [spec]',
                    $this->type,
                    Printable::of($monitorType),
                    $kind->value,
                ));
            }
        }
    }
}
