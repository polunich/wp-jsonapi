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
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\SortPath;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * the arithmetic of a paginated reading: the window asked for, the sort it runs in,
 * and the page built from the result.
 *
 * the window reads one probe record past the page, as `Query\Window::$limit` describes.
 *
 * `page[before]` alone is read in the reversed sort, and the records are turned back afterwards.
 * the probe is trimmed first, so the page ends at the record closest to the cursor,
 * as the cursor pagination profile requires.
 * a range, both cursors at once, is read forwards; when more than a page lies between them,
 * the profile asks for the page `page[after]` alone gives, flagged `rangeTruncated`.
 *
 * the probe covers only the direction that was read;
 * the other link is written anyway, as the profile lets a link lead to an empty page.
 * an empty page has no boundary record, so it hands back the cursor of the request:
 * a `null` link would say that the page the client came from is gone.
 *
 * a boundary cursor holds the id and each sort field but `id`, in the wire form of its codec.
 * a sort field through a relationship is read off the related records,
 * loaded for all the boundaries of a level in one reading per relationship;
 * a related id is read off the record before it where the relationship reads the related id.
 * a related record hidden from the client throws `UnexpectedValueException`:
 * its cursor cannot be written, and a `null` next link would falsely claim the last page
 *
 * @internal
 */
