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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;

/**
 * `PetStore` as a `TransactionBoundary`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreTransactionBoundaryTest extends TestCase
{
    public function testRollBackDiscardsEveryChangeSinceBegin(): void
    {
        $store = self::store();

        $store->begin();
        $store->delete(self::delete('2'));
        $store->delete(self::delete('3'));
        $store->rollBack();

        self::assertSame(['2', '3'], array_map(static function (StoredPet $pet): string {
            return $pet->id;
        }, $store->pets()));
    }

    public function testCommitKeepsEveryChangeSinceBegin(): void
    {
        $store = self::store();

        $store->begin();
        $store->delete(self::delete('2'));
        $store->commit();

        self::assertSame(['3'], array_map(static function (StoredPet $pet): string {
            return $pet->id;
        }, $store->pets()));
    }

    private static function store(): PetStore
    {
        return new PetStore([new StoredPet('2', '1', 'two'), new StoredPet('3', '1', 'three')]);
    }

    private static function delete(string $id): DeleteResource
    {
        return new DeleteResource(
            new Identifier(PetStore::TYPE, $id),
            null,
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
