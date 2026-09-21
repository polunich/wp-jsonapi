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

namespace Polunich\WpJsonApi\Tests\Unit\Fixtures\Store;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStoreCases;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `ResourceUpdater`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreResourceUpdaterTest extends TestCase
{
    /**
     * @param class-string<Throwable>|null $failure
     */
    #[DataProvider('preconditions')]
    public function testAnUpdaterEvaluatesThePreconditionInsideTheWrite(
        ?Precondition $precondition,
        string $target,
        ?string $failure,
    ): void {
        $store = self::store();

        if ($failure !== null) {
            $this->expectException($failure);
        }

        $updated = $store->update(self::update($target, $precondition, ['name' => 'renamed'], null));

        self::assertInstanceOf(StoredPet::class, $updated);
        self::assertSame(['2', '2', 'renamed'], [$updated->id, $updated->version, $updated->name]);
        self::assertSame($updated, $store->find('2'));
    }

    /**
     * pet 9 does not exist
     *
     * @return iterable<string, array{?Precondition, string, class-string<Throwable>|null}>
     */
    public static function preconditions(): iterable
    {
        yield 'no precondition' => [null, '2', null];
        yield 'any version' => [Precondition::anyVersion(), '2', null];
        yield 'a list that holds the stored version' => [Precondition::listedVersions(['7', '1']), '2', null];
        yield 'a list without the stored version' => [
            Precondition::listedVersions(['7']),
            '2',
            VersionMismatchException::class,
        ];
        yield 'a record that is gone, without precondition' => [null, '9', ResourceNotFoundException::class];
        yield 'a record that is gone, under any version' => [
            Precondition::anyVersion(),
            '9',
            ResourceNotFoundException::class,
        ];
        yield 'a record that is gone, under a list' => [
            Precondition::listedVersions(['1']),
            '9',
            ResourceNotFoundException::class,
        ];
    }

    public function testAnUpdaterThrowsTheFailuresOfTheLinkageAndOfTheConstraints(): void
    {
        $missing = new Identifier(PetStore::TYPE, '9');

        try {
            self::store()->update(self::update('2', null, [], [new Identifier(PetStore::TYPE, '3'), $missing]));
            self::fail('the update was expected to fail');
        } catch (RelatedResourceNotFoundException $thrown) {
            self::assertSame([PetStore::RELATIONSHIP => [$missing]], $thrown->missing);
        }

        try {
            self::store()->update(self::update('2', null, ['name' => 'three'], null));
            self::fail('the update was expected to fail');
        } catch (ConstraintViolationException $thrown) {
            self::assertSame([PetStore::UNIQUE_NAME, ['name']], [$thrown->constraint, $thrown->fields]);
        }
    }

    /**
     * hidden member 5 stays whatever the linkage, while the read back holds exactly the linkage
     *
     * @param list<string> $linkage the ids the linkage names
     * @param list<string> $stored  the littermate ids of pet 2 after the update
     * @param list<string> $read    the ids the relationship reads back, by id ascending
     */
    #[DataProvider('replacementsBesideAMemberTheClientMayNotSee')]
    public function testAnUpdaterKeepsTheMembersTheClientMayNotSee(array $linkage, array $stored, array $read): void
    {
        $store = self::store(['3', '5']);

        $updated = $store->update(self::update('2', null, [], PetStoreCases::identifiers($linkage)));

        self::assertInstanceOf(StoredPet::class, $updated);
        self::assertSame([$stored, '2'], [$updated->littermates, $updated->version]);
        self::assertSame($updated, $store->find('2'));
        self::assertSame([$read, $read], self::readBack($store, '2'));
    }

