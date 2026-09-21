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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Page::class)]
final class PageTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(Page::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testCarriesTheRecordsAndTheMetadataOfThePage(): void
    {
        $records = [self::record('1'), self::record('2')];
        $first = new Cursor(['name' => 'a'], '1');
        $last = new Cursor(['name' => 'b'], '2');

        $page = new Page($records, Strategy::Cursor, 2, null, null, true, true, true, 40, 50, $first, $last);

        self::assertSame($records, $page->records);
        self::assertSame(Strategy::Cursor, $page->strategy);
        self::assertSame(2, $page->size);
        self::assertNull($page->number);
        self::assertNull($page->offset);
        self::assertTrue($page->hasNext);
        self::assertTrue($page->hasPrev);
        self::assertTrue($page->rangeTruncated);
        self::assertSame(40, $page->total);
        self::assertSame(50, $page->estimatedTotal);
        self::assertSame($first, $page->firstCursor);
        self::assertSame($last, $page->lastCursor);
    }

    #[DataProvider('sizesBelowOne')]
    public function testRefusesAPageSizeBelowOne(int $size): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'a page is written with the used page size %d, and a page size is at least 1',
            $size,
        ));

        self::page([], Strategy::PageNumber, size: $size, number: 1);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizesBelowOne(): iterable
    {
        yield 'none' => [0];
        yield 'negative' => [-1];
    }

    public function testRefusesMoreRecordsThanTheUsedPageSize(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a page of the used page size 2 carries 3 records');

        self::page([self::record('1'), self::record('2'), self::record('3')], Strategy::PageNumber, size: 2, number: 1);
    }

    public function testTakesAsManyRecordsAsTheUsedPageSize(): void
    {
        $records = [self::record('1'), self::record('2')];

        self::assertSame($records, self::page($records, Strategy::PageNumber, size: 2, number: 1)->records);
    }

    #[DataProvider('negativeTotals')]
    public function testRefusesANegativeTotal(?int $total, ?int $estimatedTotal, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::page([], Strategy::PageNumber, number: 1, total: $total, estimatedTotal: $estimatedTotal);
    }

    /**
     * @return iterable<string, array{?int, ?int, string}>
     */
    public static function negativeTotals(): iterable
    {
        yield 'total' => [-1, null, 'a page reports -1 records in all, which is fewer than none'];
        yield 'estimated total' => [null, -2, 'a page estimates -2 records in all, which is fewer than none'];
    }

    public function testTakesTheTotalsTheStoreGaveAndNoneWhereItGaveNone(): void
    {
        $counted = self::page([], Strategy::PageNumber, number: 1, total: 0, estimatedTotal: 0);
        $uncounted = self::page([], Strategy::PageNumber, number: 1);

        self::assertSame(0, $counted->total);
        self::assertSame(0, $counted->estimatedTotal);
        self::assertNull($uncounted->total);
        self::assertNull($uncounted->estimatedTotal);
    }

    #[DataProvider('numbersBelowOne')]
    public function testRefusesAPageNumberBelowOne(int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'a page is numbered %d, and the pages of a collection are counted from 1',
            $number,
        ));

        self::page([], Strategy::PageNumber, number: $number);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function numbersBelowOne(): iterable
    {
        yield 'none' => [0];
        yield 'negative' => [-1];
    }

    public function testRefusesANegativeOffsetAndTakesTheOffsetOfTheFirstPage(): void
    {
        self::assertSame(0, self::page([], Strategy::Offset, offset: 0)->offset);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a page stands at the offset -1, and the records before a page are counted'
            . ' from 0');

        self::page([], Strategy::Offset, offset: -1);
    }

    #[DataProvider('strategiesWithoutANumber')]
    public function testRefusesAPageNumberOutsideThePageNumberStrategy(Strategy $strategy): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('a page of the %s strategy carries page[number] 1', $strategy->value));

        self::page([], $strategy, number: 1);
    }

    /**
     * @return iterable<string, array{Strategy}>
     */
    public static function strategiesWithoutANumber(): iterable
    {
        yield 'offset' => [Strategy::Offset];
        yield 'cursor' => [Strategy::Cursor];
    }

    #[DataProvider('strategiesWithoutAnOffset')]
    public function testRefusesAnOffsetOutsideTheOffsetStrategy(Strategy $strategy, ?int $number): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('a page of the %s strategy carries page[offset] 0', $strategy->value));

        self::page([], $strategy, number: $number, offset: 0);
    }

    /**
     * @return iterable<string, array{Strategy, ?int}>
     */
    public static function strategiesWithoutAnOffset(): iterable
    {
        yield 'page number' => [Strategy::PageNumber, 1];
        yield 'cursor' => [Strategy::Cursor, null];
    }

    #[DataProvider('cursorsOutsideTheCursorStrategy')]
    public function testRefusesABoundaryCursorOutsideTheCursorStrategy(
        Strategy $strategy,
        ?int $number,
        ?int $offset,
        bool $atTheFirstRecord,
        string $message,
    ): void {
        $cursor = new Cursor(['name' => 'a'], '1');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::page(
            [self::record('1')],
            $strategy,
            number: $number,
            offset: $offset,
            firstCursor: $atTheFirstRecord ? $cursor : null,
            lastCursor: $atTheFirstRecord ? null : $cursor,
        );
    }

    /**
     * @return iterable<string, array{Strategy, ?int, ?int, bool, string}>
     */
    public static function cursorsOutsideTheCursorStrategy(): iterable
    {
        yield 'page number, first record' => [
            Strategy::PageNumber,
            1,
            null,
            true,
            'a page of the number strategy carries the cursor of its first record',
        ];
        yield 'page number, last record' => [
            Strategy::PageNumber,
            1,
            null,
            false,
            'a page of the number strategy carries the cursor of its last record',
        ];
        yield 'offset, first record' => [
            Strategy::Offset,
            null,
            0,
            true,
            'a page of the offset strategy carries the cursor of its first record',
        ];
        yield 'offset, last record' => [
            Strategy::Offset,
            null,
            0,
            false,
            'a page of the offset strategy carries the cursor of its last record',
        ];
    }

    #[DataProvider('boundaries')]
    public function testAnEmptyCursorPageCarriesTheBoundaryTheRequestNamed(bool $atTheFirstRecord): void
    {
        $cursor = new Cursor(['name' => 'a'], '1');

        $page = self::page(
            [],
            Strategy::Cursor,
            hasNext: !$atTheFirstRecord,
            hasPrev: $atTheFirstRecord,
            firstCursor: $atTheFirstRecord ? $cursor : null,
            lastCursor: $atTheFirstRecord ? null : $cursor,
        );

        self::assertSame([], $page->records);
        self::assertSame($atTheFirstRecord ? $cursor : null, $page->firstCursor);
        self::assertSame($atTheFirstRecord ? null : $cursor, $page->lastCursor);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function boundaries(): iterable
    {
        yield 'the cursor of a prev link' => [true];
        yield 'the cursor of a next link' => [false];
    }

    public function testACursorPageCarriesTheCursorsOfItsBoundaryRecords(): void
    {
        $first = new Cursor(['name' => 'a'], '1');
        $last = new Cursor(['name' => 'b'], '2');

        $page = self::page(
            [self::record('1'), self::record('2')],
            Strategy::Cursor,
            firstCursor: $first,
            lastCursor: $last,
        );
        $empty = self::page([], Strategy::Cursor);

        self::assertSame($first, $page->firstCursor);
        self::assertSame($last, $page->lastCursor);
        self::assertNull($empty->firstCursor);
        self::assertNull($empty->lastCursor);
    }

    public function testWithLastCursorReturnsACopyThatCarriesTheCursor(): void
    {
        $records = [self::record('1')];
        $first = new Cursor(['name' => 'a'], '1');
        $last = new Cursor(['name' => 'b'], '1');
        $page = self::page($records, Strategy::Cursor, size: 3, hasNext: true, firstCursor: $first);

        $filled = $page->withLastCursor($last);

        self::assertNull($page->lastCursor);
        self::assertSame($last, $filled->lastCursor);
        self::assertSame($records, $filled->records);
        self::assertSame(Strategy::Cursor, $filled->strategy);
        self::assertSame(3, $filled->size);
        self::assertTrue($filled->hasNext);
        self::assertSame($first, $filled->firstCursor);
    }

    #[DataProvider('pagesThatWriteNoCursor')]
    public function testWithLastCursorRefusesAPageThatWritesNoCursor(Page $page, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $page->withLastCursor(new Cursor(['name' => 'a'], '1'));
    }

    /**
     * @return iterable<string, array{Page, string}>
     */
    public static function pagesThatWriteNoCursor(): iterable
    {
        yield 'the page number strategy' => [
            self::page([self::record('1')], Strategy::PageNumber, number: 1),
            'a page of the number strategy carries the cursor of its last record',
        ];
        yield 'the offset strategy' => [
            self::page([self::record('1')], Strategy::Offset, offset: 0),
            'a page of the offset strategy carries the cursor of its last record',
        ];
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(
        array $records,
        Strategy $strategy,
        int $size = 10,
        ?int $number = null,
        ?int $offset = null,
        bool $hasNext = false,
        bool $hasPrev = false,
        bool $rangeTruncated = false,
        ?int $total = null,
        ?int $estimatedTotal = null,
        ?Cursor $firstCursor = null,
        ?Cursor $lastCursor = null,
    ): Page {
        return new Page(
            $records,
            $strategy,
            $size,
            $number,
            $offset,
            $hasNext,
            $hasPrev,
            $rangeTruncated,
            $total,
            $estimatedTotal,
            $firstCursor,
            $lastCursor,
        );
    }

    private static function record(string $id): ResourceRecord
    {
        return new ResourceRecord('pets', $id, new Pet($id, 'Rex'));
    }
}
