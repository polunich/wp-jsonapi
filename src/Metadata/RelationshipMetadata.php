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

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * a to-one relationship with a related id accessor reads its linkage from the record,
 * so the linkage is always present.
 * any other relationship renders its linkage without include
 * only when `$linkageAlwaysPresent` is set, and then loads it through the related loader.
 * a relationship that excludes both of its read routes has no link to lead to its linkage,
 * and a relationship object carries at least one of links, data and meta (JSON:API, Relationships),
 * so its linkage is always present as well.
 *
 * an empty to-one relationship is null and an empty to-many one is `[]`,
 * so only a to-one may be nullable.
 * a to-one that is not nullable must be required on create, or a create could leave it empty.
 *
 * `$defaultSort` and `$paginationPolicy` hold only what a to-many relationship declares,
 * null where it declares nothing: a relationship is built before any registry exists,
 * so every reading asks `ResourceRegistry` for the resolved sort and policy
 *
 * @internal
 */
final readonly class RelationshipMetadata
{
    /**
     * @param ?Closure                 $relatedId   reads the related id from a record;
     *                                              null when the record lacks the linkage
     * @param list<RelationshipUpdate> $updates
     * @param ?string                  $description shown in the contract
     * @param bool                     $deprecated  marked deprecated in the contract
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     *
     * @throws InvalidArgumentException when the name is no field name,
     *                                  or the related type no type name;
     *                                  a to-many has a related id accessor or is nullable;
     *                                  a related id accessor comes without `linkageAlwaysPresent`;
     *                                  the exclusion is not one of a relationship,
     *                                  or excludes both reads without `linkageAlwaysPresent`;
     *                                  an update repeats, or is `Add` or `Remove` on a to-one;
     *                                  a to-many declares the empty sort;
     *                                  a to-one declares a default sort or a policy;
     *                                  a to-one that is not nullable is not required on create;
     *                                  a query parameter is declared twice for one kind,
     *                                  for a kind of no relationship route, for one not enabled,
     *                                  or as required on a read;
     *                                  security schemes are declared
     *                                  for a kind of no relationship route or one not enabled,
     *                                  or name a scheme twice;
     *                                  or the description is empty
     */
    public function __construct(
        public string $name,
        public Cardinality $cardinality,
        public string $relatedType,
        private ?Closure $relatedId,
        public bool $nullable,
        public bool $linkageAlwaysPresent,
        public bool $includable,
        public array $updates,
        public RouteExclusion $excluded,
        public WriteRule $writeRule,
        public ?Sort $defaultSort,
        public ?PaginationPolicy $paginationPolicy,
        public ?string $description,
        public bool $deprecated,
        public array $queryParameters = [],
        public array $securitySchemes = [],
    ) {
        ResourceFieldName::check($name, 'relationship');
        DeclaredText::check($description, sprintf('relationship "%s"', $name), 'description');

        if (!MemberName::isValid($relatedType)) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" points at type "%s", which is no member name [spec]',
                $name,
                Printable::of($relatedType),
            ));
        }

        if ($cardinality === Cardinality::ToMany) {
            $this->checkToMany();
        } else {
            $this->checkToOne();
        }

        if ($relatedId !== null && !$linkageAlwaysPresent) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" reads its linkage from the record, so its linkage is always present',
                $name,
            ));
        }

        if ($excluded->owner !== RouteOwner::Relationship) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" takes the exclusion of a type, and a relationship excludes its own reads only',
                $name,
            ));
        }

        if ($excluded->excludesAll() && !$linkageAlwaysPresent) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" excludes its related and its relationship route, so no link leads to its linkage'
                . ' and every document carries it: its linkage is always present',
                $name,
            ));
        }

        $seen = [];

        foreach ($updates as $update) {
            if (array_key_exists($update->value, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'relationship "%s" declares the update %s twice',
                    $name,
                    $update->value,
                ));
            }

            $seen[$update->value] = true;
        }

        $kinds = array_values(array_filter(OperationKind::cases(), static function (OperationKind $kind): bool {
            return $kind->targetsRelationship();
        }));

        RouteQueryParameters::check(
            $queryParameters,
            $kinds,
            $this->enables(...),
            sprintf('relationship "%s"', $name),
            'the routes of the resources take the query parameters their type declares with its own queryParameter()',
        );
        RouteSecuritySchemes::check(
            $securitySchemes,
            $kinds,
            $this->enables(...),
            sprintf('relationship "%s"', $name),
            'the routes of the resources take the security schemes their type declares with its own'
            . ' securitySchemes()',
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

    public function accepts(RelationshipUpdate $update): bool
    {
        return in_array($update, $this->updates, true);
    }

    /**
     * whether the relationship offers the route of a kind that addresses a relationship:
     * a read unless it is excluded, a write where the update is declared
     *
     * @throws InvalidArgumentException when the kind addresses no relationship
     */
    public function enables(OperationKind $kind): bool
    {
        if (!$kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'a %s operation addresses no relationship, and a relationship covers the kinds that do',
                $kind->value,
            ));
        }

        $update = RelationshipUpdate::of($kind);

        return $update === null ? !$this->excluded->excludes($kind) : $this->accepts($update);
    }

    public function readsRelatedId(): bool
    {
        return $this->relatedId !== null;
    }

    /**
     * @throws LogicException           when there is no related id accessor;
     *                                  ask `readsRelatedId()` first
     * @throws UnexpectedValueException when the accessor returns neither a string nor null,
     *                                  or the empty string,
     *                                  or null for a relationship that is not nullable
     */
    public function relatedIdOf(object $record): ?string
    {
        if ($this->relatedId === null) {
            throw new LogicException(sprintf(
                'relationship "%s" has no related id accessor, so its linkage is not read from the record',
                $this->name,
            ));
        }

        $id = ($this->relatedId)($record);

        if ($id === null) {
            if (!$this->nullable) {
                throw new UnexpectedValueException(sprintf(
                    'the related id accessor of relationship "%s" returned null for a record of class %s, and the'
                    . ' relationship is not nullable',
                    $this->name,
                    get_debug_type($record),
                ));
            }

            return null;
        }

        if (!is_string($id)) {
            throw new UnexpectedValueException(sprintf(
                'the related id accessor of relationship "%s" returned %s for a record of class %s, and an id is'
                . ' a string [spec]',
                $this->name,
                get_debug_type($id),
                get_debug_type($record),
            ));
        }

        if ($id === '') {
            throw new UnexpectedValueException(sprintf(
                'the related id accessor of relationship "%s" returned the empty string for a record of class %s,'
                . ' which identifies no resource',
                $this->name,
                get_debug_type($record),
            ));
        }

        return $id;
    }

    /**
     * @throws InvalidArgumentException when it has a related id accessor, is declared nullable,
     *                                  or declares the empty sort
     */
    private function checkToMany(): void
    {
        if ($this->relatedId !== null) {
            throw new InvalidArgumentException(sprintf(
                'to-many relationship "%s" has a related id accessor, which only a to-one relationship has',
                $this->name,
            ));
        }

        if ($this->nullable) {
            throw new InvalidArgumentException(sprintf(
                'to-many relationship "%s" is declared nullable, and an empty to-many relationship is [] and never'
                . ' null [spec]',
                $this->name,
            ));
        }

        if ($this->defaultSort?->isEmpty() === true) {
            throw new InvalidArgumentException(sprintf(
                'to-many relationship "%s" declares a default sort without a sort field, and a default sort that'
                . ' is not declared is null',
                $this->name,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when it declares `Add` or `Remove`,
     *                                  a default sort or a policy,
     *                                  or is not nullable and not required on create
     */
    private function checkToOne(): void
    {
        foreach ($this->updates as $update) {
            if ($update !== RelationshipUpdate::Replace) {
                throw new InvalidArgumentException(sprintf(
                    'to-one relationship "%s" declares the update %s, and a to-one relationship is updated by'
                    . ' replace only [spec]',
                    $this->name,
                    $update->value,
                ));
            }
        }

        if ($this->defaultSort !== null) {
            throw new InvalidArgumentException(sprintf(
                'to-one relationship "%s" declares a default sort, and no route sorts a to-one relationship',
                $this->name,
            ));
        }

        if ($this->paginationPolicy !== null) {
            throw new InvalidArgumentException(sprintf(
                'to-one relationship "%s" declares a pagination policy, and no route paginates a to-one'
                . ' relationship',
                $this->name,
            ));
        }

        if (!$this->nullable && !$this->writeRule->required) {
            throw new InvalidArgumentException(sprintf(
                'to-one relationship "%s" is not nullable and not required on create, so a create could leave it'
                . ' empty',
                $this->name,
            ));
        }
    }
}
