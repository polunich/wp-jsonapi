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
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * the only way execution reaches the capabilities a type binds:
 * it resolves the implementation of each and checks what the reading ports return,
 * so each port rule stands in one place.
 *
 * a missing capability is a `LogicException`, never a client error:
 * the build refuses a declaration that reads through an unbound port,
 * and the gate refuses a write whose port is unbound with 403.
 *
 * a loader follows the batch contract of DataLoader: one value per key, in the order of the keys.
 * the port declares that list in PHPDoc only, which PHP does not enforce,
 * so a result that is no list or has the wrong length is refused:
 * read by position, it would pair a record with the wrong key.
 * the writes need no such check: PHP enforces their return types,
 * and `AcceptedWrites` checks the monitor type of an `Accepted`
 *
 * @internal
 */
final readonly class StoreGateway
{
    private const PORTS = [
        Capability::CollectionReader->value => CollectionReader::class,
        Capability::ResourceLoader->value => ResourceLoader::class,
        Capability::RelatedLoader->value => RelatedLoader::class,
        Capability::ResourceCreator->value => ResourceCreator::class,
        Capability::ResourceUpdater->value => ResourceUpdater::class,
        Capability::ResourceDeleter->value => ResourceDeleter::class,
        Capability::RelationshipReplacer->value => RelationshipReplacer::class,
        Capability::RelationshipAdder->value => RelationshipAdder::class,
        Capability::RelationshipRemover->value => RelationshipRemover::class,
    ];

    public function __construct(
        private ResourceRegistry $resources,
        private ImplementationResolver $implementations,
    ) {}

    /**
     * @return class-string
     */
    public static function portOf(Capability $capability): string
    {
        return self::PORTS[$capability->value];
    }

    /**
     * `null` for a record missing or hidden from the client
     *
     * @return list<?object>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or returns a value that is neither a record nor null
     * @throws LogicException           when the type binds no resource loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function load(LoadRequest $request): array
    {
        $loaderLabel = sprintf('the resource loader of type "%s"', Printable::of($request->type));
        $batch = self::checkedBatch(
            $this->implementation($request->type, ResourceLoader::class)->load($request),
            $request->ids,
            $loaderLabel,
        );
        $records = [];

        foreach ($request->ids as $place => $id) {
            $record = $batch[$place];

            if ($record !== null && !is_object($record)) {
                throw new UnexpectedValueException(sprintf(
                    '%s returned %s at the place of id "%s", and a loader returns the record of each id or null for'
                    . ' the record it does not give',
                    $loaderLabel,
                    get_debug_type($record),
                    Printable::of($id),
                ));
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * at most `$request->limit` related records per parent
     *
     * @return list<list<object>>
     *
     * @throws UnexpectedValueException when the loader breaks its batch contract,
     *                                  or the entry of a parent is no list, exceeds the limit
     *                                  or holds a value that is no record
     * @throws LogicException           when the type of the parents binds no related loader
     * @throws OutOfBoundsException     when the type of the parents is not declared
     */
    public function loadRelated(RelatedLoadRequest $request): array
    {
        $loaderLabel = sprintf(
            'the related loader of relationship "%s" of type "%s"',
            Printable::of($request->relationship),
            Printable::of($request->type),
        );
        $batch = self::checkedBatch(
            $this->implementation($request->type, RelatedLoader::class)->loadRelated($request),
            $request->parentIds,
            $loaderLabel,
        );
        $related = [];

        foreach ($request->parentIds as $place => $parentId) {
            $related[] = self::relatedOf($batch[$place], $parentId, $request->limit, $loaderLabel);
        }

        return $related;
    }

    /**
     * `null` where the loader returns no record:
     * an empty relationship or a record hidden from the client, which cannot be told apart
     *
     * @return list<?object>
     *
     * @throws InvalidArgumentException when the request reads more than one record per parent
     * @throws UnexpectedValueException when the loader breaks its batch contract
     * @throws LogicException           when the type binds no related loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function loadToOne(RelatedLoadRequest $request): array
    {
        if (!$request->readsOne()) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" of type "%s" is read with the limit %d, and a reading of one related record per'
                . ' parent reads one',
                Printable::of($request->relationship),
                Printable::of($request->type),
                $request->limit,
            ));
        }

        $records = [];

        foreach ($this->loadRelated($request) as $related) {
            $records[] = $related[0] ?? null;
        }

        return $records;
    }

    /**
     * @return Slice<object>
     *
     * @throws UnexpectedValueException when the records are no list, exceed the window,
     *                                  or hold a value that is no record
     * @throws LogicException           when the type binds no collection reader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function read(CollectionQuery $query): Slice
    {
        $slice = $this->implementation($query->type, CollectionReader::class)->read($query);
        self::checkSlice($slice, $query, sprintf('the collection reader of type "%s"', Printable::of($query->type)));

        return $slice;
    }

    /**
     * a slice holds at most the window of its query, the probe record past the page included
     *
     * @param Slice<object> $slice
     * @param string        $source what read the slice, for the message
     *
     * @throws UnexpectedValueException when the records are no list, exceed the window,
     *                                  or hold a value that is no record
     */
    public static function checkSlice(Slice $slice, CollectionQuery $query, string $source): void
    {
        self::checkRecords($slice->records, $query, $source);
    }

    /**
     * the created record, or an `Accepted` for work the store has not finished
     *
     * @throws LogicException       when the type binds no resource creator
     * @throws OutOfBoundsException when the type is not declared
     */
    public function create(CreateResource $operation): object
    {
        return $this->implementation($operation->type, ResourceCreator::class)->create($operation);
    }

    /**
     * the updated record, or an `Accepted` for work the store has not finished
     *
     * @throws LogicException       when the type binds no resource updater
     * @throws OutOfBoundsException when the type is not declared
     */
    public function update(UpdateResource $operation): object
    {
        return $this->implementation($operation->target->type, ResourceUpdater::class)->update($operation);
    }

    /**
     * @throws LogicException       when the type binds no resource deleter
     * @throws OutOfBoundsException when the type is not declared
     */
    public function delete(DeleteResource $operation): ?Accepted
    {
        return $this->implementation($operation->target->type, ResourceDeleter::class)->delete($operation);
    }

    /**
     * @throws LogicException       when the type binds no relationship replacer
     * @throws OutOfBoundsException when the type is not declared
     */
    public function replace(ReplaceRelationship $operation): ?Accepted
    {
        return $this->implementation($operation->parent->type, RelationshipReplacer::class)->replace($operation);
    }

    /**
     * @throws LogicException       when the type binds no relationship adder
     * @throws OutOfBoundsException when the type is not declared
     */
    public function add(AddToRelationship $operation): ?Accepted
    {
        return $this->implementation($operation->parent->type, RelationshipAdder::class)->add($operation);
    }

    /**
     * @throws LogicException       when the type binds no relationship remover
     * @throws OutOfBoundsException when the type is not declared
     */
    public function remove(RemoveFromRelationship $operation): ?Accepted
    {
        return $this->implementation($operation->parent->type, RelationshipRemover::class)->remove($operation);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $port
     *
     * @return T
     *
     * @throws LogicException       when the type binds no implementation to that capability
     * @throws OutOfBoundsException when the type is not declared
     */
    private function implementation(string $type, string $port): object
    {
        $capability = array_search($port, self::PORTS, true);

        if ($capability === false) {
            throw new LogicException(sprintf('%s is the port of no capability', $port));
        }

        $resource = $this->resources->get($type);
        $reference = $resource->capabilities[$capability] ?? throw new LogicException(sprintf(
            'type "%s" binds no %s: the build refuses a declaration that reads through a port it does not bind,'
            . ' and the gate refuses an operation whose port is not bound, so a request never reaches it',
            $resource->type,
            $capability,
        ));

        return $this->implementations->resolve($reference, $port);
    }

    /**
     * @param array<mixed>           $batch
     * @param non-empty-list<string> $keys
     * @param string                 $loaderLabel the loader, for the message
     *
     * @return list<mixed>
     *
     * @throws UnexpectedValueException when the batch is no list,
     *                                  or holds another number of entries than keys
     */
    private static function checkedBatch(array $batch, array $keys, string $loaderLabel): array
    {
        if (!array_is_list($batch)) {
            throw new UnexpectedValueException(sprintf(
                '%s returned an array keyed by other than its positions, and a loader returns a list: "Each index in'
                . ' the Array of values must correspond to the same index in the Array of keys."',
                $loaderLabel,
            ));
        }

        if (count($batch) !== count($keys)) {
            throw new UnexpectedValueException(sprintf(
                '%s returned %d entries for a request of %d, and "The Array of values must be the same length as'
                . ' the Array of keys."',
                $loaderLabel,
                count($batch),
                count($keys),
            ));
        }

        return $batch;
    }

    /**
     * @return list<object>
     *
     * @throws UnexpectedValueException when the entry is no list, exceeds the limit,
     *                                  or holds a value that is no record
     */
    private static function relatedOf(mixed $records, string $parentId, int $limit, string $loaderLabel): array
    {
        $parent = Printable::of($parentId);

        if (!is_array($records) || !array_is_list($records)) {
            throw new UnexpectedValueException(sprintf(
                '%s returned %s for parent "%s", and a loader returns the list of the related records of each'
                . ' parent',
                $loaderLabel,
                self::shapeOf($records),
                $parent,
            ));
        }

        if (count($records) > $limit) {
            throw new UnexpectedValueException(sprintf(
                '%s returned %d records for parent "%s", and the reading asks for %d at most',
                $loaderLabel,
                count($records),
                $parent,
                $limit,
            ));
        }

        $related = [];

        foreach ($records as $record) {
            if (!is_object($record)) {
                throw new UnexpectedValueException(sprintf(
                    '%s returned %s among the records of parent "%s", and a related record is a record',
                    $loaderLabel,
                    get_debug_type($record),
                    $parent,
                ));
            }

            $related[] = $record;
        }

        return $related;
    }

    /**
     * the records are read as `mixed`: PHP enforces none of what `Slice` declares of them
     *
     * @throws UnexpectedValueException when the records are no list, exceed the window,
     *                                  or hold a value that is no record
     */
    private static function checkRecords(mixed $records, CollectionQuery $query, string $source): void
    {
        if (!is_array($records) || !array_is_list($records)) {
            throw new UnexpectedValueException(sprintf(
                '%s returned a slice whose records are %s, and a slice holds the list of the records of the'
                . ' window in the order of the sort',
                $source,
                self::shapeOf($records),
            ));
        }

        if (count($records) > $query->window->limit) {
            throw new UnexpectedValueException(sprintf(
                '%s returned %d records for a window of %d, and a reading returns the records of the page and the'
                . ' one probe record past it at most',
                $source,
                count($records),
                $query->window->limit,
            ));
        }

        foreach ($records as $position => $record) {
            if (!is_object($record)) {
                throw new UnexpectedValueException(sprintf(
                    '%s returned %s at position %d, and a reading returns records',
                    $source,
                    get_debug_type($record),
                    $position,
                ));
            }
        }
    }

    private static function shapeOf(mixed $value): string
    {
        return is_array($value) ? 'an array keyed by other than its positions' : get_debug_type($value);
    }
}
