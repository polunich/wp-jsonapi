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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(NoContentResult::class)]
final class NoContentResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(NoContentResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testAWriteThatReturnedNothingGets204(): void
    {
        self::assertSame(204, (new NoContentResult())->status());
    }

    public function testReturnsNoEntityTagByConstruction(): void
    {
        self::assertNull((new ReflectionClass(NoContentResult::class))->getConstructor());
        self::assertNull((new NoContentResult())->entityTag());
    }

    public function testTwoResultsAreTheSameResponse(): void
    {
        self::assertEquals(new NoContentResult(), new NoContentResult());
    }
}
