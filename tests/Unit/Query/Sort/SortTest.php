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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Sort;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Sort\SortField;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(Sort::class)]
final class SortTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Sort::class))->isReadOnly());
    }

    public function testKeepsTheFieldsInTheOrderOfTheRequest(): void
    {
        $created = new SortField(new FieldPath(['created']), Direction::Descending);
        $name = new SortField(new FieldPath(['name']));

        self::assertSame([$created, $name], (new Sort([$created, $name]))->fields());
    }

    /**
     * parameter names are public API: a caller may pass them as named arguments
     *
     * @param list<array{string, string}> $parameters name and type of each parameter, in order
     */
    #[DataProvider('signatures')]
    public function testTheReadersDeclareTheirPublicSignatures(
        string $method,
        bool $static,
        string $returnType,
        array $parameters,
    ): void {
        $reflection = new ReflectionMethod(Sort::class, $method);

        self::assertSame($static, $reflection->isStatic());
        self::assertSame($returnType, (string) $reflection->getReturnType());
        self::assertSame($parameters, array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            $reflection->getParameters(),
        ));
    }

    /**
     * @return array<string, array{string, bool, string, list<array{string, string}>}>
     */
    public static function signatures(): array
    {
        return [
            'the reader' => ['fromString', true, Sort::class, [['sort', 'string']]],
            'the sort by id' => ['byId', true, Sort::class, []],
            'the total order' => ['withId', false, Sort::class, []],
        ];
    }

    public function testTheSortByIdIsIdAscendingAlone(): void
    {
        $sort = Sort::byId();

        self::assertSame('id', $sort->toString());
        self::assertTrue($sort->hasField(FieldPath::id()));
        self::assertSame(Direction::Ascending, $sort->fields()[0]->direction);
    }

    #[DataProvider('totalOrders')]
    public function testTheTotalOrderAppendsIdAscendingWhereTheSortDoesNotOrderById(
        string $sort,
        string $expected,
    ): void {
        $given = $sort === '' ? new Sort([]) : Sort::fromString($sort);
        $total = $given->withId();

        self::assertSame($expected, $total->toString());

        if ($given->hasField(FieldPath::id())) {
            self::assertSame($given, $total);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function totalOrders(): iterable
    {
        yield 'the empty sort' => ['', 'id'];
        yield 'a sort without id' => ['-created,name', '-created,name,id'];
        yield 'a sort with id last' => ['name,id', 'name,id'];
        yield 'a sort with id descending first' => ['-id,name', '-id,name'];
        yield 'the id of a related resource, which is not the id' => ['owner.id', 'owner.id,id'];
    }

    /**
     * the spec's example, `sort=-created,title`
     */
    public function testWritesItselfAsTheClientWroteIt(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['title'])),
        ]);

        self::assertSame('-created,title', $sort->toString());
        self::assertSame('-created,title', (string) $sort);
    }

    public function testTheEmptySortOrdersByNothingAndWritesNothing(): void
    {
        $sort = new Sort([]);

        self::assertTrue($sort->isEmpty());
        self::assertSame([], $sort->fields());
        self::assertSame('', $sort->toString());
    }

    #[DataProvider('repeats')]
    public function testRefusesAFieldItAlreadyOrdersBy(SortField $first, SortField $second): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is sorted by twice in one sort');

        new Sort([$first, $second]);
    }

    /**
     * @return iterable<string, array{SortField, SortField}>
     */
    public static function repeats(): iterable
    {
        yield 'the same field twice' => [
            new SortField(new FieldPath(['name'])),
            new SortField(new FieldPath(['name'])),
        ];
        yield 'the same field in both directions' => [
            new SortField(new FieldPath(['name'])),
            new SortField(new FieldPath(['name']), Direction::Descending),
        ];
        yield 'the same path through a relationship' => [
            new SortField(new FieldPath(['owner', 'name'])),
            new SortField(new FieldPath(['owner', 'name']), Direction::Descending),
        ];
    }

    public function testTwoFieldsThatDifferInCaseAreTwoFields(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['name'])),
            new SortField(new FieldPath(['Name'])),
        ]);

        self::assertCount(2, $sort->fields());
    }

    #[DataProvider('membership')]
    public function testTellsWhetherItAlreadyOrdersByAField(FieldPath $field, bool $expected): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['owner', 'name'])),
        ]);

        self::assertSame($expected, $sort->hasField($field));
    }

    /**
     * @return iterable<string, array{FieldPath, bool}>
     */
    public static function membership(): iterable
    {
        yield 'a field it orders by' => [new FieldPath(['created']), true];
        yield 'a path it orders by' => [new FieldPath(['owner', 'name']), true];
        yield 'a field it does not order by' => [new FieldPath(['name']), false];
        yield 'a prefix of a path it orders by' => [new FieldPath(['owner']), false];
    }

    public function testReturnsACopyThatOrdersByOneMoreFieldLast(): void
    {
        $sort = new Sort([new SortField(new FieldPath(['created']), Direction::Descending)]);

        $total = $sort->withField(new SortField(new FieldPath(['id'])));

        self::assertSame('-created,id', $total->toString());
        self::assertSame('-created', $sort->toString());
    }

    public function testRefusesToAppendAFieldItAlreadyOrdersBy(): void
    {
        $sort = new Sort([new SortField(new FieldPath(['id']))]);

        self::assertTrue($sort->hasField(new FieldPath(['id'])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is sorted by twice in one sort');

        $sort->withField(new SortField(new FieldPath(['id']), Direction::Descending));
    }

    public function testReversesEveryFieldAndKeepsTheOrderOfTheFields(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['created']), Direction::Descending),
            new SortField(new FieldPath(['name'])),
        ]);

        self::assertSame('created,-name', $sort->reverse()->toString());
        self::assertSame($sort->toString(), $sort->reverse()->reverse()->toString());
    }

    public function testReversingTheEmptySortGivesTheEmptySort(): void
    {
        self::assertTrue((new Sort([]))->reverse()->isEmpty());
    }

    /**
     * @param list<string> $fields each field as `SortField` writes it, in order
     */
    #[DataProvider('texts')]
    public function testReadsTheTextOfASortInOrder(string $text, array $fields): void
    {
        self::assertSame(
            $fields,
            array_map(static function (SortField $field): string {
                return $field->toString();
            }, Sort::fromString($text)->fields()),
        );
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function texts(): iterable
    {
        yield 'the example of the specification' => ['-created,title', ['-created', 'title']];
        yield 'one field' => ['age', ['age']];
        yield 'id alone, a sort path of every type' => ['id', ['id']];
        yield 'two fields' => ['age,name', ['age', 'name']];
        yield 'a path and id' => ['-owner.name,id', ['-owner.name', 'id']];
        yield 'three fields in the order written' => ['name,-created,id', ['name', '-created', 'id']];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('texts')]
    public function testWritesBackTheTextItRead(string $text, array $fields): void
    {
        self::assertSame($text, Sort::fromString($text)->toString());
    }

    public function testReadsBackWhatItWrote(): void
    {
        $sort = new Sort([
            new SortField(new FieldPath(['owner', 'name']), Direction::Descending),
            new SortField(new FieldPath(['name'])),
            new SortField(new FieldPath(['id'])),
        ]);

        self::assertEquals($sort, Sort::fromString($sort->toString()));
    }

    #[DataProvider('malformedTexts')]
    public function testRefusesATextWithAFieldThatIsNoSortField(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no field path');

        Sort::fromString($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTexts(): iterable
    {
        yield 'the empty text, which represents no sort field' => [''];
        yield 'a comma alone' => [','];
        yield 'an empty field between two' => ['name,,created'];
        yield 'a trailing comma' => ['name,'];
        yield 'a leading comma' => [',name'];
        yield 'a space behind the comma' => ['name, created'];
        yield 'a malformed field behind a valid one' => ['name,--created'];
        yield 'the bracketed syntax of Strapi' => ['sort[0]=name:asc'];
    }

    #[DataProvider('repeatedTexts')]
    public function testRefusesATextThatSortsByAFieldTwice(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is sorted by twice in one sort');

        Sort::fromString($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function repeatedTexts(): iterable
    {
        yield 'the same field twice' => ['name,name'];
        yield 'the same field in both directions' => ['name,-name'];
        yield 'id twice' => ['-id,id'];
    }
}
