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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Http\EntityTag;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(NotModifiedResult::class)]
final class NotModifiedResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(NotModifiedResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    /**
     * @param ?string $tag `null` where a 200 would write none
     */
    #[DataProvider('representations')]
    public function testAConditionThatDidNotHoldGets304(?string $tag): void
    {
        $result = new NotModifiedResult($tag === null ? null : new EntityTag($tag));

        self::assertSame(304, $result->status());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function representations(): iterable
    {
        yield 'a representation with a tag' => ['a1b2'];
        yield 'a representation without one' => [null];
    }

    public function testReturnsTheEntityTagTheConditionWasEvaluatedAgainst(): void
    {
        $tag = new EntityTag('a1b2');

        self::assertSame($tag, (new NotModifiedResult($tag))->entityTag());
    }

    public function testReturnsNoEntityTagWhereA200WouldWriteNone(): void
    {
        self::assertNull((new NotModifiedResult(null))->entityTag());
    }
}
