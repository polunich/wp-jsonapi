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

namespace Polunich\WpJsonApi\Tests\Fixtures\Metadata;

use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\PathTemplate;

/**
 * valid pet store declarations for tests:
 * every argument a test leaves out takes the plainest value a valid declaration allows
 *
 * @internal
 */
final class Samples
{
    public static function implementation(): ImplementationReference
    {
        return ImplementationReference::service('implementation');
    }

    /**
     * @param list<string> $operators
     */
    public static function id(array $operators = [], ?SegmentCodec $codec = null): IdMetadata
    {
        return new IdMetadata($codec ?? new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false, $operators);
    }

    /**
     * read-only when readable, write-only otherwise:
     * an attribute neither read nor written is refused
     *
     * @param ?Codec<mixed> $codec
     * @param list<string>  $operators
     */
    public static function attribute(
        string $name,
        ?Codec $codec = null,
        bool $sortable = false,
        array $operators = [],
        bool $readable = true,
        ?WriteRule $writeRule = null,
    ): AttributeMetadata {
        return new AttributeMetadata(
            $name,
            $codec ?? new StringCodec(),
            $readable ? static function (Pet $pet): string {
                return $pet->name;
            } : null,
            $writeRule ?? ($readable ? WriteRule::readOnly() : new WriteRule(true, true, false)),
            $sortable,
            $operators,
            null,
            false,
        );
    }

    /**
     * a never-empty relationship is required on create, as the constructor demands
     *
     * @param list<RelationshipUpdate> $updates
     * @param ?bool                    $linkageAlwaysPresent null derives it: true with an accessor
     *                                                       or with both reads excluded,
     *                                                       as the constructor demands
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     */
    public static function toOne(
        string $name,
        string $relatedType,
        bool $readsRelatedId = true,
        bool $nullable = true,
        array $updates = [],
        ?bool $linkageAlwaysPresent = null,
        ?RouteExclusion $excluded = null,
        ?WriteRule $writeRule = null,
        array $queryParameters = [],
        array $securitySchemes = [],
    ): RelationshipMetadata {
        $excluded ??= RouteExclusion::none(RouteOwner::Relationship);

        return new RelationshipMetadata(
            $name,
            Cardinality::ToOne,
            $relatedType,
            $readsRelatedId ? static function (Pet $pet): ?string {
                return $pet->ownerId;
            } : null,
            $nullable,
            $linkageAlwaysPresent ?? ($readsRelatedId || $excluded->excludesAll()),
            true,
            $updates,
            $excluded,
            $writeRule ?? ($nullable ? WriteRule::readOnly() : new WriteRule(true, true, true)),
            null,
            null,
            null,
            false,
            $queryParameters,
            $securitySchemes,
        );
    }

    /**
     * @param list<RelationshipUpdate>                                     $updates
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param array<value-of<OperationKind>, list<SecurityScheme>>         $securitySchemes
     */
    public static function toMany(
        string $name,
        string $relatedType,
        bool $includable = true,
        bool $linkageAlwaysPresent = false,
        array $updates = [],
        ?Sort $defaultSort = null,
        ?PaginationPolicy $paginationPolicy = null,
        ?RouteExclusion $excluded = null,
        ?WriteRule $writeRule = null,
        array $queryParameters = [],
        array $securitySchemes = [],
    ): RelationshipMetadata {
        return new RelationshipMetadata(
            $name,
            Cardinality::ToMany,
            $relatedType,
            null,
            false,
            $linkageAlwaysPresent,
            $includable,
            $updates,
            $excluded ?? RouteExclusion::none(RouteOwner::Relationship),
            $writeRule ?? WriteRule::readOnly(),
            $defaultSort,
            $paginationPolicy,
            null,
            false,
            $queryParameters,
            $securitySchemes,
        );
    }

