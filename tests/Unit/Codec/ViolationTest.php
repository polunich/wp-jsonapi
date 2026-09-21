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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(Violation::class)]
final class ViolationTest extends TestCase
{
    public function testTheParametersDefaultToNone(): void
    {
        $violation = new Violation(Pointer::root(), 'invalid_type');

        self::assertSame([], $violation->parameters);
    }

    public function testTheMembersAreTheOnesGiven(): void
    {
        $pointer = Pointer::root()->descend('data');

        $violation = new Violation($pointer, 'value_too_long', ['maxLength' => 200]);

        self::assertSame($pointer, $violation->pointer);
        self::assertSame('value_too_long', $violation->code);
        self::assertSame(['maxLength' => 200], $violation->parameters);
    }
}
