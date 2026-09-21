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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Pagination;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Cursor::class)]
final class CursorTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Cursor::class))->isReadOnly());
    }

    public function testCarriesTheSortValuesOfTheBoundaryRecordAndItsId(): void
    {
        $cursor = new Cursor(['created' => '2026-01-01', 'name' => 'a'], '42');

        self::assertSame(['created' => '2026-01-01', 'name' => 'a'], $cursor->sortValues);
        self::assertSame('42', $cursor->id);
    }

    public function testTheClientThatOrderedByNothingLeavesTheIdAlone(): void
    {
        $cursor = new Cursor([], '42');

        self::assertSame([], $cursor->sortValues);
        self::assertSame('42', $cursor->id);
    }

    #[DataProvider('values')]
    public function testCarriesEveryScalarASortFieldCanHold(bool|float|int|string $value): void
    {
        self::assertSame(['field' => $value], (new Cursor(['field' => $value], '1'))->sortValues);
    }

    /**
     * @return iterable<string, array{bool|float|int|string}>
     */
    public static function values(): iterable
    {
        yield 'a string' => ['Ada'];
        yield 'the empty string' => [''];
        yield 'an integer' => [7];
        yield 'zero' => [0];
        yield 'a number' => [1.5];
        yield 'a boolean' => [true];
    }

    public function testASortFieldOfDigitsIsKeptUnderTheKeyPhpGivesIt(): void
    {
        $cursor = new Cursor(['0' => 7], '42');

        self::assertSame([0 => 7], $cursor->sortValues);
    }

    public function testKeysASortValueOfARelatedResourceByItsPath(): void
    {
        $cursor = new Cursor(['owner.supplier.name' => 'Ada'], '42');

        self::assertSame(['owner.supplier.name' => 'Ada'], $cursor->sortValues);
    }

    #[DataProvider('illegalKeys')]
    public function testRefusesASortValueKeyedByNoFieldPath(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keyed by the field path it belongs to');

        new Cursor([$key => 'a'], '42');
    }

    public function testEscapesAControlCharacterOfARefusedKeyInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a sort value of a cursor is keyed by the field path it belongs to, and'
            . ' "na\nme" is none');

        new Cursor(["na\nme" => 'a'], '42');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalKeys(): iterable
    {
        yield 'nothing' => [''];
        yield 'a reserved character' => ['not a field path!!'];
        yield 'two separators together' => ['owner..name'];
        yield 'a separator first' => ['.name'];
        yield 'a separator last' => ['owner.'];
        yield 'a space last' => ['name '];
    }

    #[DataProvider('unorderedValues')]
    public function testRefusesASortValueThatIsNoFiniteNumber(float $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a finite number');

        new Cursor(['field' => $value], '42');
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function unorderedValues(): iterable
    {
        yield 'not a number' => [NAN];
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    public function testRefusesACursorThatStandsAtNoRecord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('which is never empty');

        new Cursor(['created' => '2026-01-01'], '');
    }
}
