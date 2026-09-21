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
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;

/**
 * `PetStore` as a `ResourceLoader`: the fixture keeps the documented contract of the port,
 * which the tests of the library lean on.
 * `CoversNothing`: the fixture is no target of code coverage
 *
 * @internal
 */
#[CoversNothing]
final class PetStoreResourceLoaderTest extends TestCase
{
    /**
     * nonexistent pet 9 and hidden pet 3 are both returned as null at their position
     */
    public function testALoaderReturnsAResultForEachIdAtItsPosition(): void
    {
        $two = new StoredPet('2', '1', 'two');
        $five = new StoredPet('5', '1', 'five');
        $store = new PetStore([$five, new StoredPet('3', '1', 'three', [], false), $two]);

        $records = $store->load(new LoadRequest(
            PetStore::TYPE,
            ['2', '9', '3', '5'],
            new FieldHint(null),
            new LanguagePreferences([]),
            new Values([]),
        ));

        self::assertSame([$two, null, null, $five], $records);
    }
}