    /**
     * pet 2 names 3 and hidden 5; pet 4 is no member
     *
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function replacementsBesideAMemberTheClientMayNotSee(): iterable
    {
        yield 'another pet' => [['4'], ['5', '4'], ['4']];
        yield 'no pet' => [[], ['5'], []];
        yield 'another pet and the member the client may see' => [['4', '3'], ['5', '4', '3'], ['3', '4']];
    }

    /**
     * hidden pet 5 fails like nonexistent pet 9, whether it is a member or not
     *
     * @param list<string> $littermates the littermate ids of pet 2
     * @param list<string> $linkage     the ids the linkage names
     * @param list<string> $missing     the ids the store names as missing
     */
    #[DataProvider('refusedLinkagesBesideAMemberTheClientMayNotSee')]
    public function testAnUpdaterRefusesAPetTheClientMayNotSeeAsOneOfNoPet(
        array $littermates,
        array $linkage,
        array $missing,
    ): void {
        $store = self::store($littermates);

        try {
            $store->update(self::update('2', null, [], PetStoreCases::identifiers($linkage)));
            self::fail('the update was expected to fail');
        } catch (RelatedResourceNotFoundException $thrown) {
            self::assertEquals([PetStore::RELATIONSHIP => PetStoreCases::identifiers($missing)], $thrown->missing);
        }

        self::assertSame([$littermates, '1'], [$store->find('2')?->littermates, $store->find('2')?->version]);
    }

    /**
     * pet 5 is hidden, pet 4 is another pet and pet 9 does not exist
     *
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function refusedLinkagesBesideAMemberTheClientMayNotSee(): iterable
    {
        yield 'the member the client may not see' => [['3', '5'], ['5'], ['5']];
        yield 'another pet and the member the client may not see' => [['3', '5'], ['4', '5'], ['5']];
        yield 'both members' => [['3', '5'], ['3', '5'], ['5']];
        yield 'a pet the client may not see that is no member' => [['3'], ['4', '5'], ['5']];
        yield 'a pet that does not exist' => [['3', '5'], ['9'], ['9']];
    }

    public function testAnUpdaterAcceptsWorkForLaterOutsideATransactionOnly(): void
    {
        $store = self::store();

        $accepted = $store->update(self::update('2', null, [PetStore::DEFERRED => true], null));
        $store->begin();
        $updated = $store->update(self::update('2', null, [PetStore::DEFERRED => true], null));

        self::assertInstanceOf(Accepted::class, $accepted);
        self::assertSame(PetStore::MONITOR_TYPE, $accepted->type);
        self::assertInstanceOf(StoredPet::class, $updated);
    }

    /**
     * pet 2, at version 1, names `$littermates`; pet 5 is hidden
     *
     * @param list<string> $littermates
     */
    private static function store(array $littermates = []): PetStore
    {
        return new PetStore([
            new StoredPet('2', '1', 'two', $littermates),
            new StoredPet('3', '1', 'three'),
            new StoredPet('4', '1', 'four'),
            new StoredPet('5', '1', 'five', [], false),
        ]);
    }

    /**
     * the relationship read back both ways a client sees it, by id ascending:
     * the route through `CollectionReader`, and a document's linkage through `RelatedLoader`
     *
     * @return list<list<string>>
     */
    private static function readBack(PetStore $store, string $parent): array
    {
        $sort = new Sort([new SortField(new FieldPath(['id']))]);

        $page = $store->read(new CollectionQuery(
            PetStore::TYPE,
            null,
            $sort,
            new Window(0, 10),
            false,
            new FieldHint([], []),
            new LanguagePreferences([]),
            new ParentScope(PetStore::TYPE, $parent, PetStore::RELATIONSHIP),
            new Values([]),
        ));
        $linkage = $store->loadRelated(new RelatedLoadRequest(
            PetStore::TYPE,
            [$parent],
            PetStore::RELATIONSHIP,
            $sort,
            10,
            new FieldHint([], []),
            new LanguagePreferences([]),
            new Values([]),
        ));

        return [PetStoreCases::ids($page->records), ...array_map(PetStoreCases::ids(...), $linkage)];
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @param list<Identifier>|null   $littermates null when the update leaves the relationship out
     */
    private static function update(
        string $id,
        ?Precondition $precondition,
        array $attributes,
        ?array $littermates,
    ): UpdateResource {
        return new UpdateResource(
            new Identifier(PetStore::TYPE, $id),
            new Values($attributes),
            new Values($littermates === null ? [] : [PetStore::RELATIONSHIP => Linkage::toMany($littermates)]),
            $precondition,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
