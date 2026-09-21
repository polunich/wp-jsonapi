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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AllOf::class)]
final class AllOfTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(AllOf::class))->isReadOnly());
    }

    public function testKeepsTheChildrenInTheOrderOfTheRequest(): void
    {
        $first = new Condition(new FieldPath(['city']), '@eq', 'Kyiv');
        $second = new Condition(new FieldPath(['age']), '@gt', 18);

        self::assertSame([$first, $second], (new AllOf([$first, $second]))->nodes);
    }

    public function testRefusesAConjunctionOfNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"@and" holds at least one condition');

        new AllOf([]);
    }

    public function testNestsAnotherNodeOfTheTree(): void
    {
        $inner = new AllOf([new Condition(new FieldPath(['age']), '@gt', 18)]);
        $outer = new AllOf([$inner]);

        self::assertSame([$inner], $outer->nodes);
    }
}
