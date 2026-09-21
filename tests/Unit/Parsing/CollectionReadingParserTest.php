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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Parsing\CollectionReadingParser;
use Polunich\WpJsonApi\Parsing\CursorConditions;
use Polunich\WpJsonApi\Parsing\FilterParser;
use Polunich\WpJsonApi\Parsing\PageParser;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\AnyOf;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CollectionReadingParser::class)]
final class CollectionReadingParserTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(CollectionReadingParser::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testARequestWithoutParametersReadsTheDefaultSortInTheDefaultStrategy(): void
    {
        $errors = self::errorList();

        $reading = self::parser()->parse(
            [],
            'pets',
            self::policy(),
            Sort::fromString('-name'),
            Strategy::Cursor,
            $errors,
        );

        self::assertSame('pets', $reading->type);
        self::assertNull($reading->filter);
        self::assertTrue($reading->sort->isEmpty());
        self::assertSame('-name,id', $reading->readingSort->toString());
        self::assertSame(Strategy::Cursor, $reading->page->strategy);
        self::assertNull($errors->exception());
    }

    public function testWithoutADefaultSortTheReadingRunsById(): void
    {
        $reading = self::parser()->parse([], 'pets', self::policy(), null, Strategy::PageNumber, self::errorList());

        self::assertSame('id', $reading->readingSort->toString());
    }

    public function testTheSortOfTheRequestIsKeptAsWrittenAndReadWithTheId(): void
    {
        $reading = self::parser()->parse(
            ['sort' => '-name'],
            'pets',
            self::policy(),
            Sort::fromString('name'),
            Strategy::PageNumber,
            self::errorList(),
        );

        self::assertSame('-name', $reading->sort->toString());
        self::assertSame('-name,id', $reading->readingSort->toString());
    }

    public function testThePolicyGivenBoundsTheSize(): void
    {
        $errors = self::errorList();

        self::parser()->parse(
            ['page' => ['size' => '6']],
            'pets',
            new PaginationPolicy(2, 5),
            null,
            Strategy::PageNumber,
            $errors,
        );

        self::assertSame([ErrorCode::MaxPageSizeExceeded], self::codes($errors));
    }

    public function testTheCursorsJoinTheFilterOfTheRequest(): void
    {
        $filter = new Condition(new FieldPath(['name']), '@eq', 'Rex');

        $reading = self::parser()->parse(
            [
                'filter' => ['name' => ['@eq' => 'Rex']],
                'page' => ['after' => CursorText::encode(new Cursor(['name' => 'a'], '7'))],
            ],
            'pets',
            self::policy(),
            Sort::fromString('name'),
            Strategy::PageNumber,
            self::errorList(),
        );

        self::assertEquals(new AllOf([$filter, self::after('a', '7')]), $reading->filter);
    }

    public function testTheCursorsAloneAreTheFilterOfARequestThatFiltersNothing(): void
    {
        $reading = self::parser()->parse(
            ['page' => ['after' => CursorText::encode(new Cursor(['name' => 'a'], '7'))]],
            'pets',
            self::policy(),
            Sort::fromString('name'),
            Strategy::PageNumber,
            self::errorList(),
        );

        self::assertEquals(self::after('a', '7'), $reading->filter);
    }

    public function testACursorIsNotReadOnceTheErrorListHoldsAMistake(): void
    {
        $errors = self::errorList();
        $errors->add(new BadRequestException([new ErrorDescription(ErrorCode::UnsupportedInclude)]));

        $reading = self::parser()->parse(
            ['page' => ['after' => CursorText::encode(new Cursor(['elsewhere' => 'a'], '7'))]],
            'pets',
            self::policy(),
            Sort::fromString('name'),
            Strategy::PageNumber,
            $errors,
        );

        self::assertNull($reading->filter);
        self::assertSame([ErrorCode::UnsupportedInclude], self::codes($errors));
    }

    public function testAPageWithoutACursorAddsNoConditions(): void
    {
        self::assertNull(self::parser()->conditions(PageRequest::cursor(), 'pets', Sort::byId(), self::errorList()));
    }

    private static function parser(): CollectionReadingParser
    {
        $resources = new ResourceRegistry([Samples::resource(
            'pets',
            [Samples::attribute('name', sortable: true, operators: ['@eq'])],
            capabilities: [Capability::CollectionReader],
        )]);
        $api = new ApiMetadata(
            'pets api',
            '1.0',
            'acme/v1',
            $resources,
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );

        return new CollectionReadingParser(
            new FilterParser($api),
            new SortParser($resources),
            new PageParser(NamingPolicy::SnakeCase),
            new CursorConditions($resources, NamingPolicy::SnakeCase),
        );
    }

    private static function policy(): PaginationPolicy
    {
        return PaginationPolicy::libraryDefault();
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    /**
     * the records after the cursor in the order `name,id`
     */
    private static function after(string $name, string $id): FilterNode
    {
        $path = new FieldPath(['name']);

        return new AnyOf([
            new Condition($path, '@gt', $name),
            new AllOf([new Condition($path, '@eq', $name), new Condition(new FieldPath(['id']), '@gt', $id)]),
        ]);
    }

    /**
     * @return list<ErrorCode|string>
     */
    private static function codes(ErrorList $errors): array
    {
        return array_map(static function (ErrorDescription $description): ErrorCode|string {
            return $description->code;
        }, $errors->exception()?->descriptions() ?? []);
    }
}
