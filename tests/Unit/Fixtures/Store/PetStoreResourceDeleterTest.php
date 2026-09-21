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
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Throwable;

/**
 * `PetStore` as a `ResourceDeleter`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreResourceDeleterTest extends TestCase
{
    /**
     * @param class-string<Throwable>|null $failure
     */
    #[DataProvider('preconditions')]
    public function testADeleterEvaluatesThePreconditionInsideTheWrite(
        ?Precondition $precondition,
        string $target,
        ?string $failure,
    ): void {
        $store = self::store();

        if ($failure !== null) {
            $this->expectException($failure);
        }

        self::assertNull($store->delete(self::delete($target, $precondition)));
        self::assertNull($store->find('3'));
    }

    /**
     * pet 9 does not exist
     *
     * @return iterable<string, array{?Precondition, string, class-string<Throwable>|null}>
     */
    public static function preconditions(): iterable
    {
        yield 'no precondition' => [null, '3', null];
        yield 'any version' => [Precondition::anyVersion(), '3', null];
        yield 'a list that holds the stored version' => [Precondition::listedVersions(['4']), '3', null];
        yield 'a list without the stored version' => [
            Precondition::listedVersions(['1', '3']),
            '3',
            VersionMismatchException::class,
        ];
        yield 'a record that is gone, without precondition' => [null, '9', ResourceNotFoundException::class];
        yield 'a record that is gone, under any version' => [
            Precondition::anyVersion(),
            '9',
            ResourceNotFoundException::class,
        ];
        yield 'a record that is gone, under a list' => [
            Precondition::listedVersions(['4']),
            '9',
            ResourceNotFoundException::class,
        ];
    }

    public function testADeleterThrowsTheConstraintItWouldBreak(): void
    {
        try {
            self::store()->delete(self::delete('2', null));
            self::fail('the deletion was expected to fail');
        } catch (ConstraintViolationException $thrown) {
            self::assertSame([PetStore::PET_IN_USE, []], [$thrown->constraint, $thrown->fields]);
        }
    }

    /**
     * pet 3, at version 4, names pet 2 as a littermate
     */
    private static function store(): PetStore
    {
        return new PetStore([new StoredPet('2', '1', 'two'), new StoredPet('3', '4', 'three', ['2'])]);
    }

    private static function delete(string $id, ?Precondition $precondition): DeleteResource
    {
        return new DeleteResource(
            new Identifier(PetStore::TYPE, $id),
            $precondition,
            new LanguagePreferences([]),
            new Values([]),
        );
    }
}