final readonly class Paginator
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
    ) {}

    public static function reverses(PageRequest $page): bool
    {
        return $page->strategy === Strategy::Cursor && $page->before !== null && $page->after === null;
    }

    /**
     * the reading of one page as the store receives it:
     * the window of the page, in the sort reversed for a lone `page[before]`
     *
     * @param ?FilterNode   $filter          with the conditions of the cursors of the page
     * @param Sort          $sort            the forward sort plus `id`, the one `page()` takes
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the type is no member name, or the sort lacks `id`
     */
    public function collectionQuery(
        string $type,
        ?FilterNode $filter,
        Sort $sort,
        PageRequest $page,
        PaginationPolicy $policy,
        FieldHint $fields,
        LanguagePreferences $languages,
        ?ParentScope $parent,
        Values $queryParameters,
    ): CollectionQuery {
        return new CollectionQuery(
            $type,
            $filter,
            self::reverses($page) ? $sort->reverse() : $sort,
            self::window($page, $policy),
            $page->totalRequested,
            $fields,
            $languages,
            $parent,
            $queryParameters,
        );
    }

    /**
     * @param LanguagePreferences $languages read by the loads of the related records
     *                                       a boundary cursor is written from
     * @param Slice<object>       $slice     already held to the window
     * @param Sort                $sort      the forward sort plus `id`, which keys the cursors;
     *                                       never the reversed reading sort
     *
     * @throws UnexpectedValueException when a boundary cursor cannot be written,
     *                                  or the id accessor returns no non-empty string
     * @throws InvalidArgumentException when `ResourceRegistry::sortPath()` refuses a sort field
     * @throws LogicException           when a type a cursor is read through binds no loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function page(
        PageRequest $page,
        LanguagePreferences $languages,
        Slice $slice,
        PaginationPolicy $policy,
        string $type,
        Sort $sort,
    ): Page {
        $size = $policy->usedSize($page);
        $resource = $this->resources->get($type);

        [$records, $more] = self::trimmed($slice->records, $size, $resource);

        if (self::reverses($page)) {
            $records = array_reverse($records);
        }

        [$hasNext, $hasPrev, $rangeTruncated] = match ($page->strategy) {
            Strategy::PageNumber => [$more, ($page->number ?? 1) > 1, false],
            Strategy::Offset => [$more, ($page->offset ?? 0) > 0, false],
            Strategy::Cursor => self::cursorLinks($page, $records !== [], $more),
        };

        $cursors = match (true) {
            $page->strategy !== Strategy::Cursor => [null, null],
            $records !== [] => $this->cursors($languages, [$records[0], self::last($records)], $type, $sort),
            default => [$page->after, $page->before],
        };

        return new Page(
            $records,
            $page->strategy,
            $size,
            $page->strategy === Strategy::PageNumber ? $page->number ?? 1 : null,
            $page->strategy === Strategy::Offset ? $page->offset ?? 0 : null,
            $hasNext,
            $hasPrev,
            $rangeTruncated,
            $slice->total,
            $slice->estimatedTotal,
            $cursors[0] ?? null,
            $cursors[1] ?? null,
        );
    }

    /**
     * the first linkage page of one parent, without cursors:
     * `withLinkageCursors()` fills the last cursors of a whole level in one reading
     *
     * @param list<object> $records at most `$size + 1`, the probe included
     * @param int          $size    the default page size the relationship declares
     *
     * @throws UnexpectedValueException when the id accessor returns no non-empty string
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function linkagePage(
        array $records,
        int $size,
        string $type,
        ExecutionContext $context,
    ): Page {
        [$members, $more] = self::trimmed($records, $size, $this->resources->get($type));

        // the strategy the related route would negotiate, so the next link lands in it
        $strategy = $context->cursorPagination ? Strategy::Cursor : Strategy::PageNumber;

        return new Page(
            $members,
            $strategy,
            $size,
            $strategy === Strategy::PageNumber ? 1 : null,
            null,
            $more,
            false,
            false,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * fills the last cursor of every non-empty cursor page of a level,
     * with one reading per relationship a sort field runs through
     *
     * @param LanguagePreferences $languages read by the loads of the related records
     *                                       a boundary cursor is written from
     * @param list<Page>          $pages
     *
     * @return list<Page>
     *
     * @throws UnexpectedValueException when a boundary cursor cannot be written
     * @throws InvalidArgumentException when `ResourceRegistry::sortPath()` refuses a sort field
     * @throws LogicException           when a type a cursor is read through binds no loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function withLinkageCursors(LanguagePreferences $languages, array $pages, string $type, Sort $sort): array
    {
        $boundaries = [];
        $positions = [];

        foreach ($pages as $position => $page) {
            $records = $page->records;

            if ($page->strategy !== Strategy::Cursor || $records === []) {
                continue;
            }

            $positions[] = $position;
            $boundaries[] = self::last($records);
        }

        if ($boundaries === []) {
            return $pages;
        }

        $cursors = array_combine($positions, $this->cursors($languages, $boundaries, $type, $sort));
        $filled = [];

        foreach ($pages as $position => $page) {
            $cursor = $cursors[$position] ?? null;
            $filled[] = $cursor === null ? $page : $page->withLastCursor($cursor);
        }

        return $filled;
    }

    /**
     * every number stays an int: past `PHP_INT_MAX` a sum or a product turns into a float,
     * which `Query\Window` refuses
     */
    private static function window(PageRequest $page, PaginationPolicy $policy): Window
    {
        $size = $policy->usedSize($page);
        // a size of `PHP_INT_MAX` leaves no room for the probe and needs none: nothing lies past it
        $limit = $size < PHP_INT_MAX ? $size + 1 : $size;

        $offset = match ($page->strategy) {
            Strategy::PageNumber => self::skipped($page->number ?? 1, $size, $limit),
            Strategy::Offset => $page->offset ?? 0,
            Strategy::Cursor => 0,
        };

        return new Window($offset, $limit);
    }

    /**
     * a page number too far out would overflow into a float.
     * such a page lies past the end of any collection and is empty at any offset,
     * so it is clamped to the furthest window instead of failing
     *
     * @param int $number 1 or above
     * @param int $limit  the page size plus the probe
     */
    private static function skipped(int $number, int $size, int $limit): int
    {
        $pages = $number - 1;
        $furthest = PHP_INT_MAX - $limit;

        return $pages > intdiv($furthest, $size) ? $furthest : $pages * $size;
    }

    /**
     * the records within the page, and whether the probe came back
     *
     * @param list<object> $records
     *
     * @return array{list<ResourceRecord>, bool}
     *
     * @throws UnexpectedValueException when the id accessor returns no non-empty string
     */
    private static function trimmed(array $records, int $size, ResourceMetadata $resource): array
    {
        $members = [];

        foreach (array_slice($records, 0, $size) as $record) {
            $members[] = ResourceRecord::of($resource, $record);
        }

        return [$members, count($records) > $size];
    }

    /**
     * @param bool $more whether the probe came back
     *
     * @return array{bool, bool, bool} hasNext, hasPrev and rangeTruncated
     */
    private static function cursorLinks(PageRequest $page, bool $filled, bool $more): array
    {
        $after = $page->after !== null;
        $before = $page->before !== null;

        if (!$filled) {
            return [$before, $after, false];
        }

        return [$before || $more, $after || ($before && $more), $after && $before && $more];
    }

    /**
     * @param non-empty-list<ResourceRecord> $boundaries
     *
     * @return list<Cursor>
     *
     * @throws UnexpectedValueException when a boundary cursor cannot be written
     * @throws InvalidArgumentException when `ResourceRegistry::sortPath()` refuses a sort field
     * @throws LogicException           when a type a cursor is read through binds no loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    private function cursors(LanguagePreferences $languages, array $boundaries, string $type, Sort $sort): array
    {
        $resource = $this->resources->get($type);

        // per sort field but the id of the type: the wire value of each boundary, keyed by its id
        $fields = [];

        foreach ($sort->fields() as $field) {
            $path = $this->resources->sortPath($type, $field->field);

            // the id of the type goes into the id member of the cursor
            if ($path->path->isId()) {
                continue;
            }

            $written = $path->path->toString();
            $fields[$written] = $path->attribute === null
                ? $this->relatedIds($languages, $boundaries, $resource, $path, $written)
                : $this->attributeValues($languages, $boundaries, $resource, $path, $path->attribute, $written);
        }

        $cursors = [];

        foreach ($boundaries as $boundary) {
            $values = [];

            foreach ($fields as $written => $wires) {
                $values[(string) $written] = $wires[$boundary->id];
            }

            $cursors[] = new Cursor($values, self::wireId($resource, $boundary->id, 'a boundary record'));
        }

        return $cursors;
    }

    /**
     * the attribute a sort path ends at, of each boundary, keyed by the id of the boundary
     *
     * @param non-empty-list<ResourceRecord> $boundaries
     *
     * @return array<array-key, bool|float|int|string>
     *
     * @throws UnexpectedValueException when a value is no finite scalar,
     *                                  or a related record is missing
     * @throws LogicException           when a type the records are read through binds no loader
     * @throws OutOfBoundsException     when a type is not declared
     */
    private function attributeValues(
        LanguagePreferences $languages,
        array $boundaries,
        ResourceMetadata $resource,
        SortPath $path,
        AttributeMetadata $attribute,
        string $written,
    ): array {
        $records = $path->relationships === []
            ? null
            : $this->relatedRecords(
                $languages,
                $boundaries,
                $resource,
                $path->relationships,
                $attribute->name,
                $written,
            );
        $values = [];

        foreach ($boundaries as $boundary) {
            $values[$boundary->id] = self::wireValue(
                $attribute,
                $records === null ? $boundary->requireRecord() : self::relatedOf($records, $boundary, $written),
                $written,
            );
        }

        return $values;
    }

    /**
     * the id a sort path ends at, of each boundary, keyed by the id of the boundary.
     * where the last relationship reads the related id, it is read off the record before it;
     * otherwise it comes through the related loader, in one reading for the level
     *
     * @param non-empty-list<ResourceRecord> $boundaries
     *
     * @return array<array-key, string>
     *
     * @throws UnexpectedValueException when a relationship on the path is empty,
     *                                  a related record is missing or hidden from the client,
     *                                  or the id codec writes no non-empty string
     * @throws LogicException           when a type the records are read through binds no loader,
     *                                  or the path runs through no relationship
     * @throws OutOfBoundsException     when a type is not declared
     */
    private function relatedIds(
        LanguagePreferences $languages,
        array $boundaries,
        ResourceMetadata $resource,
        SortPath $path,
        string $written,
    ): array {
        $before = $path->relationships;
        $last = array_pop($before) ?? throw new LogicException(sprintf(
            'sort path "%s" names a related id and runs through no relationship',
            Printable::of($written),
        ));
        // the record each boundary reaches before the last relationship
        $parents = [];
        $parentType = $resource;

        if ($before === []) {
            foreach ($boundaries as $boundary) {
                $parents[$boundary->id] = $boundary;
            }
        } else {
            $parentType = $this->resources->get($before[count($before) - 1]->relatedType);
            $reached = $this->relatedRecords($languages, $boundaries, $resource, $before, $last->name, $written);

            foreach ($reached as $boundaryId => $record) {
                $parents[$boundaryId] = ResourceRecord::of($parentType, $record);
            }
        }

        $ids = [];

        if ($last->readsRelatedId()) {
            foreach ($parents as $boundaryId => $parent) {
                $ids[$boundaryId] = $last->relatedIdOf($parent->requireRecord())
                    ?? throw self::emptyOn($last, $parent, $written);
            }
        } else {
            $distinct = [];

            foreach ($parents as $parent) {
                $distinct[$parent->id] = $parent;
            }

            // the one field needed is the id, which every record carries
            $records = $this->byRelatedLoader(
                $languages,
                array_values($distinct),
                $parentType,
                $last,
                new FieldHint([]),
                $written,
            );

            foreach ($parents as $boundaryId => $parent) {
                $ids[$boundaryId] = $path->resource->id->idOf($records[$parent->id]);
            }
        }

        $wires = [];

        foreach ($ids as $boundaryId => $id) {
            $wires[$boundaryId] = self::wireId($path->resource, $id, sprintf('sort field "%s"', $written));
        }

        return $wires;
    }

    /**
     * @param array<array-key, object> $records keyed by the id of the boundary record
     *
     * @throws UnexpectedValueException when the level was read without that boundary record
     */
    private static function relatedOf(array $records, ResourceRecord $boundary, string $path): object
    {
        return $records[$boundary->id] ?? throw new UnexpectedValueException(sprintf(
            'no related record of the boundary record "%s" of type "%s" was read for sort field "%s", so no'
            . ' boundary cursor can be written for it',
            Printable::of($boundary->id),
            $boundary->type,
            Printable::of($path),
        ));
    }

    /**
     * the record each boundary's sort field ends on, keyed by the id of the boundary:
     * each relationship of the path is read once per level, through the loader it declares
     *
     * @param non-empty-list<ResourceRecord>       $boundaries
     * @param non-empty-list<RelationshipMetadata> $relationships in path order
     * @param string                               $path          for the message
     *
     * @return non-empty-array<array-key, object>
     *
     * @throws UnexpectedValueException when a relationship on the path is empty,
     *                                  the id accessor returns no non-empty string,
     *                                  or a related record is missing or hidden from the client
     * @throws LogicException           when a type the records are read through binds no loader
     * @throws OutOfBoundsException     when a type is not declared
     */
    private function relatedRecords(
        LanguagePreferences $languages,
        array $boundaries,
        ResourceMetadata $resource,
        array $relationships,
        string $attribute,
        string $path,
    ): array {
        // the record each boundary has reached along the path, starting at itself
        $reached = [];

        foreach ($boundaries as $boundary) {
            $reached[$boundary->id] = $boundary;
        }

        $last = count($relationships) - 1;

        foreach ($relationships as $index => $relationship) {
            $related = $this->resources->get($relationship->relatedType);
            // the one field needed: the next relationship of the path, or the attribute it ends at
            $hint = new FieldHint([$index === $last ? $attribute : $relationships[$index + 1]->name]);
            $parents = [];

            foreach ($reached as $record) {
                $parents[$record->id] = $record;
            }

            $byParent = $relationship->readsRelatedId()
                ? $this->byIdentifier($languages, array_values($parents), $relationship, $hint, $path)
                : $this->byRelatedLoader($languages, array_values($parents), $resource, $relationship, $hint, $path);

            foreach ($reached as $boundaryId => $record) {
                $reached[$boundaryId] = ResourceRecord::of($related, $byParent[$record->id]);
            }

            $resource = $related;
        }

        $records = [];

        foreach ($reached as $boundaryId => $record) {
            $records[$boundaryId] = $record->requireRecord();
        }

        return $records;
    }

    /**
     * for a relationship whose related id is read off the parent: one load for the whole level,
     * keyed by the id of the parent
     *
     * @param non-empty-list<ResourceRecord> $parents
     *
     * @return array<array-key, object>
     *
     * @throws UnexpectedValueException when the relationship of a parent is empty,
     *                                  the loader breaks its batch contract,
     *                                  or a related record is missing or hidden from the client
     * @throws LogicException           when the related type binds no resource loader
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function byIdentifier(
        LanguagePreferences $languages,
        array $parents,
        RelationshipMetadata $relationship,
        FieldHint $hint,
        string $path,
    ): array {
        $ids = [];

        foreach ($parents as $parent) {
            $ids[$parent->id] = $relationship->relatedIdOf($parent->requireRecord())
                ?? throw self::emptyOn($relationship, $parent, $path);
        }

        $asked = array_values(array_unique(array_values($ids)));
        $byId = array_combine($asked, $this->store->load(
            new LoadRequest($relationship->relatedType, $asked, $hint, $languages, new Values([])),
        ));
        $records = [];

        foreach ($ids as $parentId => $id) {
            $records[$parentId] = $byId[$id] ?? throw new UnexpectedValueException(sprintf(
                'the resource loader of type "%s" returned no record for id "%s", which sort field "%s" of a'
                . ' boundary record is read off: a related record the client may not see leaves the library'
                . ' with no cursor to write, and a next link of null would claim that no next page exists'
                . ' [profile-cursor]',
                $relationship->relatedType,
                Printable::of($id),
                Printable::of($path),
            ));
        }

        return $records;
    }

    /**
     * for a relationship without a related id accessor:
     * one related loader reading for the whole level, keyed by the id of the parent
     *
     * @param non-empty-list<ResourceRecord> $parents
     *
     * @return array<array-key, object>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or a related record is missing or hidden from the client
     * @throws LogicException           when the type of the parents binds no related loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    private function byRelatedLoader(
        LanguagePreferences $languages,
        array $parents,
        ResourceMetadata $resource,
        RelationshipMetadata $relationship,
        FieldHint $hint,
        string $path,
    ): array {
        $parentIds = array_map(
            static function (ResourceRecord $parent): string {
                return $parent->id;
            },
            $parents,
        );
        $loaded = $this->store->loadToOne(
            RelatedLoadRequest::toOne(
                $resource->type,
                $parentIds,
                $relationship->name,
                $hint,
                $languages,
                new Values([]),
            ),
        );
        $records = [];

        foreach ($parentIds as $place => $parentId) {
            $records[$parentId] = $loaded[$place] ?? throw new UnexpectedValueException(sprintf(
                'the related loader of type "%s" returned no record of relationship "%s" for parent "%s",'
                . ' which sort field "%s" of a boundary record is read off: a related record the client may'
                . ' not see leaves the library with no cursor to write, and a next link of null would claim'
                . ' that no next page exists [profile-cursor]',
                $resource->type,
                $relationship->name,
                Printable::of($parentId),
                Printable::of($path),
            ));
        }

        return $records;
    }

    /**
     * @param string $holder what the cursor reads the id from, for the message
     *
     * @throws UnexpectedValueException when the codec writes no non-empty string
     */
    private static function wireId(ResourceMetadata $resource, string $id, string $holder): string
    {
        $wire = $resource->id->codec->encode($id);

        if (!is_string($wire)) {
            throw new UnexpectedValueException(sprintf(
                'the id codec of type "%s" wrote the id "%s" of %s as %s, and an id is a string [spec]',
                $resource->type,
                Printable::of($id),
                $holder,
                get_debug_type($wire),
            ));
        }

        if ($wire === '') {
            throw new UnexpectedValueException(sprintf(
                'the id codec of type "%s" wrote the id "%s" of %s as the empty string, which stands at no'
                . ' record',
                $resource->type,
                Printable::of($id),
                $holder,
            ));
        }

        return $wire;
    }

    private static function emptyOn(
        RelationshipMetadata $relationship,
        ResourceRecord $parent,
        string $path,
    ): UnexpectedValueException {
        return new UnexpectedValueException(sprintf(
            'relationship "%s" of record "%s" of type "%s" is empty, and sort field "%s" of a boundary record'
            . ' is read through it, so no boundary cursor can be written for it',
            $relationship->name,
            Printable::of($parent->id),
            $parent->type,
            Printable::of($path),
        ));
    }

    /**
     * @param object $record the boundary record, or the related record a sort field ends on
     *
     * @throws UnexpectedValueException when the value is no scalar a cursor holds, or is not finite
     */
    private static function wireValue(AttributeMetadata $attribute, object $record, string $path): bool|float|int|string
    {
        $wire = $attribute->codec->encode($attribute->valueOf($record));

        if (!is_bool($wire) && !is_float($wire) && !is_int($wire) && !is_string($wire)) {
            throw new UnexpectedValueException(sprintf(
                'sort field "%s" of a boundary record was written as %s, and a cursor carries a bool, a'
                . ' number or a string: a cursor divides the records totally and JSON writes no other'
                . ' scalar',
                Printable::of($path),
                get_debug_type($wire),
            ));
        }

        if (is_float($wire) && !is_finite($wire)) {
            throw new UnexpectedValueException(sprintf(
                'sort field "%s" of a boundary record was written as %s, which is no finite number, so it'
                . ' orders nothing',
                Printable::of($path),
                var_export($wire, true),
            ));
        }

        return $wire;
    }

    /**
     * @param non-empty-list<ResourceRecord> $records
     */
    private static function last(array $records): ResourceRecord
    {
        return $records[count($records) - 1];
    }
}
