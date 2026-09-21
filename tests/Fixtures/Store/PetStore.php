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

namespace Polunich\WpJsonApi\Tests\Fixtures\Store;

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
use Polunich\WpJsonApi\Store\ConstraintViolationException;
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
 * an in-memory store of pets that point at each other through the to-many `littermates`,
 * implementing every Store port to its documented contract.
 *
 * it reads no filter and sorts by id alone
 *
 * @implements CollectionReader<StoredPet>
 * @implements ResourceLoader<StoredPet>
 * @implements RelatedLoader<StoredPet>
 * @implements ResourceCreator<StoredPet>
 * @implements ResourceUpdater<StoredPet>
 *
 * @internal
 */
final class PetStore implements
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
    public const TYPE = 'pets';

    public const RELATIONSHIP = 'littermates';

    public const MONITOR_TYPE = 'jobs';

    /**
     * an attribute that makes a create or an update return `Accepted`, outside a transaction only
     */
    public const DEFERRED = 'deferred';

    public const UNIQUE_NAME = 'unique_name';

    /**
     * violated by deleting a pet that another pet names as a littermate
     */
    public const PET_IN_USE = 'pet_in_use';

    /**
     * @var list<StoredPet>|null the pets as `begin()` found them, null outside a transaction
     */
    private ?array $savepoint = null;

    /**
     * @param list<StoredPet> $pets
     */
    public function __construct(private array $pets) {}

    /**
     * every stored pet, hidden ones included, in storage order
     *
     * @return list<StoredPet>
     */
    public function pets(): array
    {
        return $this->pets;
    }

    /**
     * the stored pet of the id, hidden or not
     */
    public function find(string $id): ?StoredPet
    {
        foreach ($this->pets as $pet) {
            if ($pet->id === $id) {
                return $pet;
            }
        }

        return null;
    }

    #[Override]
    public function read(CollectionQuery $query): Slice
    {
        self::expect(self::TYPE, $query->type);

        if ($query->filter !== null) {
            throw new LogicException('the pet store reads no filter');
        }

        $pets = $this->visible();

        if ($query->parent !== null) {
            self::expect(self::TYPE, $query->parent->type);
            self::expect(self::RELATIONSHIP, $query->parent->relationship);

            $pets = $this->littermatesOf($query->parent->id);
        }

        $sorted = self::sorted($pets, $query->sort);

        return new Slice(
            array_slice($sorted, $query->window->offset, $query->window->limit),
            $query->totalRequested ? count($sorted) : null,
        );
    }

    #[Override]
    public function load(LoadRequest $request): array
    {
        self::expect(self::TYPE, $request->type);

        return array_map(
            function (string $id): ?StoredPet {
                $pet = $this->find($id);

                return $pet !== null && $pet->visible ? $pet : null;
            },
            $request->ids,
        );
    }

    #[Override]
    public function loadRelated(RelatedLoadRequest $request): array
    {
        self::expect(self::TYPE, $request->type);
        self::expect(self::RELATIONSHIP, $request->relationship);

        return array_map(
            function (string $parentId) use ($request): array {
                return array_slice(
                    self::sorted($this->littermatesOf($parentId), $request->sort),
                    0,
                    $request->limit,
                );
            },
            $request->parentIds,
        );
    }

    #[Override]
    public function create(CreateResource $operation): object
    {
        self::expect(self::TYPE, $operation->type);

        if ($this->savepoint === null && $operation->attributes->has(self::DEFERRED)) {
            return new Accepted(self::MONITOR_TYPE, new Job('create-' . count($this->pets)));
        }

        if ($operation->id !== null && $this->find($operation->id) !== null) {
            throw new ResourceExistsException();
        }

        $littermates = $operation->relationships->has(self::RELATIONSHIP)
            ? $this->littermateIds(self::RELATIONSHIP, $operation->relationships->get(self::RELATIONSHIP))
            : [];
        $name = self::name($operation->attributes, '');
        $this->expectUniqueName($name, null);

        $pet = new StoredPet($operation->id ?? (string) (count($this->pets) + 1), '1', $name, $littermates);
        $this->pets[] = $pet;

        return $pet;
    }

    #[Override]
    public function update(UpdateResource $operation): object
    {
        self::expect(self::TYPE, $operation->type);
        $pet = $this->checkedTarget($operation->target->id, $operation->precondition);

        if ($this->savepoint === null && $operation->attributes->has(self::DEFERRED)) {
            return new Accepted(self::MONITOR_TYPE, new Job('update-' . $pet->id));
        }

        $littermates = $operation->relationships->has(self::RELATIONSHIP)
            ? $this->replacedIds($pet, self::RELATIONSHIP, $operation->relationships->get(self::RELATIONSHIP))
            : $pet->littermates;
        $name = self::name($operation->attributes, $pet->name);
        $this->expectUniqueName($name, $pet->id);

        return $this->replaceStored($pet->revised($name, $littermates));
    }

    #[Override]
    public function delete(DeleteResource $operation): ?Accepted
    {
        self::expect(self::TYPE, $operation->type);
        $pet = $this->checkedTarget($operation->target->id, $operation->precondition);

        foreach ($this->pets as $other) {
            if (in_array($pet->id, $other->littermates, true)) {
                throw new ConstraintViolationException(self::PET_IN_USE);
            }
        }

        $this->pets = array_values(array_filter(
            $this->pets,
            static function (StoredPet $stored) use ($pet): bool {
                return $stored->id !== $pet->id;
            },
        ));

        return null;
    }

    #[Override]
    public function replace(ReplaceRelationship $operation): ?Accepted
    {
        self::expect(self::TYPE, $operation->type);
        self::expect(self::RELATIONSHIP, $operation->relationship);
        $parent = $this->checkedTarget($operation->parent->id, $operation->precondition);

        $this->replaceStored($parent->revised(
            $parent->name,
            $this->replacedIds($parent, $operation->relationship, $operation->linkage),
        ));

        return null;
    }

    #[Override]
    public function add(AddToRelationship $operation): ?Accepted
    {
        self::expect(self::TYPE, $operation->type);
        self::expect(self::RELATIONSHIP, $operation->relationship);
        $parent = $this->checkedTarget($operation->parent->id, $operation->precondition);

        $littermates = array_values(array_unique([
            ...$parent->littermates,
            ...$this->littermateIds($operation->relationship, $operation->linkage),
        ]));

        $this->replaceStored($parent->revised($parent->name, $littermates));

        return null;
    }

    #[Override]
    public function remove(RemoveFromRelationship $operation): ?Accepted
    {
        self::expect(self::TYPE, $operation->type);
        self::expect(self::RELATIONSHIP, $operation->relationship);
        $parent = $this->checkedTarget($operation->parent->id, $operation->precondition);

        // an identifier of no member or of a hidden pet is skipped,
        // as `RelationshipRemover` requires
        $removed = [];

        foreach ($operation->linkage->identifiers() as $identifier) {
            $pet = $identifier->type === self::TYPE ? $this->find($identifier->id) : null;

            if ($pet !== null && $pet->visible) {
                $removed[] = $identifier->id;
            }
        }

        $littermates = array_values(array_filter(
            $parent->littermates,
            static function (string $id) use ($removed): bool {
                return !in_array($id, $removed, true);
            },
        ));

        $this->replaceStored($parent->revised($parent->name, $littermates));

        return null;
    }

    #[Override]
    public function begin(): void
    {
        if ($this->savepoint !== null) {
            throw new LogicException('the pet store runs one transaction at a time');
        }

        $this->savepoint = $this->pets;
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
        $this->pets = $this->openSavepoint();
        $this->savepoint = null;
    }

    /**
     * the stored pet a write changes, once its stored version meets the precondition
     *
     * @throws ResourceNotFoundException when the pet is gone, under every precondition
     * @throws VersionMismatchException  when the precondition does not admit the stored version
     */
    private function checkedTarget(string $id, ?Precondition $precondition): StoredPet
    {
        $pet = $this->find($id);

        if ($pet === null) {
            throw new ResourceNotFoundException();
        }

        if ($precondition !== null && !$precondition->admits($pet->version)) {
            throw new VersionMismatchException();
        }

        return $pet;
    }

    /**
     * the ids the linkage names, each of a pet the client may see
     *
     * @return list<string>
     *
     * @throws RelatedResourceNotFoundException when an identifier names no such pet
     */
    private function littermateIds(string $relationship, mixed $linkage): array
    {
        if (!$linkage instanceof Linkage) {
            throw new LogicException('a relationship of an operation carries a linkage');
        }

        $ids = [];
        $missing = [];

        foreach ($linkage->identifiers() as $identifier) {
            $pet = $identifier->type === self::TYPE ? $this->find($identifier->id) : null;

            if ($pet === null || !$pet->visible) {
                $missing[] = $identifier;

                continue;
            }

            $ids[] = $identifier->id;
        }

        if ($missing !== []) {
            throw new RelatedResourceNotFoundException([$relationship => $missing]);
        }

        return $ids;
    }

    /**
     * the littermates a complete replacement stores: the members the client may not see stay first,
     * then come the ids of the linkage in its order.
     * a member that names no stored pet stays as well, as it does in `remove()`;
     * the order matters to no reader, since each sorts what it returns
     *
     * @return list<string>
     *
     * @throws RelatedResourceNotFoundException when an identifier of the linkage names no pet
     *                                          the client may see
     */
    private function replacedIds(StoredPet $pet, string $relationship, mixed $linkage): array
    {
        $named = $this->littermateIds($relationship, $linkage);
        $kept = array_filter(
            $pet->littermates,
            function (string $id): bool {
                return $this->find($id)?->visible !== true;
            },
        );

        return [...array_values($kept), ...$named];
    }

    /**
     * @throws ConstraintViolationException when another pet carries the name
     */
    private function expectUniqueName(string $name, ?string $id): void
    {
        foreach ($this->pets as $pet) {
            if ($pet->name === $name && $pet->id !== $id) {
                throw new ConstraintViolationException(self::UNIQUE_NAME, ['name']);
            }
        }
    }

    private function replaceStored(StoredPet $pet): StoredPet
    {
        $this->pets = array_map(
            static function (StoredPet $stored) use ($pet): StoredPet {
                return $stored->id === $pet->id ? $pet : $stored;
            },
            $this->pets,
        );

        return $pet;
    }

    /**
     * @return list<StoredPet>
     */
    private function visible(): array
    {
        return array_values(array_filter($this->pets, static function (StoredPet $pet): bool {
            return $pet->visible;
        }));
    }

    /**
     * the pets the client may see that the parent points at; none for a parent that is gone
     *
     * @return list<StoredPet>
     */
    private function littermatesOf(string $parentId): array
    {
        $littermates = $this->find($parentId)->littermates ?? [];

        return array_values(array_filter(
            $this->visible(),
            static function (StoredPet $pet) use ($littermates): bool {
                return in_array($pet->id, $littermates, true);
            },
        ));
    }

    /**
     * @return list<StoredPet>
     */
    private function openSavepoint(): array
    {
        if ($this->savepoint === null) {
            throw new LogicException('the pet store has no open transaction');
        }

        return $this->savepoint;
    }

    /**
     * @param list<StoredPet> $pets
     *
     * @return list<StoredPet>
     */
    private static function sorted(array $pets, Sort $sort): array
    {
        $fields = $sort->fields();

        if (count($fields) !== 1 || $fields[0]->field->toString() !== 'id') {
            throw new LogicException('the pet store sorts by id alone');
        }

        $sign = $fields[0]->direction === Direction::Descending ? -1 : 1;
        usort(
            $pets,
            static function (StoredPet $first, StoredPet $second) use ($sign): int {
                return $sign * strcmp($first->id, $second->id);
            },
        );

        return $pets;
    }

    /**
     * @param Values<mixed> $attributes
     */
    private static function name(Values $attributes, string $current): string
    {
        if (!$attributes->has('name')) {
            return $current;
        }

        $name = $attributes->get('name');

        if (!is_string($name)) {
            throw new LogicException('the name of a pet is a string');
        }

        return $name;
    }

    private static function expect(string $expected, string $actual): void
    {
        if ($actual !== $expected) {
            throw new LogicException(sprintf('the pet store serves "%s", not "%s"', $expected, $actual));
        }
    }
}
