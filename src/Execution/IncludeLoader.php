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
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use UnexpectedValueException;

/**
 * builds the `RecordGraph` of a document.
 *
 * the walk runs level by level from the primary records, one batch per relationship per level,
 * so an include path of n segments costs n readings however many records a level holds.
 *
 * a relationship writes a data member when its related id is read off the record,
 * when it is declared always linked, or when an include path names it at this level.
 * only a path-named relationship adds its records to `included` and continues the walk from them;
 * the fieldsets never prune the walk.
 *
 * a related id read off its parent carries no record and stays unknown to the walk,
 * so a path naming the relationship still loads the record
 *
 * @internal
 */
final readonly class IncludeLoader
{
    /**
     * a member name holds no control character, so no two pairs join into one key
     */
    private const SEPARATOR = "\0";

    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Paginator $paginator,
    ) {}

    /**
     * the fields the library reads whatever the fieldset:
     * each relationship whose related id is read off the record,
     * and the first segment of each sort field but `id`, which a boundary cursor reads.
     * `$sort` is `null` for a reading without a page, which writes no cursor
     *
     * @return list<string>
     */
    public static function requiredFields(ResourceMetadata $resource, ?Sort $sort): array
    {
        $fields = [];

        foreach ($resource->relationships as $relationship) {
            if ($relationship->readsRelatedId()) {
                $fields[$relationship->name] = true;
            }
        }

        foreach ($sort?->fields() ?? [] as $field) {
            // the id accessor reads `id` whatever the hint names
            if (!$field->field->isId()) {
                $fields[$field->field->segments()[0]] = true;
            }
        }

        return array_map(
            static function (int|string $field): string {
                return (string) $field;
            },
            array_keys($fields),
        );
    }

    /**
     * the hint for records rendered as resource objects
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function fieldHint(string $type, SparseFieldsets $fields, ?Sort $sort): FieldHint
    {
        return new FieldHint($fields->fields($type), self::requiredFields($this->resources->get($type), $sort));
    }

    /**
     * the hint for records rendered as identifiers only
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    public function identifierHint(string $type, ?Sort $sort): FieldHint
    {
        return new FieldHint([], self::requiredFields($this->resources->get($type), $sort));
    }

    /**
     * @param list<ResourceRecord> $primary each carrying its record
     *
     * @throws UnexpectedValueException when a loader breaks its batch contract
     * @throws LogicException           when a primary record carries no record,
     *                                  or a capability the walk reads is not bound
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    public function load(
        GraphRequest $request,
        array $primary,
        ExecutionContext $context,
    ): RecordGraph {
        $paths = self::pathsOf($request->include);
        $walk = new IncludeWalk();
        $level = [];

        foreach ($primary as $entry) {
            self::reach($level, $entry, $paths);
            $walk->know($entry);
            $walk->renderAsPrimary($entry);
        }

        return $this->walk(array_values($level), $request->fields, $request->languages, $context, $walk);
    }

    /**
     * for the relationship route: its include paths start at the parent type with `$relationship`,
     * and the parent is only their root, never rendered, so none of its linkage is built
     *
     * @param list<ResourceRecord> $related the linkage members that carry a record;
     *                                      an identifier without one has nothing to continue from
     *
     * @throws UnexpectedValueException when a loader breaks its batch contract
     * @throws InvalidArgumentException when a member of $related carries no record
     * @throws LogicException           when no parent was loaded,
     *                                  or a capability the walk reads is not bound
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    public function loadFromRelationship(
        GraphRequest $request,
        string $parentType,
        string $relationship,
        array $related,
        ExecutionContext $context,
    ): RecordGraph {
        $declared = $this->resources->relationshipOf($parentType, $relationship);
        $paths = self::pathsOf($request->include);
        $tails = self::tailsOf($paths, $declared->name);
        $named = self::firstPath($paths, $declared->name) !== null;
        $walk = new IncludeWalk();
        $walk->know(ResourceRecord::of($this->resources->get($parentType), $context->record()));
        $level = [];

        foreach ($related as $entry) {
            $walk->know($entry);

            // the primary data are identifiers only,
            // so a member an include path names joins `included` as a resource object
            if (!$named || $walk->isRendered($entry)) {
                continue;
            }

            self::reach($level, $entry, $tails);
            $walk->include($entry);
        }

        return $this->walk(array_values($level), $request->fields, $request->languages, $context, $walk);
    }

    /**
     * for a 202: the include paths of the request name relationships of the request type,
     * not of the monitor type, so the walk takes none and `included` stays empty.
     * a fieldset hints only the reading of a relationship a path names, so none applies here
     *
     * @throws UnexpectedValueException when a loader breaks its batch contract
     * @throws LogicException           when the monitor carries no record,
     *                                  or a capability the walk reads is not bound
     * @throws OutOfBoundsException     when the monitor type is not declared
     */
    public function loadMonitor(
        LanguagePreferences $languages,
        ResourceRecord $monitor,
        ExecutionContext $context,
    ): RecordGraph {
        $walk = new IncludeWalk();
        $walk->know($monitor);
        $walk->renderAsPrimary($monitor);

        return $this->walk([new LevelEntry($monitor, [])], new SparseFieldsets([]), $languages, $context, $walk);
    }

    /**
     * @param list<LevelEntry> $level
     *
     * @throws UnexpectedValueException when a loader breaks its batch contract
     * @throws LogicException           when a record is missing where the linkage is read off it,
     *                                  or a capability the walk reads is not bound
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    private function walk(
        array $level,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        ExecutionContext $context,
        IncludeWalk $walk,
    ): RecordGraph {
        while ($level !== []) {
            $next = [];

            foreach ($this->batches($level) as $batch) {
                $relationship = $batch->relationship;
                $reached = match (true) {
                    $relationship->readsRelatedId() => $this->relateByIdentifier($batch, $fields, $languages, $walk),
                    $relationship->cardinality === Cardinality::ToMany => $this->relateToMany(
                        $batch,
                        $fields,
                        $languages,
                        $context,
                        $walk,
                    ),
                    default => $this->relateToOne($batch, $fields, $languages, $walk),
                };

                foreach ($reached as $entry) {
                    self::reach($next, $entry->resource, $entry->paths);
                    $walk->include($entry->resource);
                }
            }

            $level = array_values($next);
        }

        return $walk->graph($context->includeSupplied);
    }

    /**
     * one batch per relationship of one type, ordered by the first include path naming it;
     * a batch holds every record of the level the relationship writes a data member for
     *
     * @param list<LevelEntry> $level
     *
     * @return list<IncludeBatch>
     *
     * @throws OutOfBoundsException when a type is not declared
     */
    private function batches(array $level): array
    {
        $relationships = [];
        $parents = [];
        $order = [];

        foreach ($level as $entry) {
            $resource = $entry->resource;

            foreach ($this->resources->get($resource->type)->relationships as $relationship) {
                $first = self::firstPath($entry->paths, $relationship->name);

                if ($first === null && !$relationship->readsRelatedId() && !$relationship->linkageAlwaysPresent) {
                    continue;
                }

                $key = $resource->type . self::SEPARATOR . $relationship->name;
                $relationships[$key] ??= [$relationship, $resource->type];
                $parents[$key][] = new BatchParent(
                    $resource,
                    self::tailsOf($entry->paths, $relationship->name),
                    $first !== null,
                );
                $order[$key] = min($order[$key] ?? PHP_INT_MAX, $first ?? PHP_INT_MAX);
            }
        }

        // ordering by include path keeps the document deterministic;
        // a batch no path names only writes linkage, so it goes last
        asort($order);
        $batches = [];

        foreach (array_keys($order) as $key) {
            [$relationship, $type] = $relationships[$key];
            $batches[] = new IncludeBatch($relationship, $type, $parents[$key]);
        }

        return $batches;
    }

    /**
     * a to-one relationship whose related id is read off the record: the linkage costs no reading,
     * and the related records are loaded only where an include path names the relationship
     *
     * @return list<LevelEntry>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or the related id accessor returns no id
     * @throws LogicException           when a parent carries no record,
     *                                  or the related type binds no resource loader
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function relateByIdentifier(
        IncludeBatch $batch,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        IncludeWalk $walk,
    ): array {
        $relationship = $batch->relationship;
        $related = $relationship->relatedType;
        $identifiers = [];

        foreach ($batch->parents as $index => $parent) {
            $id = $relationship->relatedIdOf($parent->resource->requireRecord());
            $identifiers[$index] = $id;
            $walk->link($batch->type, $parent->resource->id, $relationship->name, RecordLinkage::toOne(
                $id === null ? null : new ResourceRecord($related, $id, null),
            ));
        }

        $missing = [];
        $asked = [];

        foreach ($batch->parents as $index => $parent) {
            $id = $identifiers[$index] ?? null;

            if (
                !$parent->named
                || $id === null
                || $walk->known($related, $id) !== null
                || array_key_exists($id, $asked)
            ) {
                continue;
            }

            $asked[$id] = true;
            $missing[] = $id;
        }

        // a load request names at least one id
        if ($missing !== []) {
            $resource = $this->resources->get($related);
            $loaded = $this->store->load(new LoadRequest(
                $related,
                $missing,
                $this->hint($batch->named(), $related, null, $fields),
                $languages,
                new Values([]),
            ));

            foreach ($loaded as $record) {
                // `null` is a record hidden from the client: its identifier still renders
                if ($record !== null) {
                    $walk->know(ResourceRecord::of($resource, $record));
                }
            }
        }

        $reached = [];

        foreach ($batch->parents as $index => $parent) {
            $id = $identifiers[$index] ?? null;
            $entry = $id === null ? null : $walk->known($related, $id);

            if ($parent->named && $entry !== null) {
                $reached[] = new LevelEntry($entry, $parent->tails);
            }
        }

        return $reached;
    }

    /**
     * a to-one relationship read through the related loader, in one reading for the whole level
     *
     * @return list<LevelEntry>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or the id accessor of the related type returns no id
     * @throws LogicException           when the type binds no related loader
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function relateToOne(
        IncludeBatch $batch,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        IncludeWalk $walk,
    ): array {
        $relationship = $batch->relationship;
        $related = $relationship->relatedType;
        $resource = $this->resources->get($related);
        $loaded = $this->store->loadToOne(RelatedLoadRequest::toOne(
            $batch->type,
            $batch->parentIds(),
            $relationship->name,
            $this->hint($batch->named(), $related, null, $fields),
            $languages,
            new Values([]),
        ));
        $reached = [];

        foreach ($batch->parents as $index => $parent) {
            $record = $loaded[$index] ?? null;
            $entry = $record === null ? null : $walk->know(ResourceRecord::of($resource, $record));
            $walk->link($batch->type, $parent->resource->id, $relationship->name, RecordLinkage::toOne($entry));

            if ($parent->named && $entry !== null) {
                $reached[] = new LevelEntry($entry, $parent->tails);
            }
        }

        return $reached;
    }

    /**
     * a to-many relationship: one reading for the whole level,
     * a page plus the probe record per parent, which becomes the first linkage page of that parent
     *
     * @return list<LevelEntry>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or a boundary cursor cannot be written
     * @throws LogicException           when the type binds no related loader,
     *                                  or a page of the level came back for no parent
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function relateToMany(
        IncludeBatch $batch,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        ExecutionContext $context,
        IncludeWalk $walk,
    ): array {
        $relationship = $batch->relationship;
        $related = $relationship->relatedType;
        $size = $this->resources->relationshipPaginationPolicy($batch->type, $relationship->name)->defaultSize;
        $sort = $this->resources->relationshipReadingSort($batch->type, $relationship->name);
        $loaded = $this->store->loadRelated(new RelatedLoadRequest(
            $batch->type,
            $batch->parentIds(),
            $relationship->name,
            $sort,
            $size + 1,
            $this->hint($batch->named(), $related, $sort, $fields),
            $languages,
            new Values([]),
        ));
        $pages = [];

        foreach ($loaded as $records) {
            $pages[] = $this->paginator->linkagePage($records, $size, $related, $context);
        }

        $cursored = $this->paginator->withLinkageCursors($languages, $pages, $related, $sort);
        $reached = [];
        $place = 0;

        foreach ($batch->parents as $parent) {
            $page = $cursored[$place] ?? throw new LogicException(sprintf(
                'the linkage pages of relationship "%s" of type "%s" came back %d for %d parents, and'
                . ' one page belongs to each parent of a level',
                $relationship->name,
                $batch->type,
                count($cursored),
                count($batch->parents),
            ));
            ++$place;
            $walk->link($batch->type, $parent->resource->id, $relationship->name, RecordLinkage::toMany($page));

            foreach ($page->records as $record) {
                $entry = $walk->know($record);

                if ($parent->named) {
                    $reached[] = new LevelEntry($entry, $parent->tails);
                }
            }
        }

        return $reached;
    }

    /**
     * @throws OutOfBoundsException when the type is not declared
     */
    private function hint(bool $named, string $type, ?Sort $sort, SparseFieldsets $fields): FieldHint
    {
        return $named ? $this->fieldHint($type, $fields, $sort) : $this->identifierHint($type, $sort);
    }

    /**
     * @return array<int, non-empty-list<string>>
     */
    private static function pathsOf(?IncludePaths $include): array
    {
        $paths = [];

        foreach ($include?->all() ?? [] as $place => $path) {
            $paths[$place] = $path->relationships();
        }

        return $paths;
    }

    /**
     * the rest of each path that starts with the relationship, keyed by the place of the path;
     * a path that ends there leaves nothing
     *
     * @param array<int, non-empty-list<string>> $paths
     *
     * @return array<int, non-empty-list<string>>
     */
    private static function tailsOf(array $paths, string $name): array
    {
        $tails = [];

        foreach ($paths as $place => $path) {
            if ($path[0] !== $name) {
                continue;
            }

            $tail = array_slice($path, 1);

            if ($tail !== []) {
                $tails[$place] = $tail;
            }
        }

        return $tails;
    }

    /**
     * the place of the first path starting with the relationship, which orders the batches;
     * `null` when none does
     *
     * @param array<int, non-empty-list<string>> $paths
     */
    private static function firstPath(array $paths, string $name): ?int
    {
        $first = null;

        foreach ($paths as $place => $path) {
            if ($path[0] === $name && ($first === null || $place < $first)) {
                $first = $place;
            }
        }

        return $first;
    }

    /**
     * a pair joins the level once, with the paths of every parent that reached it.
     * the level is changed in place: a level passed in and returned would be copied by each call,
     * which makes a level of n pairs cost n squared
     *
     * @param array<string, LevelEntry>          $level
     * @param array<int, non-empty-list<string>> $paths
     */
    private static function reach(array &$level, ResourceRecord $resource, array $paths): void
    {
        $key = $resource->type . self::SEPARATOR . $resource->id;
        $held = $level[$key] ?? null;
        $level[$key] = new LevelEntry($held->resource ?? $resource, ($held->paths ?? []) + $paths);
    }
}