    /**
     * @param list<AttributeMetadata>                $attributes
     * @param list<RelationshipMetadata>             $relationships
     * @param list<Capability>                       $capabilities  bound beside `ResourceLoader`,
     *                                                              which is bound by default
     *                                                              if `FetchResource` is kept
     * @param ?list<OperationKind>                   $permissions   the kinds with a permission;
     *                                                              null for every enabled kind,
     *                                                              derived here, not by `enables()`
     * @param array<value-of<OperationKind>, string> $monitorTypes
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     * @param ?list<string>                          $path          the path of the type,
     *                                                              `[$type]` when null
     */
    public static function resource(
        string $type,
        array $attributes = [],
        array $relationships = [],
        array $capabilities = [],
        ?array $permissions = null,
        array $monitorTypes = [],
        ?Sort $defaultSort = null,
        ?PaginationPolicy $paginationPolicy = null,
        int $maxIncludeDepth = 1,
        ClientIdPolicy $clientIdPolicy = ClientIdPolicy::Forbidden,
        ?IdMetadata $id = null,
        string $idParameterName = 'id',
        ?RouteExclusion $excluded = null,
        ?VersionPolicy $versionPolicy = null,
        array $queryParameters = [],
        ?string $description = null,
        array $securitySchemes = [],
        ?array $path = null,
    ): ResourceMetadata {
        $excluded ??= RouteExclusion::none(RouteOwner::Resource);
        $bound = $excluded->excludes(OperationKind::FetchResource)
            ? []
            : [Capability::ResourceLoader->value => self::implementation()];

        foreach ($capabilities as $capability) {
            $bound[$capability->value] = self::implementation();
        }

        $attributesByName = [];

        foreach ($attributes as $attribute) {
            $attributesByName[$attribute->name] = $attribute;
        }

        $relationshipsByName = [];

        foreach ($relationships as $relationship) {
            $relationshipsByName[$relationship->name] = $relationship;
        }

        $declared = [];

        foreach ($permissions ?? self::enabledKinds($relationships, $capabilities, $excluded) as $kind) {
            $declared[$kind->value] = self::implementation();
        }

        return new ResourceMetadata(
            $type,
            $id ?? self::id(),
            $idParameterName,
            $attributesByName,
            $relationshipsByName,
            $bound,
            $declared,
            $monitorTypes,
            $excluded,
            $clientIdPolicy,
            $versionPolicy,
            $defaultSort,
            $paginationPolicy ?? PaginationPolicy::libraryDefault(),
            $maxIncludeDepth,
            $description,
            $queryParameters,
            $securitySchemes,
            $path,
        );
    }

