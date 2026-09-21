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

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\Condition;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Condition::class)]
final class ConditionTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Condition::class))->isReadOnly());
    }

    public function testCarriesTheFieldTheOperatorAndTheDecodedValue(): void
    {
        $field = new FieldPath(['city']);
        $condition = new Condition($field, '@eq', 'Kyiv');

        self::assertSame($field, $condition->field);
        self::assertSame('@eq', $condition->operator);
        self::assertSame('Kyiv', $condition->value);
    }

    #[DataProvider('values')]
    public function testCarriesAValueOfEveryShapeAnOperatorTakes(string $operator, mixed $value): void
    {
        self::assertSame($value, (new Condition(new FieldPath(['field']), $operator, $value))->value);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function values(): iterable
    {
        yield 'a scalar' => ['@gt', 18];
        yield 'a text' => ['@containsi', 'kyi'];
        yield 'a list' => ['@in', ['Kyiv', 'Lviv']];
        yield 'a boolean' => ['@null', true];
        yield 'a pair' => ['@between', [1, 10]];
        yield 'a decoded object' => ['@eq', new DateTimeImmutable('2026-01-01T00:00:00Z')];
    }

    #[DataProvider('illegalOperators')]
    public function testRefusesAStringThatCanNameNoOperator(string $operator): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no operator name');

        new Condition(new FieldPath(['city']), $operator, 'Kyiv');
    }

    public function testEscapesAControlCharacterOfARefusedOperatorInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('operator "@e\nq" is no operator name: an operator is "@" and a member name');

        new Condition(new FieldPath(['city']), "@e\nq", 'Kyiv');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalOperators(): iterable
    {
        yield 'empty' => [''];
        yield 'without the prefix' => ['eq'];
        yield 'the prefix alone' => ['@'];
        yield 'a reserved character' => ['@e.q'];
        yield 'the prefix in the middle' => ['e@q'];
        yield 'a hyphen last' => ['@eq-'];
    }

    public function testTakesACustomOperatorOfTheProject(): void
    {
        $condition = new Condition(new FieldPath(['location']), '@near', [50.45, 30.52]);

        self::assertSame('@near', $condition->operator);
    }
}
