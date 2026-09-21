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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Filter\Operators;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(Operators::class)]
final class OperatorsTest extends TestCase
{
    /**
     * a custom operator never lives here, so nothing may be registered:
     * the class stays a static table
     */
    public function testThePublicApiIsStaticAndHoldsNoOperatorsOfAProject(): void
    {
        $class = new ReflectionClass(Operators::class);
        $methods = $class->getMethods(ReflectionMethod::IS_PUBLIC);
        $names = array_map(static function (ReflectionMethod $method): string {
            return $method->getName();
        }, $methods);

        sort($names);

        self::assertSame(['isBuiltIn', 'isName', 'names', 'operandShape'], $names);

        foreach ($methods as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' is not static');
        }

        self::assertSame([], $class->getProperties());
        self::assertNull($class->getConstructor());
    }

    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Operators::class))->isReadOnly());
    }

    public function testKnowsTheTwentyOneComparisonOperatorsOfStrapiInItsOrder(): void
    {
        self::assertSame(
            [
                '@eq', '@eqi', '@ne', '@nei', '@lt', '@lte', '@gt', '@gte', '@in', '@notIn',
                '@contains', '@notContains', '@containsi', '@notContainsi', '@null', '@notNull',
                '@between', '@startsWith', '@startsWithi', '@endsWith', '@endsWithi',
            ],
            Operators::names(),
        );
        self::assertCount(21, Operators::names());
    }

    public function testTheOperandShapesAreScalarTextListBooleanAndPair(): void
    {
        self::assertSame(
            ['scalar', 'text', 'list', 'boolean', 'pair'],
            [
                Operators::OPERAND_SCALAR,
                Operators::OPERAND_TEXT,
                Operators::OPERAND_LIST,
                Operators::OPERAND_BOOLEAN,
                Operators::OPERAND_PAIR,
            ],
        );
    }

    #[DataProvider('operandShapes')]
    public function testReturnsTheOperandShapeOfTheOperator(string $operator, string $operandShape): void
    {
        self::assertSame($operandShape, Operators::operandShape($operator));
    }

    /**
     * a built-in operator added without a case in `operandShapes()` fails here
     */
    public function testTheOperandShapesAboveNameEveryBuiltInOperator(): void
    {
        self::assertSame(Operators::names(), array_keys(iterator_to_array(self::operandShapes())));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operandShapes(): iterable
    {
        yield '@eq' => ['@eq', Operators::OPERAND_SCALAR];
        yield '@eqi' => ['@eqi', Operators::OPERAND_TEXT];
        yield '@ne' => ['@ne', Operators::OPERAND_SCALAR];
        yield '@nei' => ['@nei', Operators::OPERAND_TEXT];
        yield '@lt' => ['@lt', Operators::OPERAND_SCALAR];
        yield '@lte' => ['@lte', Operators::OPERAND_SCALAR];
        yield '@gt' => ['@gt', Operators::OPERAND_SCALAR];
        yield '@gte' => ['@gte', Operators::OPERAND_SCALAR];
        yield '@in' => ['@in', Operators::OPERAND_LIST];
        yield '@notIn' => ['@notIn', Operators::OPERAND_LIST];
        yield '@contains' => ['@contains', Operators::OPERAND_TEXT];
        yield '@notContains' => ['@notContains', Operators::OPERAND_TEXT];
        yield '@containsi' => ['@containsi', Operators::OPERAND_TEXT];
        yield '@notContainsi' => ['@notContainsi', Operators::OPERAND_TEXT];
        yield '@null' => ['@null', Operators::OPERAND_BOOLEAN];
        yield '@notNull' => ['@notNull', Operators::OPERAND_BOOLEAN];
        yield '@between' => ['@between', Operators::OPERAND_PAIR];
        yield '@startsWith' => ['@startsWith', Operators::OPERAND_TEXT];
        yield '@startsWithi' => ['@startsWithi', Operators::OPERAND_TEXT];
        yield '@endsWith' => ['@endsWith', Operators::OPERAND_TEXT];
        yield '@endsWithi' => ['@endsWithi', Operators::OPERAND_TEXT];
    }

    #[DataProvider('namesWithoutOperandShape')]
    public function testRefusesToReturnAnOperandShapeForAnOperatorThatIsNotBuiltIn(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Operators::operandShape($name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesWithoutOperandShape(): iterable
    {
        yield 'a custom operator' => ['@near', 'no built-in operator is named "@near"'];
        yield 'a built-in operator in another case' => ['@EQ', 'no built-in operator is named "@EQ"'];
        yield 'a logical operator, which is a node' => ['@and', 'no built-in operator is named "@and"'];
        yield 'a name with a line feed, written as an escape' => ["@eq\n", 'no built-in operator is named "@eq\n"'];
    }

    #[DataProvider('names')]
    public function testTellsWhetherAnOperatorIsOneOfTheBuiltInSet(string $name, bool $expected): void
    {
        self::assertSame($expected, Operators::isBuiltIn($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function names(): iterable
    {
        yield 'a built-in operator' => ['@eq', true];
        yield 'a text operator' => ['@endsWithi', true];
        yield 'another case' => ['@EQ', false];
        yield 'without the prefix' => ['eq', false];
        yield 'a logical operator, which is a node' => ['@and', false];
        yield 'a custom operator' => ['@near', false];
        yield 'a field' => ['city', false];
    }

    #[DataProvider('shapes')]
    public function testTellsWhetherAStringCanNameAnOperatorAtAll(string $name, bool $expected): void
    {
        self::assertSame($expected, Operators::isName($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function shapes(): iterable
    {
        yield 'a built-in operator' => ['@eq', true];
        yield 'a custom operator' => ['@near', true];
        yield 'a custom operator of two words' => ['@within radius', true];
        yield 'empty' => ['', false];
        yield 'the prefix alone' => ['@', false];
        // the whole name must be a member name, and `@` may only lead one
        yield 'the prefix twice' => ['@@eq', false];
        yield 'without the prefix' => ['eq', false];
        yield 'the prefix in the middle' => ['e@q', false];
        yield 'a reserved character' => ['@eq.i', false];
        yield 'a dollar sign' => ['@ne$ar', false];
        yield 'a hyphen last' => ['@eq-', false];
        yield 'a space last' => ['@near ', false];
    }
}
