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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(FetchRelationship::class)]
final class FetchRelationshipTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FetchRelationship::class))->isReadOnly());
    }

    public function testCarriesTheParentTheRelationshipThePageAndTheShapeOfTheResponse(): void
    {
        $parent = new Identifier('pets', '1');
        $page = PageRequest::pageNumber(2, 10);
        $include = new IncludePaths([new IncludePath(['orders', 'customer'])]);
        $fields = new SparseFieldsets(['orders' => ['address'], 'customers' => ['name']]);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $operation = new FetchRelationship(
            parent: $parent,
            relationship: 'orders',
            filter: null,
            page: $page,
            include: $include,
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame($parent, $operation->parent);
        self::assertSame('orders', $operation->relationship);
        self::assertSame($page, $operation->page);
        self::assertSame($include, $operation->include);
        self::assertSame($fields, $operation->fields);
        self::assertSame($languages, $operation->languages);
    }

    public function testTakesTheConditionsOfItsCursorsThePageTheIncludePathsAndTheFieldsetsAndNoSort(): void
    {
        $parameters = array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            (new ReflectionMethod(FetchRelationship::class, '__construct'))->getParameters(),
        );

        self::assertSame(
            [
                ['parent', Identifier::class],
                ['relationship', 'string'],
                ['filter', '?' . FilterNode::class],
                ['page', PageRequest::class],
                ['include', '?' . IncludePaths::class],
                ['fields', SparseFieldsets::class],
                ['languages', LanguagePreferences::class],
                ['queryParameters', Values::class],
            ],
            $parameters,
        );
        self::assertFalse(property_exists(FetchRelationship::class, 'sort'));
    }

    public function testCarriesTheConditionsOfTheCursorsOfItsPage(): void
    {
        $conditions = self::conditions();

        $operation = self::operation(
            new Identifier('pets', '42'),
            null,
            $conditions,
            PageRequest::cursor(after: new Cursor([], '7')),
        );

        self::assertSame($conditions, $operation->filter);
    }

    /**
     * a cursor-strategy page without `page[after]` or `page[before]` carries no cursor either
     */
    public function testCarriesNoFilterWhereThePageCarriesNoCursor(): void
    {
        self::assertNull(self::operation(new Identifier('pets', '42'), null)->filter);
        self::assertNull(
            self::operation(new Identifier('pets', '42'), null, null, PageRequest::cursor(size: 5))->filter,
        );
    }

    public function testRefusesAFilterWhereThePageCarriesNoCursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the filter of relationship "tags" of a parent of type "pets" carries the conditions of the cursors'
            . ' of its page, the one filter that route reads, and this page carries no cursor',
        );

        self::operation(new Identifier('pets', '42'), null, self::conditions());
    }

    #[DataProvider('pagesWithACursor')]
    public function testRefusesACursorWhoseConditionsAreMissing(PageRequest $page): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the page of relationship "tags" of a parent of type "pets" carries a cursor, whose conditions are'
            . ' the filter this linkage is read with, and this operation carries none',
        );

        self::operation(new Identifier('pets', '42'), null, null, $page);
    }

    /**
     * @return iterable<string, array{PageRequest}>
     */
    public static function pagesWithACursor(): iterable
    {
        yield 'page[after]' => [PageRequest::cursor(after: new Cursor([], '7'))];
        yield 'page[before]' => [PageRequest::cursor(before: new Cursor([], '7'))];
        yield 'a range' => [PageRequest::cursor(new Cursor([], '1'), new Cursor([], '7'))];
    }

    public function testIsTheFetchRelationshipOperationOfTheTypeOfItsParent(): void
    {
        $operation = self::operation(new Identifier('pets', '42'), null);

        self::assertSame(OperationKind::FetchRelationship, $operation->kind());
        self::assertSame('pets', $operation->type);
        self::assertSame('pets', $operation->type());
        self::assertSame('tags', $operation->relationship());
    }

    public function testAnAbsentIncludeParameterIsNoEmptyOne(): void
    {
        $empty = new IncludePaths([]);

        self::assertNull(self::operation(new Identifier('pets', '42'), null)->include);
        self::assertSame($empty, self::operation(new Identifier('pets', '42'), $empty)->include);
    }

    /**
     * the spec's own example: `comments.author` on `/articles/1/relationships/comments`
     */
    #[DataProvider('pathsFromTheRelationship')]
    public function testTakesIncludePathsThatStartWithTheRelationship(IncludePaths $include): void
    {
        $operation = new FetchRelationship(
            new Identifier('articles', '1'),
            'comments',
            null,
            PageRequest::pageNumber(),
            $include,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );

        self::assertSame($include, $operation->include);
    }

    /**
     * @return iterable<string, array{IncludePaths}>
     */
    public static function pathsFromTheRelationship(): iterable
    {
        yield 'the relationship itself' => [new IncludePaths([new IncludePath(['comments'])])];
        yield 'the example of the specification' => [new IncludePaths([new IncludePath(['comments', 'author'])])];
        yield 'several paths' => [
            new IncludePaths([new IncludePath(['comments']), new IncludePath(['comments', 'author', 'company'])]),
        ];
        yield 'the empty include' => [new IncludePaths([])];
    }

    /**
     * the first segment must equal the relationship as a string:
     * a longer name, another case or an equal number is another relationship
     */
    #[DataProvider('pathsElsewhere')]
    public function testRefusesAnIncludePathThatDoesNotStartWithTheRelationship(
        string $relationship,
        IncludePaths $include,
        string $path,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'include path "' . $path . '" does not start with relationship "' . $relationship
            . '" of a parent of type "pets", whose linkage is the primary data every included resource is'
            . ' reached from',
        );

        new FetchRelationship(
            new Identifier('pets', '1'),
            $relationship,
            null,
            PageRequest::pageNumber(),
            $include,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @return iterable<string, array{string, IncludePaths, string}>
     */
    public static function pathsElsewhere(): iterable
    {
        yield 'another relationship of the parent' => [
            'orders',
            new IncludePaths([new IncludePath(['owner'])]),
            'owner',
        ];
        yield 'a path written from the related type' => [
            'orders',
            new IncludePaths([new IncludePath(['customer', 'supplier'])]),
            'customer.supplier',
        ];
        yield 'the relationship behind another one' => [
            'orders',
            new IncludePaths([new IncludePath(['owner', 'orders'])]),
            'owner.orders',
        ];
        yield 'a second path' => [
            'orders',
            new IncludePaths([new IncludePath(['orders', 'customer']), new IncludePath(['tags'])]),
            'tags',
        ];
        yield 'a name that begins with the relationship' => [
            'orders',
            new IncludePaths([new IncludePath(['orders-archived'])]),
            'orders-archived',
        ];
        yield 'the relationship in another case' => [
            'orders',
            new IncludePaths([new IncludePath(['Orders'])]),
            'Orders',
        ];
        yield 'a name equal as a number' => ['10', new IncludePaths([new IncludePath(['1e1'])]), '1e1'];
    }

    public function testRefusesAnEmptyRelationshipName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the linkage of a parent of type "pets" is read through a relationship, whose'
            . ' name is never empty');

        new FetchRelationship(
            new Identifier('pets', '42'),
            '',
            null,
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfEveryNameItPutsIntoAMessage(Closure $construct, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $construct();
    }

    /**
     * @return iterable<string, array{Closure(): FetchRelationship, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of the parent of an empty relationship name' => [
            static function (): FetchRelationship {
                return new FetchRelationship(
                    new Identifier("pe\nts", '1'),
                    '',
                    null,
                    PageRequest::pageNumber(),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'the linkage of a parent of type "pe\nts" is read through a relationship, whose name is never empty',
        ];
        yield 'the relationship and the type of the parent of an include path that starts elsewhere' => [
            static function (): FetchRelationship {
                return new FetchRelationship(
                    new Identifier("pe\x7Fts", '1'),
                    "or\0ders",
                    null,
                    PageRequest::pageNumber(),
                    new IncludePaths([new IncludePath(['owner'])]),
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'include path "owner" does not start with relationship "or\000ders" of a parent of type "pe\177ts", whose'
            . ' linkage is the primary data every included resource is reached from',
        ];
        yield 'the relationship and the type of the parent of a filter whose page carries no cursor' => [
            static function (): FetchRelationship {
                return new FetchRelationship(
                    new Identifier("pe\x7Fts", '1'),
                    "or\0ders",
                    self::conditions(),
                    PageRequest::pageNumber(),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'the filter of relationship "or\000ders" of a parent of type "pe\177ts" carries the conditions of the'
            . ' cursors of its page, the one filter that route reads, and this page carries no cursor',
        ];
        yield 'the relationship and the type of the parent of a cursor whose conditions are missing' => [
            static function (): FetchRelationship {
                return new FetchRelationship(
                    new Identifier("pe\nts", '1'),
                    "or\tders",
                    null,
                    PageRequest::cursor(after: new Cursor([], '7')),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
            'the page of relationship "or\tders" of a parent of type "pe\nts" carries a cursor, whose conditions are'
            . ' the filter this linkage is read with, and this operation carries none',
        ];
    }

    private static function operation(
        Identifier $parent,
        ?IncludePaths $include,
        ?FilterNode $filter = null,
        ?PageRequest $page = null,
    ): FetchRelationship {
        return new FetchRelationship(
            parent: $parent,
            relationship: 'tags',
            filter: $filter,
            page: $page ?? PageRequest::pageNumber(1, 10),
            include: $include,
            fields: new SparseFieldsets(['tags' => ['name']]),
            languages: new LanguagePreferences([]),
            queryParameters: new Values([]),
        );
    }

    /**
     * the condition a cursor at id `7` adds under the sort by `id` alone
     */
    private static function conditions(): FilterNode
    {
        return new Condition(new FieldPath(['id']), '@gt', '7');
    }
}
