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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\Not;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Not::class)]
final class NotTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Not::class))->isReadOnly());
    }

    public function testCarriesTheNodeThatMustNotMatch(): void
    {
        $condition = new Condition(new FieldPath(['city']), '@eq', 'Kyiv');

        self::assertSame($condition, (new Not($condition))->node);
    }

    public function testNegatesAWholeSubtree(): void
    {
        $subtree = new AnyOf([new Condition(new FieldPath(['city']), '@eq', 'Kyiv')]);

        self::assertSame($subtree, (new Not($subtree))->node);
    }
}