    /**
     * `GET /pets/search` responding with a page of pets
     */
    public static function pageEndpoint(
        string $type = 'pets',
        ?Sort $defaultSort = null,
        ?PaginationPolicy $paginationPolicy = null,
        string $method = 'GET',
    ): EndpointMetadata {
        return self::endpoint(
            'searchPets',
            $method,
            '/pets/search',
            [],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Page, $type, null, null, null, null)],
            defaultSort: $defaultSort,
            paginationPolicy: $paginationPolicy,
        );
    }

    /**
     * @param array<string, string>         $bindings         parameter => bound type
     * @param array<string, SegmentCodec>   $codecs           parameter => codec,
     *                                                        for a parameter bound to no type
     * @param list<QueryParameterMetadata>  $queryParameters
     * @param ?array<int, ResponseMetadata> $responses        the bound record with 200 when null
     * @param list<MediaResponseMetadata>   $mediaResponses
     * @param list<MediaBodyMetadata>       $mediaBodies
     * @param ?list<SecurityScheme>         $securitySchemes
     * @param ?Sort                         $defaultSort      of a page response
     * @param ?PaginationPolicy             $paginationPolicy of a page response
     */
    public static function endpoint(
        string $name = 'adoptPet',
        string $method = 'POST',
        string $path = '/pets/{id}/adopt',
        array $bindings = ['id' => 'pets'],
        array $codecs = [],
        array $queryParameters = [],
        ?BodyMetadata $body = null,
        ?array $responses = null,
        ?string $tag = null,
        ?string $description = null,
        ?string $preconditionParameter = null,
        bool $leavesUnchanged = false,
        array $mediaResponses = [],
        int $jsonApiRank = 0,
        array $mediaBodies = [],
        ?array $securitySchemes = null,
        ?Sort $defaultSort = null,
        ?PaginationPolicy $paginationPolicy = null,
    ): EndpointMetadata {
        foreach (array_keys($bindings) as $parameter) {
            $codecs[$parameter] ??= new DigitsCodec();
        }

        return new EndpointMetadata(
            $name,
            $method,
            PathTemplate::written($path, $codecs, 'the endpoint "' . $name . '"'),
            $bindings,
            $queryParameters,
            $body,
            $responses ?? [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
            ImplementationReference::service('endpoint handler'),
            ImplementationReference::service('endpoint permission'),
            $tag,
            $description,
            $preconditionParameter,
            $leavesUnchanged,
            $mediaResponses,
            $jsonApiRank,
            $mediaBodies,
            $securitySchemes,
            $defaultSort,
            $paginationPolicy,
        );
    }

    /**
     * @param list<string>          $types
     * @param ?list<SecurityScheme> $securitySchemes
     */
    public static function atomicRoute(
        array $types = ['pets'],
        string $name = 'atomicOperations',
        string $path = '/operations',
        ?string $tag = null,
        ?string $description = null,
        ?array $securitySchemes = null,
    ): AtomicRouteMetadata {
        return new AtomicRouteMetadata(
            $name,
            PathTemplate::written($path, [], 'the atomic route "' . $name . '"'),
            $types,
            $tag,
            $description,
            $securitySchemes,
        );
    }

    /**
     * the atomic routes of an API with a transaction boundary or without one:
     * `/operations` taking every type of the registry, or none
     *
     * @return list<AtomicRouteMetadata>
     */
    public static function atomicRoutes(ResourceRegistry $resources, ?ImplementationReference $boundary): array
    {
        if ($boundary === null) {
            return [];
        }

        return [self::atomicRoute(array_map(static function (ResourceMetadata $resource): string {
            return $resource->type;
        }, $resources->all()))];
    }

    /**
     * the language sources of an API that declares none: `Accept-Language` alone
     */
    public static function languageSources(): LanguageSources
    {
        return new LanguageSources([LanguageSourceMetadata::acceptLanguage()]);
    }

    /**
     * @param list<OperatorMetadata> $custom
     *
     * @return array<string, OperatorMetadata>
     */
    public static function operators(array $custom = []): array
    {
        $table = [];

        foreach (Operators::names() as $name) {
            $table[$name] = OperatorMetadata::builtIn($name);
        }

        foreach ($custom as $operator) {
            $table[$operator->name] = $operator;
        }

        return $table;
    }

    /**
     * @param list<RelationshipMetadata> $relationships
     * @param list<Capability>           $capabilities
     *
     * @return list<OperationKind>
     */
    private static function enabledKinds(array $relationships, array $capabilities, RouteExclusion $excluded): array
    {
        $kinds = $excluded->excludes(OperationKind::FetchResource) ? [] : [OperationKind::FetchResource];
        $capabilityOfKind = [
            OperationKind::FetchCollection->value => Capability::CollectionReader,
            OperationKind::CreateResource->value => Capability::ResourceCreator,
            OperationKind::UpdateResource->value => Capability::ResourceUpdater,
            OperationKind::DeleteResource->value => Capability::ResourceDeleter,
        ];

        foreach ($capabilityOfKind as $kind => $capability) {
            if (in_array($capability, $capabilities, true) && !$excluded->excludes(OperationKind::from($kind))) {
                $kinds[] = OperationKind::from($kind);
            }
        }

        foreach ([OperationKind::FetchRelated, OperationKind::FetchRelationship] as $read) {
            foreach ($relationships as $relationship) {
                if (!$relationship->excluded->excludes($read)) {
                    $kinds[] = $read;

                    break;
                }
            }
        }

        $updates = [
            RelationshipUpdate::Replace->value => OperationKind::ReplaceRelationship,
            RelationshipUpdate::Add->value => OperationKind::AddToRelationship,
            RelationshipUpdate::Remove->value => OperationKind::RemoveFromRelationship,
        ];

        foreach ($updates as $update => $kind) {
            foreach ($relationships as $relationship) {
                if (in_array(RelationshipUpdate::from($update), $relationship->updates, true)) {
                    $kinds[] = $kind;

                    break;
                }
            }
        }

        return $kinds;
    }
}
