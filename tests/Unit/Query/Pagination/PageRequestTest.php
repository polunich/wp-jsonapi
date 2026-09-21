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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(PageRequest::class)]
final class PageRequestTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(PageRequest::class))->isReadOnly());
    }

    public function testThePageNumberStrategyCarriesTheNumberAndTheSize(): void
    {
        $page = PageRequest::pageNumber(3, 20);

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertSame(3, $page->number);
        self::assertSame(20, $page->size);
    }

    public function testTheOffsetStrategyCarriesTheOffsetAndTheLimit(): void
    {
        $page = PageRequest::offset(40, 20);

        self::assertSame(Strategy::Offset, $page->strategy);
        self::assertSame(40, $page->offset);
        self::assertSame(20, $page->limit);
    }

    public function testTheCursorStrategyCarriesTheBoundariesAndTheSize(): void
    {
        $after = new Cursor(['created' => '2026-01-01'], '42');
        $page = PageRequest::cursor($after, null, 20);

        self::assertSame(Strategy::Cursor, $page->strategy);
        self::assertSame($after, $page->after);
        self::assertNull($page->before);
        self::assertSame(20, $page->size);
    }

    public function testTheCursorStrategyTakesBothBoundariesAsARange(): void
    {
        $after = new Cursor(['created' => '2026-01-01'], '1');
        $before = new Cursor(['created' => '2026-02-01'], '9');

        $page = PageRequest::cursor($after, $before);

        self::assertSame($after, $page->after);
        self::assertSame($before, $page->before);
    }

    public function testThePageNumberStrategyCarriesNoParameterOfAnotherStrategy(): void
    {
        $page = PageRequest::pageNumber(3, 20);

        self::assertNull($page->offset);
        self::assertNull($page->limit);
        self::assertNull($page->after);
        self::assertNull($page->before);
    }

    public function testTheOffsetStrategyCarriesNoParameterOfAnotherStrategy(): void
    {
        $page = PageRequest::offset(40, 20);

        self::assertNull($page->number);
        self::assertNull($page->size);
        self::assertNull($page->after);
        self::assertNull($page->before);
    }

    public function testTheCursorStrategyCarriesNoParameterOfAnotherStrategy(): void
    {
        $page = PageRequest::cursor(new Cursor([], '42'), null, 20);

        self::assertNull($page->number);
        self::assertNull($page->offset);
        self::assertNull($page->limit);
    }

    public function testTheRequestWithoutAPageParameterIsThePageNumberStrategyWithNothingInIt(): void
    {
        $page = PageRequest::pageNumber();

        self::assertSame(Strategy::PageNumber, $page->strategy);
        self::assertNull($page->number);
        self::assertNull($page->size);
    }

    #[DataProvider('defaultTotalRequests')]
    public function testTheTotalIsRequestedByTheStrategyWhenTheClientSendsNoWithCount(
        PageRequest $page,
        bool $expected,
    ): void {
        self::assertSame($expected, $page->totalRequested);
    }

    /**
     * @return iterable<string, array{PageRequest, bool}>
     */
    public static function defaultTotalRequests(): iterable
    {
        yield 'the page number' => [PageRequest::pageNumber(3, 20), true];
        yield 'the offset' => [PageRequest::offset(40, 20), true];
        yield 'the cursor' => [PageRequest::cursor(null, null, 20), false];
    }

    #[DataProvider('sentWithCounts')]
    public function testTheClientDecidesTheTotalWhereverItSendsWithCount(PageRequest $page, bool $expected): void
    {
        self::assertSame($expected, $page->totalRequested);
    }

    /**
     * @return iterable<string, array{PageRequest, bool}>
     */
    public static function sentWithCounts(): iterable
    {
        yield 'the page number, turned off' => [PageRequest::pageNumber(3, 20, false), false];
        yield 'the page number, turned on' => [PageRequest::pageNumber(3, 20, true), true];
        yield 'the offset, turned off' => [PageRequest::offset(40, 20, false), false];
        yield 'the cursor, turned on' => [PageRequest::cursor(null, null, 20, true), true];
        yield 'the cursor, turned off' => [PageRequest::cursor(null, null, 20, false), false];
    }

    #[DataProvider('impossiblePages')]
    public function testRefusesAPageThatNamesNoRecords(Closure $build, string $parameter): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($parameter);

        $build();
    }

    /**
     * @return iterable<string, array{Closure(): PageRequest, string}>
     */
    public static function impossiblePages(): iterable
    {
        yield 'the page before the first' => [static function (): PageRequest {
            return PageRequest::pageNumber(0);
        }, 'page[number]'];
        yield 'a negative page' => [static function (): PageRequest {
            return PageRequest::pageNumber(-1);
        }, 'page[number]'];
        yield 'a page of no record' => [static function (): PageRequest {
            return PageRequest::pageNumber(1, 0);
        }, 'page[size]'];
        yield 'a negative offset' => [static function (): PageRequest {
            return PageRequest::offset(-1);
        }, 'page[offset]'];
        yield 'a limit of no record' => [static function (): PageRequest {
            return PageRequest::offset(0, 0);
        }, 'page[limit]'];
        yield 'a cursor page of no record' => [
            static function (): PageRequest {
                return PageRequest::cursor(null, null, 0);
            },
            'page[size]',
        ];
    }

    public function testTheFirstPageIsOneAndTheFirstOffsetIsNone(): void
    {
        self::assertSame(1, PageRequest::pageNumber(1)->number);
        self::assertSame(0, PageRequest::offset(0)->offset);
    }
}
