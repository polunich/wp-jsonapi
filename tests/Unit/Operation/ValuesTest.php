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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use InvalidArgumentException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\Values;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Values::class)]
final class ValuesTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Values::class))->isReadOnly());
    }

    public function testReturnsTheValueOfAMemberTheRequestCarries(): void
    {
        $values = new Values(['name' => 'Buddy', 'rating' => 4]);

        self::assertTrue($values->has('name'));
        self::assertSame('Buddy', $values->get('name'));
        self::assertSame(4, $values->get('rating'));
    }

    public function testAMemberSetToNullIsPresent(): void
    {
        $values = new Values(['name' => 'Buddy', 'description' => null]);

        self::assertTrue($values->has('description'));
        self::assertNull($values->get('description'));
    }

    public function testAMemberLeftOutIsAbsent(): void
    {
        $values = new Values(['name' => 'Buddy']);

        self::assertFalse($values->has('description'));
    }

    public function testRefusesToReturnAValueForAMemberLeftOut(): void
    {
        $values = new Values(['name' => 'Buddy']);

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('the request carries no "description", which is absent, not null');

        $values->get('description');
    }

    public function testEscapesAControlCharacterOfTheNameInTheMessage(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('the request carries no "descrip\ntion", which is absent, not null; ask has()'
            . ' first');

        (new Values([]))->get("descrip\ntion");
    }

    public function testNamesTheMembersInTheOrderOfTheRequest(): void
    {
        $values = new Values(['name' => 'x', 'description' => null, 'rating' => 4]);

        self::assertSame(['name', 'description', 'rating'], $values->names());
    }

    public function testAMemberNameOfDigitsIsReadBackAsAString(): void
    {
        $values = new Values(['0' => 'zero', '123' => 'many', 'name' => 'x']);

        self::assertSame(['0', '123', 'name'], $values->names());
        self::assertTrue($values->has('0'));
        self::assertSame('zero', $values->get('0'));
        self::assertSame('many', $values->get('123'));
    }

    public function testARequestMayCarryNoMember(): void
    {
        $values = new Values([]);

        self::assertSame([], $values->names());
        self::assertFalse($values->has('name'));
    }

    public function testRefusesAnEmptyMemberName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a member name is never empty');

        new Values(['name' => 'x', '' => 'nameless']);
    }
}
