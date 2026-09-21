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
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStoreCases;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `RelationshipAdder`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreRelationshipAdderTest extends TestCase
{
    /**
     * pet 2 is a member already and is not added again
     */
    public function testAnAdderAddsEachMemberOnce(): void
    {
        $store = self::store();

        self::assertNull($store->add(self::operation('1', null, ['4', '2'])));

        $parent = $store->find('1');

        self::assertNotNull($parent);
        self::assertSame([['2', '4'], '2'], [$parent->littermates, $parent->version]);
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

        self::assertNull($store->add(self::operation($parent, $precondition, ['4'])));
        self::assertSame('2', $store->find($parent)?->version);
    }

    public function testTheStoreNamesTheMissingIdentifiersByTheRelationship(): void
    {
        $missing = new Identifier(PetStore::TYPE, '8');
        $invisible = new Identifier(PetStore::TYPE, '5');

        try {
            self::store()->add(self::operation('1', null, ['4', '8', '5']));
            self::fail('the addition was expected to fail');
        } catch (RelatedResourceNotFoundException $thrown) {
            self::assertEquals([PetStore::RELATIONSHIP => [$missing, $invisible]], $thrown->missing);
        }
    }

    /**
     * hidden pet 5 fails like nonexistent pet 8, although it is a member already,
     * which for a visible pet is a success
     */
    public function testAnAdderRefusesAMemberTheClientMayNotSeeAsOneOfNoPet(): void
    {
        $store = self::store(['2', '5']);

        foreach (['5', '8'] as $id) {
            try {
                $store->add(self::operation('1', null, [$id]));
                self::fail('the addition was expected to fail');
            } catch (RelatedResourceNotFoundException $thrown) {
                self::assertEquals([PetStore::RELATIONSHIP => [new Identifier(PetStore::TYPE, $id)]], $thrown->missing);
            }
        }

        self::assertSame([['2', '5'], '1'], [$store->find('1')?->littermates, $store->find('1')?->version]);
        self::assertNull($store->add(self::operation('1', null, ['2'])));
        self::assertSame([['2', '5'], '2'], [$store->find('1')?->littermates, $store->find('1')?->version]);
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
     * @param list<string> $littermates the ids the linkage names
     */
    private static function operation(
        string $parent,
        ?Precondition $precondition,
        array $littermates,
    ): AddToRelationship {
        return new AddToRelationship(
            new Identifier(PetStore::TYPE, $parent),
            PetStore::RELATIONSHIP,
            Linkage::toMany(array_map(static function (string $id): Identifier {
                return new Identifier(PetStore::TYPE, $id);
            }, $littermates)),
            $precondition,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
