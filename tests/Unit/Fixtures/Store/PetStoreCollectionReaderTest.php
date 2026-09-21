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
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStoreCases;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;

/**
 * `PetStore` as a `CollectionReader`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreCollectionReaderTest extends TestCase
{
    /**
     * hidden pet 3 is left out before the window applies; counted, it would stand second in it
     */
    public function testAReaderReturnsTheWindowOfTheVisibleRecords(): void
    {
        $store = self::store();

        $slice = $store->read(self::query(new Window(1, 2), totalRequested: true));

        self::assertSame(['2', '4'], PetStoreCases::ids($slice->records));
        self::assertSame(4, $slice->total);
        self::assertNull($slice->estimatedTotal);
        self::assertNull($store->read(self::query(new Window(0, 10), totalRequested: false))->total);
    }

    public function testARelationshipReadReturnsTheLinkageTheRelatedLoaderReturns(): void
    {
        $store = self::store();
        $sort = new Sort([new SortField(new FieldPath(['id']), Direction::Descending)]);

        $page = $store->read(new CollectionQuery(
            type: PetStore::TYPE,
            filter: null,
            sort: $sort,
            window: new Window(0, 3),
            totalRequested: false,
            fields: new FieldHint([], []),
            languages: new LanguagePreferences([]),
            parent: new ParentScope(PetStore::TYPE, '1', PetStore::RELATIONSHIP),
            queryParameters: new Values([]),
        ));
        $linkage = $store->loadRelated(new RelatedLoadRequest(
            PetStore::TYPE,
            ['1'],
            PetStore::RELATIONSHIP,
            $sort,
            3,
            new FieldHint([], []),
            new LanguagePreferences([]),
            new Values([]),
        ));

        self::assertSame(['5', '2'], PetStoreCases::ids($page->records));
        self::assertSame([PetStoreCases::ids($page->records)], array_map(PetStoreCases::ids(...), $linkage));
    }

    /**
     * pet 3 is hidden; pet 1 names 5, 3 and 2 as littermates
     */
    private static function store(): PetStore
    {
        return new PetStore([
            new StoredPet('5', '1', 'five'),
            new StoredPet('1', '1', 'one', ['5', '3', '2']),
            new StoredPet('3', '1', 'three', [], false),
            new StoredPet('4', '1', 'four'),
            new StoredPet('2', '1', 'two'),
        ]);
    }

    private static function query(Window $window, bool $totalRequested): CollectionQuery
    {
        return new CollectionQuery(
            PetStore::TYPE,
            null,
            new Sort([new SortField(new FieldPath(['id']))]),
            $window,
            $totalRequested,
            new FieldHint(null),
            new LanguagePreferences([]),
            null,
            new Values([]),
        );
    }
}
