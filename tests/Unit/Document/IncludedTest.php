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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\Included;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\ResourceObject;

/**
 * @internal
 */
#[CoversClass(Included::class)]
final class IncludedTest extends TestCase
{
    public function testAnInstanceWithoutResourcesCarriesNone(): void
    {
        self::assertSame([], (new Included())->all());
    }

    public function testTheInsertionOrderIsKept(): void
    {
        $first = self::resource('orders', '5');
        $second = self::resource('customers', '9');

        self::assertSame([$first, $second], (new Included([$first, $second]))->all());
    }

    public function testTheSameTypeAndIdAppearsOnce(): void
    {
        $first = self::resource('customers', '9');
        $again = self::resource('customers', '9');
        $other = self::resource('orders', '5');

        $included = new Included([$first, $other, $again]);

        self::assertSame([$first, $other], $included->all());
    }

    public function testTheIdAloneDoesNotIdentifyAResource(): void
    {
        $order = self::resource('orders', '9');
        $customer = self::resource('customers', '9');

        self::assertSame([$order, $customer], (new Included([$order, $customer]))->all());
    }

    private static function resource(string $type, string $id): ResourceObject
    {
        return new ResourceObject($type, $id, new Links(['self' => sprintf('http://example.com/%s/%s', $type, $id)]));
    }
}
