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
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStoreCases;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `RelationshipReplacer`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreRelationshipReplacerTest extends TestCase
{
    public function testAReplacerReplacesEveryMember(): void
    {
        $store = self::store();

        self::assertNull($store->replace(self::operation('1', null, ['4', '2'])));

        $parent = $store->find('1');

        self::assertNotNull($parent);
        self::assertSame([['4', '2'], '2'], [$parent->littermates, $parent->version]);
    }

    /**
     * hidden member 5 stays whatever the linkage, while the read back holds exactly the linkage
     *
     * @param list<string> $linkage the ids the linkage names
     * @param list<string> $stored  the littermate ids of pet 1 after the replacement
     * @param list<string> $read    the ids the relationship reads back, by id ascending
     */
    #[DataProvider('replacementsBesideAMemberTheClientMayNotSee')]
    public function testAReplacerKeepsTheMembersTheClientMayNotSee(array $linkage, array $stored, array $read): void
    {
        $store = self::store(['2', '5']);

        self::assertNull($store->replace(self::operation('1', null, $linkage)));

        $parent = $store->find('1');

        self::assertNotNull($parent);
        self::assertSame([$stored, '2'], [$parent->littermates, $parent->version]);
        self::assertSame([$read, $read], self::readBack($store, '1'));
    }

    /**
     * pet 1 names 2 and hidden 5; pet 4 is no member
     *
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function replacementsBesideAMemberTheClientMayNotSee(): iterable
    {
        yield 'another pet' => [['4'], ['5', '4'], ['4']];
        yield 'no pet' => [[], ['5'], []];
        yield 'another pet and the member the client may see' => [['4', '2'], ['5', '4', '2'], ['2', '4']];
    }

    /**
     * @param class-string<Throwable>|null $failure
     */
    #[DataProviderExternal(PetStoreCases::class, 'preconditions')]
    public function testTheStoreEvaluatesThePreconditionInsideTheWrite(
        ?Precondition $precondition,
        string $parent,
        ?string $failure,
    ): void {
        $store = self::store();

        if ($failure !== null) {
            $this->expectException($failure);
        }

        self::assertNull($store->replace(self::operation($parent, $precondition, ['4'])));
        self::assertSame('2', $store->find($parent)?->version);
    }

    public function testTheStoreNamesTheMissingIdentifiersByTheRelationship(): void
    {
        $missing = new Identifier(PetStore::TYPE, '8');
        $invisible = new Identifier(PetStore::TYPE, '5');

        try {
            self::store()->replace(self::operation('1', null, ['4', '8', '5']));
            self::fail('the replacement was expected to fail');
        } catch (RelatedResourceNotFoundException $thrown) {
            self::assertEquals([PetStore::RELATIONSHIP => [$missing, $invisible]], $thrown->missing);
        }
    }

    /**
     * hidden pet 5 fails like nonexistent pet 8, although the replacement would keep it
     *
     * @param list<string> $linkage the ids the linkage names
     * @param list<string> $missing the ids the store names as missing
     */
    #[DataProvider('refusedLinkagesBesideAMemberTheClientMayNotSee')]
    public function testAReplacerRefusesAMemberTheClientMayNotSeeAsOneOfNoPet(array $linkage, array $missing): void
    {
        $store = self::store(['2', '5']);

        try {
            $store->replace(self::operation('1', null, $linkage));
            self::fail('the replacement was expected to fail');
        } catch (RelatedResourceNotFoundException $thrown) {
            self::assertEquals([PetStore::RELATIONSHIP => PetStoreCases::identifiers($missing)], $thrown->missing);
        }

        self::assertSame([['2', '5'], '1'], [$store->find('1')?->littermates, $store->find('1')?->version]);
    }

    /**
     * pet 1 names 2 and hidden 5; pet 4 is no member and pet 8 does not exist
     *
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function refusedLinkagesBesideAMemberTheClientMayNotSee(): iterable
    {
        yield 'the member the client may not see' => [['5'], ['5']];
        yield 'another pet and the member the client may not see' => [['4', '5'], ['5']];
        yield 'both members' => [['2', '5'], ['5']];
        yield 'a pet that does not exist' => [['8'], ['8']];
    }

    /**
     * pet 1, at version 1, names `$littermates`; pet 4 is no member and pet 5 is hidden
     *
     * @param list<string> $littermates
     */
    private static function store(array $littermates = ['2']): PetStore
    {
        return new PetStore([
            new StoredPet('1', '1', 'one', $littermates),
            new StoredPet('2', '1', 'two'),
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
     * @param list<string> $littermates the ids the linkage names
     */
    private static function operation(
        string $parent,
        ?Precondition $precondition,
        array $littermates,
    ): ReplaceRelationship {
        return new ReplaceRelationship(
            new Identifier(PetStore::TYPE, $parent),
            PetStore::RELATIONSHIP,
            Linkage::toMany(PetStoreCases::identifiers($littermates)),
            $precondition,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
