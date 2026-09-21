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

use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceCreator;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use Polunich\WpJsonApi\Tests\Fixtures\Store\Job;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `ResourceCreator`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreResourceCreatorTest extends TestCase
{
    public function testACreatorReturnsTheCreatedRecord(): void
    {
        $store = self::store();

        $created = $store->create(self::create(null, ['name' => 'three'], [new Identifier(PetStore::TYPE, '2')]));

        self::assertInstanceOf(StoredPet::class, $created);
        self::assertSame(
            ['3', '1', 'three', ['2']],
            [$created->id, $created->version, $created->name, $created->littermates],
        );
        self::assertSame($created, $store->find('3'));
    }

    public function testACreatorReturnsAnAcceptedForWorkItHasNotFinished(): void
    {
        $store = self::store();

        $accepted = $store->create(self::create(null, [PetStore::DEFERRED => true, 'name' => 'three'], []));

        self::assertInstanceOf(Accepted::class, $accepted);
        self::assertSame(PetStore::MONITOR_TYPE, $accepted->type);
        self::assertInstanceOf(Job::class, $accepted->record);
        self::assertCount(2, $store->pets());
    }

    public function testInsideATransactionACreatorFinishesTheWrite(): void
    {
        $store = self::store();
        $store->begin();

        $created = $store->create(self::create(null, [PetStore::DEFERRED => true, 'name' => 'three'], []));

        self::assertInstanceOf(StoredPet::class, $created);
        self::assertCount(3, $store->pets());
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @param list<Identifier>        $littermates
     * @param class-string<Throwable> $failure
     */
    #[DataProvider('failures')]
    public function testACreatorThrowsTheFailuresOfTheWrite(
        ?string $id,
        array $attributes,
        array $littermates,
        string $failure,
        Closure $check,
    ): void {
        try {
            self::store()->create(self::create($id, $attributes, $littermates));
        } catch (Throwable $thrown) {
            self::assertInstanceOf($failure, $thrown);
            $check($thrown);

            return;
        }

        self::fail('the create was expected to fail');
    }

    /**
     * @return iterable<string, array{
     *     ?string,
     *     array<array-key, mixed>,
     *     list<Identifier>,
     *     class-string<Throwable>,
     *     Closure(Throwable): void,
     * }>
     */
    public static function failures(): iterable
    {
        $none = static function (Throwable $thrown): void {};

        yield 'a client-generated id that exists' => [
            '2',
            ['name' => 'three'],
            [],
            ResourceExistsException::class,
            $none,
        ];

        $missing = new Identifier(PetStore::TYPE, '9');

        yield 'a related resource that does not exist' => [
            null,
            ['name' => 'three'],
            [new Identifier(PetStore::TYPE, '2'), $missing],
            RelatedResourceNotFoundException::class,
            static function (Throwable $thrown) use ($missing): void {
                self::assertInstanceOf(RelatedResourceNotFoundException::class, $thrown);
                self::assertSame([PetStore::RELATIONSHIP => [$missing]], $thrown->missing);
            },
        ];

        $invisible = new Identifier(PetStore::TYPE, '7');

        yield 'a related resource the client may not see' => [
            null,
            ['name' => 'three'],
            [$invisible],
            RelatedResourceNotFoundException::class,
            static function (Throwable $thrown) use ($invisible): void {
                self::assertInstanceOf(RelatedResourceNotFoundException::class, $thrown);
                self::assertSame([PetStore::RELATIONSHIP => [$invisible]], $thrown->missing);
            },
        ];
        yield 'a name another pet carries' => [
            null,
            ['name' => 'two'],
            [],
            ConstraintViolationException::class,
            static function (Throwable $thrown): void {
                self::assertInstanceOf(ConstraintViolationException::class, $thrown);
                self::assertSame([PetStore::UNIQUE_NAME, ['name']], [$thrown->constraint, $thrown->fields]);
            },
        ];
    }

    private static function store(): PetStore
    {
        return new PetStore([new StoredPet('2', '1', 'two'), new StoredPet('7', '1', 'seven', [], false)]);
    }

    /**
     * @param array<array-key, mixed> $attributes
     * @param list<Identifier>        $littermates
     */
    private static function create(?string $id, array $attributes, array $littermates): CreateResource
    {
        return new CreateResource(
            PetStore::TYPE,
            $id,
            new Values($attributes),
            new Values([PetStore::RELATIONSHIP => Linkage::toMany($littermates)]),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
