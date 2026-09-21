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
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Document\RelationshipObject;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Document\ResourceObject;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * one resource of a document, as a resource object or a resource identifier object.
 *
 * the fields are those the fieldset of the type admits, in declaration order;
 * an attribute without an accessor is write-only and never written, whatever the fieldset says.
 *
 * the fieldset applies even when it drops the relationship an included resource hangs from:
 * JSON:API exempts sparse fieldsets from full linkage (Compound Documents),
 * so nothing is added back.
 *
 * a link is written only to a route that `Metadata\RouteTable::fetches()` says responds to a GET:
 * a resource of a type that excludes the fetch of its resources carries no links,
 * and a relationship without either route carries its linkage instead,
 * which `RelationshipMetadata` then requires to be always present.
 *
 * every link carries the language parameter of the request and no other part of its query:
 * the query of one route does not shape another,
 * while the language holds on every route and a client that follows a link keeps it.
 *
 * every id written into a document or a URL is the output of the id codec
 *
 * @internal
 */
final readonly class ResourceSerializer
{
    public function __construct(
        private ResourceRegistry $resources,
        private RouteTable $routes,
        private RouteUrls $urls,
        private PaginationWriter $paginationWriter,
    ) {}

    /**
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws OutOfBoundsException     when a type or a related type is not declared
     * @throws UnexpectedValueException when a codec of the declaration refuses
     *                                  what an accessor returned,
     *                                  or a boundary cursor cannot be written as text
     * @throws LogicException           when the resource carries no record,
     *                                  a linkage page lacks a member its strategy needs,
     *                                  or a to-many linkage stands
     *                                  where its relationship route does not respond
     * @throws InvalidArgumentException when a relationship carries neither a link nor its linkage
     */
    public function resource(
        ResourceRecord $record,
        RecordGraph $graph,
        SparseFieldsets $fields,
        array $languageQuery,
    ): ResourceObject {
        $resource = $this->resources->get($record->type);
        $id = $this->documentId($record);

        // read up front, so a resource without a record (a linkage identifier) is refused
        // even when no field would read it
        $row = $record->requireRecord();
        $named = $fields->fields($record->type);

        $attributes = [];

        foreach ($resource->attributes as $key => $attribute) {
            if (!$attribute->hasAccessor() || !self::admits($named, $attribute->name)) {
                continue;
            }

            $attributes[$key] = self::encoded($resource, $record, $attribute, $row);
        }

        $relationships = [];

        foreach ($resource->relationships as $key => $relationship) {
            if (!self::admits($named, $relationship->name)) {
                continue;
            }

            $relationships[$key] = $this->relationship($record, $id, $relationship, $graph, $languageQuery);
        }

        $links = new Links($this->routes->fetches(RouteKind::Resource, $record->type, null)
            ? [Member::SELF => $this->urls->fetchUrl(
                new RouteTarget(RouteKind::Resource, $record->type, $id, null),
                $languageQuery,
            )]
            : []);

        return new ResourceObject($record->type, $id, $links, $attributes, $relationships);
    }

    /**
     * no `meta`: the per-item cursor of the cursor pagination profile is optional,
     * and the links of a page already carry its boundary cursors
     *
     * @throws OutOfBoundsException     when the type is not declared
     * @throws UnexpectedValueException when the id codec refuses the id,
     *                                  or writes no non-empty string
     */
    public function identifier(ResourceRecord $record): ResourceIdentifierObject
    {
        return new ResourceIdentifierObject($record->type, $this->documentId($record));
    }

    /**
     * @throws OutOfBoundsException     when the type is not declared
     * @throws UnexpectedValueException when the id codec refuses the id,
     *                                  or writes no non-empty string
     */
    public function documentId(ResourceRecord $record): string
    {
        $resource = $this->resources->get($record->type);

        try {
            $wire = $resource->id->codec->encode($record->id);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException(sprintf(
                'the id codec of type "%s" refuses the id "%s" the accessor of the declaration'
                . ' returned: %s',
                $resource->type,
                Printable::of($record->id),
                $exception->getMessage(),
            ), 0, $exception);
        }

        if (!is_string($wire)) {
            throw new UnexpectedValueException(sprintf(
                'the id codec of type "%s" wrote the id "%s" as %s, and "The values of the id, type,'
                . ' and lid members MUST be strings." [spec, Identification]',
                $resource->type,
                Printable::of($record->id),
                get_debug_type($wire),
            ));
        }

        if ($wire === '') {
            throw new UnexpectedValueException(sprintf(
                'the id codec of type "%s" wrote the id "%s" as the empty string, which identifies no'
                . ' resource',
                $resource->type,
                Printable::of($record->id),
            ));
        }

        return $wire;
    }

    /**
     * `data` stands only where the graph names the relationship, otherwise just the links.
     * pagination links address the relationship route, never the related one,
     * as JSON:API requires of a relationship object (Relationships).
     * no `meta`: a linkage page of the graph reports no total, estimate or truncation
     *
     * @param string                  $id            the id a document writes for the parent
     * @param array<array-key, mixed> $languageQuery
     *
     * @throws OutOfBoundsException     when a related type is not declared
     * @throws UnexpectedValueException when the id codec of an identifier refuses its id,
     *                                  or a boundary cursor cannot be written as text
     * @throws LogicException           when the linkage page lacks a member its strategy needs,
     *                                  or a to-many linkage stands
     *                                  where the relationship route does not respond
     * @throws InvalidArgumentException when the relationship carries neither a link nor its linkage
     */
    private function relationship(
        ResourceRecord $record,
        string $id,
        RelationshipMetadata $relationship,
        RecordGraph $graph,
        array $languageQuery,
    ): RelationshipObject {
        $members = [];
        $paged = $this->routes->fetches(RouteKind::Relationship, $record->type, $relationship->name);

        if ($paged) {
            $members[Member::SELF] = $this->urls->fetchUrl(
                new RouteTarget(RouteKind::Relationship, $record->type, $id, $relationship->name),
                $languageQuery,
            );
        }

        if ($this->routes->fetches(RouteKind::Related, $record->type, $relationship->name)) {
            $members[Member::RELATED] = $this->urls->fetchUrl(
                new RouteTarget(RouteKind::Related, $record->type, $id, $relationship->name),
                $languageQuery,
            );
        }

        $links = new Links($members);

        // the graph is keyed by the id the accessor read, not by the id a document writes
        $linkage = $graph->linkage($record->type, $record->id, $relationship->name);

        if ($linkage === null) {
            return RelationshipObject::withoutLinkage($links);
        }

        if (!$linkage->isToMany()) {
            $of = $linkage->record();

            return RelationshipObject::toOne($links, $of === null ? null : $this->identifier($of));
        }

        if (!$paged) {
            throw new LogicException(sprintf(
                'the to-many relationship "%s" of type "%s" carries its linkage and excludes its relationship route,'
                . ' which its pagination links lead to; the API is not built with such a declaration',
                $relationship->name,
                $record->type,
            ));
        }

        $page = $linkage->page();
        $route = new RouteTarget(RouteKind::Relationship, $record->type, $id, $relationship->name);

        return RelationshipObject::toMany(
            $this->paginationWriter->links($links, $page, $route, $languageQuery),
            array_map($this->identifier(...), $page->records),
        );
    }

    /**
     * a value the codec refuses came from the accessor of the project, not from the client,
     * hence `UnexpectedValueException`, a 500
     *
     * @throws UnexpectedValueException when the codec refuses what the accessor returned
     */
    private static function encoded(
        ResourceMetadata $resource,
        ResourceRecord $record,
        AttributeMetadata $attribute,
        object $row,
    ): mixed {
        $value = $attribute->valueOf($row);

        try {
            return $attribute->codec->encode($value);
        } catch (InvalidArgumentException $exception) {
            throw new UnexpectedValueException(sprintf(
                'the codec of attribute "%s" of type "%s" refuses what the accessor of the declaration'
                . ' returned for the record of id "%s": %s',
                $attribute->name,
                $resource->type,
                Printable::of($record->id),
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * @param ?list<string> $named the fields the client named, null when it named no fieldset
     */
    private static function admits(?array $named, string $field): bool
    {
        return $named === null || in_array($field, $named, true);
    }
}
