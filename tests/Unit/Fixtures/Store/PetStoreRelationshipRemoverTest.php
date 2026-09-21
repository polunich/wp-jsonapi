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
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStoreCases;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `RelationshipRemover`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreRelationshipRemoverTest extends TestCase
{
    /**
     * @param list<Identifier> $removed   the linkage of the removal
     * @param list<string>     $remaining the littermate ids of the parent after the removal
     */
    #[DataProvider('removals')]
    public function testARemoverRemovesTheMembersAndAcceptsMissingOnes(array $removed, array $remaining): void
    {
        $store = self::store();

        self::assertNull($store->remove(self::operation('1', null, $removed)));

        $parent = $store->find('1');

        self::assertNotNull($parent);
        self::assertSame([$remaining, '2'], [$parent->littermates, $parent->version]);
    }

    /**
     * @return iterable<string, array{list<Identifier>, list<string>}>
     */
    public static function removals(): iterable
    {
        yield 'a member' => [PetStoreCases::identifiers(['2']), ['3', '5']];
        yield 'two members' => [PetStoreCases::identifiers(['3', '2']), ['5']];
        yield 'an id of no pet and a member' => [PetStoreCases::identifiers(['8', '2']), ['3', '5']];
        yield 'a pet that is no member and a member' => [PetStoreCases::identifiers(['4', '2']), ['3', '5']];
        yield 'the parent, which is no member of its own relationship' => [
            PetStoreCases::identifiers(['1', '3']),
            ['2', '5'],
        ];
        yield 'nothing that is a member' => [PetStoreCases::identifiers(['8', '4']), ['2', '3', '5']];
        yield 'an identifier of another type with the id of a member, and a member' => [
            [new Identifier('customers', '2'), new Identifier(PetStore::TYPE, '3')],
            ['2', '5'],
        ];
    }

    /**
     * removing hidden member 5 must leave everything as removing nonexistent pet 8 does
     */
    public function testARemoverSkipsAMemberTheClientMayNotSee(): void
    {
        $invisible = self::store();
        $missing = self::store();

        $result = $invisible->remove(self::operation('1', null, PetStoreCases::identifiers(['5', '2'])));

        self::assertNull($result);
        self::assertSame($missing->remove(self::operation('1', null, PetStoreCases::identifiers(['8', '2']))), $result);
        self::assertSame(['3', '5'], $invisible->find('1')?->littermates);
        self::assertEquals($missing->pets(), $invisible->pets());
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

        self::assertNull($store->remove(self::operation($parent, $precondition, PetStoreCases::identifiers(['4']))));
        self::assertSame('2', $store->find($parent)?->version);
    }

    /**
     * pet 1, at version 1, names 2, 3 and 5; pet 4 is no member and pet 5 is hidden
     */
    private static function store(): PetStore
    {
        return new PetStore([
            new StoredPet('1', '1', 'one', ['2', '3', '5']),
            new StoredPet('2', '1', 'two'),
            new StoredPet('3', '1', 'three'),
            new StoredPet('4', '1', 'four'),
            new StoredPet('5', '1', 'five', [], false),
        ]);
    }

    /**
     * @param list<Identifier> $littermates the linkage
     */
    private static function operation(
        string $parent,
        ?Precondition $precondition,
        array $littermates,
    ): RemoveFromRelationship {
        return new RemoveFromRelationship(
            new Identifier(PetStore::TYPE, $parent),
            PetStore::RELATIONSHIP,
            Linkage::toMany($littermates),
            $precondition,
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
