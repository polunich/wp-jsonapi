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
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;

/**
 * `PetStore` as a `RelatedLoader`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreRelatedLoaderTest extends TestCase
{
    /**
     * hidden pet 3 is left out before the limit of 2 applies
     */
    public function testALoaderReturnsTheRelatedRecordsOfEachParentAtItsPosition(): void
    {
        $store = new PetStore([
            new StoredPet('1', '1', 'one', ['4', '2', '3', '5']),
            new StoredPet('2', '1', 'two', ['1']),
            new StoredPet('3', '1', 'three', [], false),
            new StoredPet('4', '1', 'four'),
            new StoredPet('5', '1', 'five'),
            new StoredPet('6', '1', 'six'),
        ]);

        $related = $store->loadRelated(new RelatedLoadRequest(
            PetStore::TYPE,
            ['6', '1', '2'],
            PetStore::RELATIONSHIP,
            new Sort([new SortField(new FieldPath(['id']))]),
            2,
            new FieldHint([], []),
            new LanguagePreferences([]),
            new Values([]),
        ));

        self::assertSame(
            [[], ['2', '4'], ['1']],
            array_map(
                static function (array $records): array {
                    return array_map(static function (StoredPet $pet): string {
                        return $pet->id;
                    }, $records);
                },
                $related,
            ),
        );
    }
}
