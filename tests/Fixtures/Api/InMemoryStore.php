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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use DateTimeImmutable;
use LogicException;
use Override;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Store\VersionMismatchException;

/**
 * every port of `Store` for the three types of `FixtureApi`, held in memory,
 * following the contracts the ports document.
 *
 * a write checks its precondition against the stored version again,
 * and a record that is gone is `ResourceNotFoundException` under every precondition.
 * an identifier that names no stored record is `RelatedResourceNotFoundException`,
 * except in a removal, which skips it.
 * a deletion takes the record out of every relationship that points at it,
 * as a foreign key with ON DELETE does. every record is one the client may see.
 *
 * a new pet gets the next number,
 * a new order a counted-up version 4 UUID and the moment the store was given,
 * so every run gets the same documents
 *
 * @implements CollectionReader<Record>
 * @implements ResourceLoader<Record>
 * @implements RelatedLoader<Record>
 * @implements ResourceCreator<Record>
 * @implements ResourceUpdater<Record>
 *
 * @internal
 */
final class InMemoryStore implements
    CollectionReader,
    ResourceLoader,
    RelatedLoader,
    ResourceCreator,
    ResourceUpdater,
    ResourceDeleter,
    RelationshipReplacer,
    RelationshipAdder,
    RelationshipRemover,
    TransactionBoundary
{
    public const PETS = 'pets';

    public const CUSTOMERS = 'customers';

    public const ORDERS = 'orders';

    /**
     * every record carries all of them, whether a write names them or not
     */
    private const ATTRIBUTES = [
        self::PETS => ['name', 'description', 'listedAt'],
        self::CUSTOMERS => ['name'],
        self::ORDERS => ['address', 'placedAt'],
    ];

    private const TO_ONE = [
        self::PETS => ['owner' => self::CUSTOMERS],
        self::CUSTOMERS => [],
        self::ORDERS => ['customer' => self::CUSTOMERS],
    ];

    private const TO_MANY = [
        self::PETS => ['orders' => self::ORDERS],
        self::CUSTOMERS => [],
        self::ORDERS => [],
    ];

    /**
     * @var array<string, array<string, Record>> type => id => record, in the order they were stored
     */
    private array $records;

    /**
     * @var array<string, array<string, Record>> what `reset()` puts back
     */
    private readonly array $initial;

    /**
     * @var array{array<string, array<string, Record>>, int}|null what `begin()` found,
     *                                                             null outside a transaction
     */
    private ?array $savepoint = null;

    /** how many orders the store placed without a client id */
    private int $ordersCreated = 0;

    private RecordMatcher $matcher;

    /**
     * @param list<Record>      $records
     * @param DateTimeImmutable $now     the moment an order is placed at
     */
    public function __construct(array $records, private readonly DateTimeImmutable $now)
    {
        $this->records = [self::PETS => [], self::CUSTOMERS => [], self::ORDERS => []];

        foreach ($records as $record) {
            $this->records[self::known($record->type)][$record->id] = $record;
        }

        $this->initial = $this->records;

        $this->matcher = new RecordMatcher(function (Record $record, string $relationship): array {
            return $this->related($record, $relationship);
        });
    }

    public static function seeded(): self
    {
        $order = static function (int $number): string {
            return sprintf('00000000-0000-4000-8000-%012d', $number);
        };

        return new self([
            new Record(self::CUSTOMERS, '1', '1', ['name' => 'Ada Lovelace']),
            new Record(self::CUSTOMERS, '2', '1', ['name' => 'Grace Hopper']),
            new Record(self::CUSTOMERS, '3', '1', ['name' => 'Alan Turing']),
            new Record(
                self::PETS,
                '1',
                '1',
                [
                    'name' => 'Rex',
                    'description' => 'a beagle who loves long walks.',
                    'listedAt' => new DateTimeImmutable('2026-01-02T10:00:00+00:00'),
                ],
                ['owner' => '1'],
                ['orders' => [$order(1), $order(2)]],
            ),
            new Record(
                self::PETS,
                '2',
                '1',
                [
                    'name' => 'Whiskers',
                    'description' => null,
                    'listedAt' => new DateTimeImmutable('2026-02-03T11:30:00+00:00'),
                ],
                ['owner' => '2'],
                ['orders' => []],
            ),
            new Record(
                self::PETS,
                '3',
                '1',
                [
                    'name' => 'Nemo',
                    'description' => 'a clownfish.',
                    'listedAt' => new DateTimeImmutable('2026-03-04T09:15:00.250000+02:00'),
                ],
                ['owner' => '1'],
                ['orders' => [$order(3)]],
            ),
            new Record(
                self::ORDERS,
                $order(1),
                '1',
                ['address' => '1 Main Street', 'placedAt' => new DateTimeImmutable('2026-01-02T11:00:00+00:00')],
                ['customer' => '2'],
            ),
            new Record(
                self::ORDERS,
                $order(2),
                '1',
                ['address' => '2 Elm Street', 'placedAt' => new DateTimeImmutable('2026-01-03T08:00:00+00:00')],
                ['customer' => '3'],
            ),
            new Record(
                self::ORDERS,
                $order(3),
                '1',
                ['address' => '3 Oak Avenue', 'placedAt' => new DateTimeImmutable('2026-03-05T12:00:00+00:00')],
                ['customer' => '2'],
            ),
        ], new DateTimeImmutable('2026-09-21T12:00:00+00:00'));
    }

    /**
     * puts the store back as it was made, for a store shared across tests
     */
    public function reset(): void
    {
        $this->records = $this->initial;
        $this->ordersCreated = 0;
        $this->savepoint = null;
    }

    public function find(string $type, string $id): ?Record
    {
        return $this->records[self::known($type)][$id] ?? null;
    }

    /**
     * in the order they were stored
     *
     * @return list<Record>
     */
    public function all(string $type): array
    {
        return array_values($this->records[self::known($type)]);
    }

    #[Override]
    public function read(CollectionQuery $query): Slice
    {
        $records = $this->all($query->type);

        if ($query->parent !== null) {
            $parent = $this->find($query->parent->type, $query->parent->id);
            $records = $parent === null ? [] : $this->related($parent, $query->parent->relationship);
        }

        if ($query->filter !== null) {
            $matches = $query->filter->accept($this->matcher);
            $records = array_values(array_filter($records, $matches));
        }

        $records = array_values(array_filter($records, static function (Record $record) use ($query): bool {
            return self::matchesQuery($record, $query->queryParameters);
        }));
        $records = $this->sorted($records, $query->sort);

        return new Slice(
            array_slice($records, $query->window->offset, $query->window->limit),
            $query->totalRequested ? count($records) : null,
        );
    }

    /**
     * the query parameters the fixture API declares: `namePrefix` on the pets,
     * `placedAfter` on the orders of a pet
     *
     * @param Values<mixed> $parameters
     */
    private static function matchesQuery(Record $record, Values $parameters): bool
    {
        if ($parameters->has('namePrefix')) {
            $prefix = $parameters->get('namePrefix');

            $name = $record->attribute('name');

            if (!is_string($prefix) || !is_string($name) || !str_starts_with($name, $prefix)) {
                return false;
            }
        }

        if ($parameters->has('placedAfter')) {
            $after = $parameters->get('placedAfter');

            if (!$after instanceof DateTimeImmutable || $record->attribute('placedAt') < $after) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function load(LoadRequest $request): array
    {
        return array_map(function (string $id) use ($request): ?Record {
            return $this->find($request->type, $id);
        }, $request->ids);
    }

    #[Override]
    public function loadRelated(RelatedLoadRequest $request): array
    {
        return array_map(
            function (string $parentId) use ($request): array {
                $parent = $this->find($request->type, $parentId);

                return $parent === null
                    ? []
                    : array_slice(
                        $this->sorted($this->related($parent, $request->relationship), $request->sort),
                        0,
                        $request->limit,
                    );
            },
            $request->parentIds,
        );
    }

    /**
     * @return Record the created record; the fixture accepts no work for later
     */
    #[Override]
    public function create(CreateResource $operation): object
    {
        $type = self::known($operation->type);
        $id = $operation->id ?? $this->nextId($type);

        if ($this->find($type, $id) !== null) {
            throw new ResourceExistsException();
        }

        $attributes = array_fill_keys(self::ATTRIBUTES[$type], null);

        if ($type === self::ORDERS) {
            $attributes['placedAt'] = $this->now;
        }

        [$toOne, $toMany] = $this->linkages($type, $operation->relationships);
        $record = new Record(
            $type,
            $id,
            '1',
            array_replace($attributes, self::attributes($type, $operation->attributes)),
            array_replace(array_fill_keys(array_keys(self::TO_ONE[$type]), null), $toOne),
            array_replace(array_fill_keys(array_keys(self::TO_MANY[$type]), []), $toMany),
        );
        $this->records[$type][$id] = $record;

        return $record;
    }

    /**
     * @return Record the updated record; the fixture accepts no work for later
     */
    #[Override]
    public function update(UpdateResource $operation): object
    {
        $record = $this->checkedTarget($operation->target, $operation->precondition);
        [$toOne, $toMany] = $this->linkages($record->type, $operation->relationships);

        return $this->store($record->revised(self::attributes($record->type, $operation->attributes), $toOne, $toMany));
    }

    #[Override]
    public function delete(DeleteResource $operation): ?Accepted
    {
        $record = $this->checkedTarget($operation->target, $operation->precondition);
        unset($this->records[$record->type][$record->id]);

        foreach ($this->records as $type => $records) {
            foreach ($records as $id => $other) {
                $this->records[$type][$id] = self::without($other, $record);
            }
        }

        return null;
    }

    #[Override]
    public function replace(ReplaceRelationship $operation): ?Accepted
    {
        $parent = $this->checkedTarget($operation->parent, $operation->precondition);
        [
            $toOne,
            $toMany,
        ] = $this->linkages($parent->type, new Values([$operation->relationship => $operation->linkage]));
        $this->store($parent->revised([], $toOne, $toMany));

        return null;
    }

    #[Override]
    public function add(AddToRelationship $operation): ?Accepted
    {
        $parent = $this->checkedTarget($operation->parent, $operation->precondition);
        [, $toMany] = $this->linkages($parent->type, new Values([$operation->relationship => $operation->linkage]));

        // the spec: "If a given type and id is already in the relationship,
        // the server MUST NOT add it again."
        $ids = array_values(array_unique([
            ...$parent->toMany($operation->relationship),
            ...$toMany[$operation->relationship],
        ]));
        $this->store($parent->revised([], [], [$operation->relationship => $ids]));

        return null;
    }

    #[Override]
    public function remove(RemoveFromRelationship $operation): ?Accepted
    {
        $parent = $this->checkedTarget($operation->parent, $operation->precondition);
        $removed = array_map(static function (Identifier $identifier): string {
            return $identifier->id;
        }, $operation->linkage->identifiers());

        $ids = array_values(array_filter(
            $parent->toMany($operation->relationship),
            static function (string $id) use ($removed): bool {
                return !in_array($id, $removed, true);
            },
        ));
        $this->store($parent->revised([], [], [$operation->relationship => $ids]));

        return null;
    }

    #[Override]
    public function begin(): void
    {
        if ($this->savepoint !== null) {
            throw new LogicException('the fixture store runs one transaction at a time');
        }

        $this->savepoint = [$this->records, $this->ordersCreated];
    }

    #[Override]
    public function commit(): void
    {
        $this->openSavepoint();
        $this->savepoint = null;
    }

    #[Override]
    public function rollBack(): void
    {
        [$this->records, $this->ordersCreated] = $this->openSavepoint();
        $this->savepoint = null;
    }

    /**
     * the related type and the ids the record holds
     *
     * @return array{string, list<string>}
     *
     * @throws LogicException when the type declares no such relationship
     */
    private function linkageIds(Record $record, string $relationship): array
    {
        $type = self::known($record->type);
        $toOne = self::TO_ONE[$type];
        $toMany = self::TO_MANY[$type];

        if (array_key_exists($relationship, $toOne)) {
            $id = $record->toOne($relationship);

            return [$toOne[$relationship], $id === null ? [] : [$id]];
        }

        if (array_key_exists($relationship, $toMany)) {
            return [$toMany[$relationship], $record->toMany($relationship)];
        }

        throw new LogicException(sprintf('type "%s" has no relationship "%s"', $record->type, $relationship));
    }

    /**
     * in the order the relationship holds them; an id with no stored record is skipped
     *
     * @return list<Record>
     */
    private function related(Record $record, string $relationship): array
    {
        [$type, $ids] = $this->linkageIds($record, $relationship);
        $records = [];

        foreach ($ids as $id) {
            $related = $this->find($type, $id);

            if ($related !== null) {
                $records[] = $related;
            }
        }

        return $records;
    }

    /**
     * @param Values<mixed> $relationships
     *
     * @return array{array<string, ?string>, array<string, list<string>>}
     *
     * @throws RelatedResourceNotFoundException when an identifier names no record of its type
     */
    private function linkages(string $type, Values $relationships): array
    {
        $toOne = [];
        $toMany = [];
        $missing = [];

        foreach ($relationships->names() as $name) {
            $linkage = $relationships->get($name);
            $related = self::TO_ONE[$type][$name] ?? self::TO_MANY[$type][$name]
                ?? throw new LogicException(sprintf('type "%s" has no relationship "%s"', $type, $name));

            if (!$linkage instanceof Linkage) {
                throw new LogicException('a relationship of an operation carries a linkage');
            }

            $ids = [];

            foreach ($linkage->identifiers() as $identifier) {
                if ($identifier->type !== $related || $this->find($related, $identifier->id) === null) {
                    $missing[$name][] = $identifier;

                    continue;
                }

                $ids[] = $identifier->id;
            }

            if ($linkage->isToMany()) {
                $toMany[$name] = $ids;
            } else {
                $toOne[$name] = $ids[0] ?? null;
            }
        }

        if ($missing !== []) {
            throw new RelatedResourceNotFoundException($missing);
        }

        return [$toOne, $toMany];
    }

    /**
     * @throws ResourceNotFoundException when the record is gone
     * @throws VersionMismatchException  when the precondition does not admit the stored version
     */
    private function checkedTarget(Identifier $target, ?Precondition $precondition): Record
    {
        $record = $this->find($target->type, $target->id) ?? throw new ResourceNotFoundException();

        if ($precondition !== null && !$precondition->admits($record->version)) {
            throw new VersionMismatchException();
        }

        return $record;
    }

    private function store(Record $record): Record
    {
        $this->records[$record->type][$record->id] = $record;

        return $record;
    }

    private function nextId(string $type): string
    {
        if ($type === self::ORDERS) {
            do {
                $id = sprintf('00000000-0000-4000-8000-%012d', 100 + ++$this->ordersCreated);
            } while ($this->find($type, $id) !== null);

            return $id;
        }

        $numbers = array_map('intval', array_keys($this->records[$type]));

        return (string) (($numbers === [] ? 0 : max($numbers)) + 1);
    }

    /**
     * @param list<Record> $records
     *
     * @return list<Record>
     */
    private function sorted(array $records, Sort $sort): array
    {
        usort($records, function (Record $first, Record $second) use ($sort): int {
            foreach ($sort->fields() as $field) {
                $order = RecordMatcher::compareValues(
                    $this->matcher->value($first, $field->field),
                    $this->matcher->value($second, $field->field),
                );

                if ($order !== 0) {
                    return $field->direction === Direction::Descending ? -$order : $order;
                }
            }

            return 0;
        });

        return $records;
    }

    /**
     * @return array{array<string, array<string, Record>>, int}
     */
    private function openSavepoint(): array
    {
        return $this->savepoint ?? throw new LogicException('the fixture store has no open transaction');
    }

    /**
     * @param Values<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function attributes(string $type, Values $values): array
    {
        $attributes = [];

        foreach ($values->names() as $name) {
            if (!in_array($name, self::ATTRIBUTES[$type], true)) {
                throw new LogicException(sprintf('type "%s" has no attribute "%s"', $type, $name));
            }

            $attributes[$name] = $values->get($name);
        }

        return $attributes;
    }

    /**
     * the record with the deleted one taken out of its relationships
     */
    private static function without(Record $record, Record $deleted): Record
    {
        $toOne = $record->toOne;
        $toMany = $record->toMany;

        foreach (self::TO_ONE[$record->type] as $name => $type) {
            if ($type === $deleted->type && $toOne[$name] === $deleted->id) {
                $toOne[$name] = null;
            }
        }

        foreach (self::TO_MANY[$record->type] as $name => $type) {
            if ($type === $deleted->type) {
                $toMany[$name] = array_values(array_diff($toMany[$name], [$deleted->id]));
            }
        }

        return $toOne === $record->toOne && $toMany === $record->toMany
            ? $record
            : new Record($record->type, $record->id, $record->version, $record->attributes, $toOne, $toMany);
    }

    /**
     * @return self::PETS|self::CUSTOMERS|self::ORDERS
     *
     * @throws LogicException when the fixture declares no such type
     */
    private static function known(string $type): string
    {
        return match ($type) {
            self::PETS, self::CUSTOMERS, self::ORDERS => $type,
            default => throw new LogicException(sprintf('the fixture store holds no type "%s"', $type)),
        };
    }
}
