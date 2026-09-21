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

namespace Polunich\WpJsonApi\Tests\Unit\Execution;

use InvalidArgumentException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use stdClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(Paginator::class)]
final class PaginatorTest extends TestCase
{
    #[DataProvider('pages')]
    public function testTheReadingReversesForPageBeforeAlone(PageRequest $page, bool $reverses): void
    {
        self::assertSame($reverses, Paginator::reverses($page));
    }

    #[DataProvider('pages')]
    public function testTheSortIsTurnedAroundForPageBeforeAlone(PageRequest $page, bool $reverses): void
    {
        $sort = Sort::fromString('name,-id');

        $read = self::query($page, $sort)->sort;

        self::assertSame($reverses ? '-name,id' : 'name,-id', $read->toString());

        if (!$reverses) {
            self::assertSame($sort, $read);
        }
    }

    /**
     * @return iterable<string, array{PageRequest, bool}>
     */
    public static function pages(): iterable
    {
        yield 'page[before] alone' => [PageRequest::cursor(before: self::boundary()), true];
        yield 'page[after] alone' => [PageRequest::cursor(after: self::boundary()), false];
        yield 'a range of both cursors' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            false,
        ];
        yield 'a cursor page without a cursor' => [PageRequest::cursor(), false];
        yield 'the page number strategy' => [PageRequest::pageNumber(2), false];
        yield 'the offset strategy' => [PageRequest::offset(4), false];
    }

    public function testTheQueryCarriesTheReadingAsGiven(): void
    {
        $filter = new Condition(FieldPath::fromString('name'), Operators::EQUAL, 'Rex');
        $fields = new FieldHint(['name']);
        $languages = new LanguagePreferences([]);
        $parent = new ParentScope('customers', '4', 'pets');
        $queryParameters = new Values(['color' => 'red']);

        $query = self::paginator()->collectionQuery(
            'pets',
            $filter,
            Sort::byId(),
            PageRequest::offset(totalRequested: true),
            self::policy(),
            $fields,
            $languages,
            $parent,
            $queryParameters,
        );

        self::assertSame('pets', $query->type);
        self::assertSame($filter, $query->filter);
        self::assertTrue($query->totalRequested);
        self::assertSame($fields, $query->fields);
        self::assertSame($languages, $query->languages);
        self::assertSame($parent, $query->parent);
        self::assertSame($queryParameters, $query->queryParameters);
    }

    #[DataProvider('windows')]
    public function testTheWindowIsTheOffsetOfTheStrategyAndTheSizePlusOne(
        PageRequest $page,
        int $offset,
        int $limit,
    ): void {
        $window = self::query($page)->window;

        self::assertSame($offset, $window->offset);
        self::assertSame($limit, $window->limit);
    }

    /**
     * @return iterable<string, array{PageRequest, int, int}>
     */
    public static function windows(): iterable
    {
        yield 'the first page of a numbered walk, unnamed' => [PageRequest::pageNumber(), 0, 3];
        yield 'the first page of a numbered walk' => [PageRequest::pageNumber(1), 0, 3];
        yield 'the fourth page of a numbered walk' => [PageRequest::pageNumber(4), 6, 3];
        yield 'a numbered page that names its size' => [PageRequest::pageNumber(3, 5), 10, 6];
        yield 'an offset walk without page[offset]' => [PageRequest::offset(), 0, 3];
        yield 'an offset walk at page[offset]' => [PageRequest::offset(7), 7, 3];
        yield 'an offset walk that names its limit' => [PageRequest::offset(7, 4), 7, 5];
        yield 'a cursor page without a cursor' => [PageRequest::cursor(), 0, 3];
        yield 'a cursor page after a record' => [PageRequest::cursor(after: self::boundary()), 0, 3];
        yield 'a range, which reads with the max page size' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            0,
            3,
        ];
        yield 'a cursor page that names its size' => [PageRequest::cursor(size: 9), 0, 10];
    }

    #[DataProvider('windowsAtTheEdgeOfThePlatform')]
    public function testTheWindowHoldsEveryNumberInsideTheIntegersOfThePlatform(
        PageRequest $page,
        PaginationPolicy $policy,
        int $offset,
        int $limit,
    ): void {
        $window = self::query($page, policy: $policy)->window;

        self::assertSame($offset, $window->offset);
        self::assertSame($limit, $window->limit);
    }

    /**
     * @return iterable<string, array{PageRequest, PaginationPolicy, int, int}>
     */
    public static function windowsAtTheEdgeOfThePlatform(): iterable
    {
        yield 'a page number past what the platform counts' => [
            PageRequest::pageNumber(PHP_INT_MAX, 10),
            new PaginationPolicy(10, 100),
            PHP_INT_MAX - 11,
            11,
        ];
        yield 'the last page number the platform reads whole' => [
            PageRequest::pageNumber(intdiv(PHP_INT_MAX - 11, 10) + 1, 10),
            new PaginationPolicy(10, 100),
            intdiv(PHP_INT_MAX - 11, 10) * 10,
            11,
        ];
        yield 'a page size that leaves no room for the probe record' => [
            PageRequest::pageNumber(1, PHP_INT_MAX),
            new PaginationPolicy(10, PHP_INT_MAX),
            0,
            PHP_INT_MAX,
        ];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('usedSizes')]
    public function testThePageIsCutToTheSizeTheReadingUsedAndNotToTheDefault(
        PageRequest $request,
        int $returned,
        int $size,
        array $ids,
        bool $hasNext,
    ): void {
        $page = self::paginator()->page(
            $request,
            new LanguagePreferences([]),
            new Slice(self::pets($returned)),
            new PaginationPolicy(2, 100),
            'pets',
            self::sort(),
        );

        self::assertSame($size, $page->size);
        self::assertSame($ids, self::ids($page));
        self::assertSame($hasNext, $page->hasNext);
    }

    /**
     * @return iterable<string, array{PageRequest, int, int, list<string>, bool}>
     */
    public static function usedSizes(): iterable
    {
        yield 'a numbered page that names page[size]' => [
            PageRequest::pageNumber(1, 4),
            5,
            4,
            ['1', '2', '3', '4'],
            true,
        ];
        yield 'an offset page that names page[limit]' => [
            PageRequest::offset(0, 3),
            3,
            3,
            ['1', '2', '3'],
            false,
        ];
        yield 'a range without page[size], which reads with the maximum' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            3,
            100,
            ['1', '2', '3'],
            true,
        ];
    }

    public function testTheProbeRecordIsTrimmedAndSaysThatANextPageFollows(): void
    {
        $page = self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::pets(3)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(['1', '2'], self::ids($page));
        self::assertTrue($page->hasNext);
        self::assertSame(2, $page->size);
    }

    public function testAPageWithoutAProbeRecordHasNoNextPage(): void
    {
        $page = self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(['1', '2'], self::ids($page));
        self::assertFalse($page->hasNext);
    }

    public function testEveryRecordOfThePageIsPairedWithItsId(): void
    {
        $first = new Pet('1', 'first', '101');
        $second = new Pet('2', 'second', '102');

        $page = self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::read([$first, $second])),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(['pets', 'pets'], array_map(
            static function (ResourceRecord $record): string {
                return $record->type;
            },
            $page->records,
        ));
        self::assertSame(['1', '2'], self::ids($page));
        self::assertSame([$first, $second], array_map(
            static function (ResourceRecord $record): object {
                return $record->requireRecord();
            },
            $page->records,
        ));
    }

    /**
     * the reversed reading returns the record nearest the cursor first
     */
    public function testAPageReadBackwardsDropsTheRecordFurthestFromTheCursorAndIsTurnedBack(): void
    {
        $page = self::paginator()->page(
            PageRequest::cursor(before: self::boundary()),
            new LanguagePreferences([]),
            new Slice(self::read([
                new Pet('5', 'fifth', '101'),
                new Pet('4', 'fourth', '101'),
                new Pet('3', 'third', '101'),
            ])),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(['4', '5'], self::ids($page));
    }

    #[DataProvider('neighbours')]
    public function testThePageReportsTheNeighboursAndTheRangeOfItsStrategy(
        PageRequest $request,
        int $returned,
        bool $hasNext,
        bool $hasPrev,
        bool $rangeTruncated,
    ): void {
        $page = self::paginator()->page(
            $request,
            new LanguagePreferences([]),
            new Slice(self::pets($returned)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame($hasNext, $page->hasNext);
        self::assertSame($hasPrev, $page->hasPrev);
        self::assertSame($rangeTruncated, $page->rangeTruncated);
    }

    /**
     * @return iterable<string, array{PageRequest, int, bool, bool, bool}>
     */
    public static function neighbours(): iterable
    {
        yield 'a numbered page that names none, with a probe' => [PageRequest::pageNumber(), 3, true, false, false];
        yield 'the first numbered page, without a probe' => [PageRequest::pageNumber(1), 2, false, false, false];
        yield 'the second numbered page, with a probe' => [PageRequest::pageNumber(2), 3, true, true, false];
        yield 'an offset page that names none, with a probe' => [PageRequest::offset(), 3, true, false, false];
        yield 'an offset page at 0, without a probe' => [PageRequest::offset(0), 2, false, false, false];
        yield 'an offset page at 1, with a probe' => [PageRequest::offset(1), 3, true, true, false];
        yield 'an empty cursor page of a range' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            0,
            true,
            true,
            false,
        ];
        yield 'an empty cursor page after a record' => [
            PageRequest::cursor(after: self::boundary()),
            0,
            false,
            true,
            false,
        ];
        yield 'an empty cursor page before a record' => [
            PageRequest::cursor(before: self::boundary()),
            0,
            true,
            false,
            false,
        ];
        yield 'an empty cursor page without a cursor' => [PageRequest::cursor(), 0, false, false, false];
        yield 'a cursor page without a cursor, with a probe' => [PageRequest::cursor(), 3, true, false, false];
        yield 'a cursor page without a cursor, without a probe' => [PageRequest::cursor(), 2, false, false, false];
        yield 'a cursor page after a record, with a probe' => [
            PageRequest::cursor(after: self::boundary()),
            3,
            true,
            true,
            false,
        ];
        yield 'a cursor page after a record, without a probe' => [
            PageRequest::cursor(after: self::boundary()),
            2,
            false,
            true,
            false,
        ];
        yield 'a cursor page before a record, with a probe' => [
            PageRequest::cursor(before: self::boundary()),
            3,
            true,
            true,
            false,
        ];
        yield 'a cursor page before a record, without a probe' => [
            PageRequest::cursor(before: self::boundary()),
            2,
            true,
            false,
            false,
        ];
        yield 'a range larger than the page' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            3,
            true,
            true,
            true,
        ];
        yield 'a range the page holds whole' => [
            PageRequest::cursor(after: self::boundary(), before: self::boundary()),
            2,
            true,
            true,
            false,
        ];
    }

    public function testTheSizesOfTheSliceReachThePageUntouched(): void
    {
        $counted = self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::pets(2), 17, 20),
            self::policy(),
            'pets',
            self::sort(),
        );
        $uncounted = self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(17, $counted->total);
        self::assertSame(20, $counted->estimatedTotal);
        self::assertNull($uncounted->total);
        self::assertNull($uncounted->estimatedTotal);
    }

    #[DataProvider('positions')]
    public function testThePageCarriesTheNumberOrTheOffsetOfItsStrategy(
        PageRequest $request,
        Strategy $strategy,
        ?int $number,
        ?int $offset,
    ): void {
        $page = self::paginator()->page(
            $request,
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame($strategy, $page->strategy);
        self::assertSame($number, $page->number);
        self::assertSame($offset, $page->offset);
        self::assertSame(2, $page->size);
    }

    /**
     * @return iterable<string, array{PageRequest, Strategy, ?int, ?int}>
     */
    public static function positions(): iterable
    {
        yield 'a numbered page that names none' => [PageRequest::pageNumber(), Strategy::PageNumber, 1, null];
        yield 'the third numbered page' => [PageRequest::pageNumber(3), Strategy::PageNumber, 3, null];
        yield 'an offset page that names none' => [PageRequest::offset(), Strategy::Offset, null, 0];
        yield 'an offset page at 6' => [PageRequest::offset(6), Strategy::Offset, null, 6];
        yield 'a cursor page' => [PageRequest::cursor(after: self::boundary()), Strategy::Cursor, null, null];
    }

    public function testTheBoundaryCursorsStandAtTheFirstAndTheLastRecordOfThePage(): void
    {
        $page = self::paginator()->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(3)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame('1', self::cursor($page->firstCursor)->id);
        self::assertSame(['name' => 'name 1'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame('2', self::cursor($page->lastCursor)->id);
        self::assertSame(['name' => 'name 2'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testABoundaryCursorWritesItsValuesThroughTheCodecs(): void
    {
        $resources = self::registry(
            id: new IdMetadata(self::marking(), static function (Pet $pet): string {
                return $pet->id;
            }, false),
            name: Samples::attribute('name', self::marking(), sortable: true),
        );

        $page = self::paginator($resources)->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame('wire:1', self::cursor($page->firstCursor)->id);
        self::assertSame(['name' => 'wire:name 1'], self::cursor($page->firstCursor)->sortValues);
    }

    #[DataProvider('pagesWithoutCursors')]
    public function testNoCursorIsWrittenOutsideACursorPageThatHoldsARecord(PageRequest $request, int $returned): void
    {
        $page = self::paginator()->page(
            $request,
            new LanguagePreferences([]),
            new Slice(self::pets($returned)),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertNull($page->firstCursor);
        self::assertNull($page->lastCursor);
    }

    /**
     * @return iterable<string, array{PageRequest, int}>
     */
    public static function pagesWithoutCursors(): iterable
    {
        yield 'a numbered page' => [PageRequest::pageNumber(), 2];
        yield 'an offset page' => [PageRequest::offset(), 2];
        yield 'an empty cursor page' => [PageRequest::cursor(), 0];
    }

    #[DataProvider('emptyPagesOfABoundary')]
    public function testAnEmptyCursorPageHandsBackTheBoundaryOfItsRequest(
        PageRequest $request,
        bool $atTheFirstCursor,
    ): void {
        $page = self::paginator()->page(
            $request,
            new LanguagePreferences([]),
            new Slice([]),
            self::policy(),
            'pets',
            self::sort(),
        );

        self::assertSame(
            $atTheFirstCursor ? $request->after : $request->before,
            $atTheFirstCursor ? $page->firstCursor : $page->lastCursor,
        );
        self::assertNull($atTheFirstCursor ? $page->lastCursor : $page->firstCursor);
    }

    /**
     * @return iterable<string, array{PageRequest, bool}>
     */
    public static function emptyPagesOfABoundary(): iterable
    {
        yield 'a page after a record' => [PageRequest::cursor(after: self::boundary()), true];
        yield 'a page before a record' => [PageRequest::cursor(before: self::boundary()), false];
    }

    public function testASortFieldThroughARelationshipWithAnIdentifierIsReadInOneLoad(): void
    {
        $loader = self::loader([new Pet('101', 'Ann'), new Pet('102', 'Bob')]);
        $languages = new LanguagePreferences([]);

        $page = self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => $loader]))->page(
            PageRequest::cursor(),
            $languages,
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );

        self::assertCount(1, $loader->asked);
        self::assertSame('customers', $loader->asked[0]->type);
        self::assertSame(['101', '102'], $loader->asked[0]->ids);
        self::assertSame(['name'], $loader->asked[0]->fields->fields());
        self::assertSame($languages, $loader->asked[0]->languages);
        self::assertSame(['owner.name' => 'Ann'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame(['owner.name' => 'Bob'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testTwoBoundaryRecordsOfOneRelatedResourceAskForItsIdOnce(): void
    {
        $loader = self::loader([new Pet('101', 'Ann')]);

        $page = self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => $loader]))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::read([new Pet('1', 'first', '101'), new Pet('2', 'second', '101')])),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );

        self::assertSame(['101'], $loader->asked[0]->ids);
        self::assertSame(['owner.name' => 'Ann'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame(['owner.name' => 'Ann'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testASortFieldThroughARelationshipWithoutAnIdentifierIsReadThroughTheRelatedLoader(): void
    {
        $loader = self::relatedLoader([[new Pet('101', 'Ann')], [new Pet('102', 'Bob')]]);
        $languages = new LanguagePreferences([]);

        $page = self::paginator(
            self::registry(readsRelatedId: false),
            BoundImplementations::of([RelatedLoader::class => $loader]),
        )->page(
            PageRequest::cursor(),
            $languages,
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );

        self::assertCount(1, $loader->asked);
        self::assertSame('pets', $loader->asked[0]->type);
        self::assertSame(['1', '2'], $loader->asked[0]->parentIds);
        self::assertSame('owner', $loader->asked[0]->relationship);
        self::assertSame('id', $loader->asked[0]->sort->toString());
        self::assertSame(1, $loader->asked[0]->limit);
        self::assertSame(['name'], $loader->asked[0]->fields->fields());
        self::assertSame($languages, $loader->asked[0]->languages);
        self::assertSame(['owner.name' => 'Ann'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame(['owner.name' => 'Bob'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testARelatedIdWithAnIdentifierIsReadOffTheBoundaryRecord(): void
    {
        $loader = self::loader([]);

        $page = self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => $loader]))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('-owner.id,id'),
        );

        self::assertSame([], $loader->asked);
        self::assertSame(['owner.id' => '101'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame('1', self::cursor($page->firstCursor)->id);
        self::assertSame(['owner.id' => '102'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testARelatedIdWithoutAnIdentifierIsReadThroughTheRelatedLoaderAskingForNoField(): void
    {
        $loader = self::relatedLoader([[new Pet('101', 'Ann')], [new Pet('102', 'Bob')]]);
        $languages = new LanguagePreferences([]);

        $page = self::paginator(
            self::registry(readsRelatedId: false),
            BoundImplementations::of([RelatedLoader::class => $loader]),
        )->page(
            PageRequest::cursor(),
            $languages,
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('owner.id,id'),
        );

        self::assertCount(1, $loader->asked);
        self::assertSame(['1', '2'], $loader->asked[0]->parentIds);
        self::assertSame('owner', $loader->asked[0]->relationship);
        self::assertSame([], $loader->asked[0]->fields->fields());
        self::assertSame($languages, $loader->asked[0]->languages);
        self::assertSame(['owner.id' => '101'], self::cursor($page->firstCursor)->sortValues);
        self::assertSame(['owner.id' => '102'], self::cursor($page->lastCursor)->sortValues);
    }

    public function testARelatedIdIsWrittenByTheIdCodecOfTheRelatedType(): void
    {
        $page = self::paginator(self::registry(customersId: new IdMetadata(self::marking(), static function (
            Pet $pet,
        ): string {
            return $pet->id;
        }, false)))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            Sort::fromString('owner.id,id'),
        );

        self::assertSame(['owner.id' => 'wire:101'], self::cursor($page->firstCursor)->sortValues);
    }

    public function testARelatedIdTheCodecWritesAsNoStringIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'the id codec of type "customers" wrote the id "101" of sort field "owner.id" as int, and an id is a'
            . ' string [spec]',
        );

        self::paginator(self::registry(customersId: new IdMetadata(self::writing(12), static function (
            Pet $pet,
        ): string {
            return $pet->id;
        }, false)))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            Sort::fromString('owner.id,id'),
        );
    }

    public function testAnEmptyRelationshipOfARelatedIdIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'relationship "owner" of record "1" of type "pets" is empty, and sort field "owner.id" of a boundary'
            . ' record is read through it',
        );

        self::paginator(self::registry(nullable: true))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::read([new Pet('1', 'first')])),
            self::policy(),
            'pets',
            Sort::fromString('owner.id,id'),
        );
    }

    public function testARelatedRecordTheClientMayNotSeeIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the resource loader of type "customers" returned no record for id "101"');

        self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => self::loader([
            null,
        ])]))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );
    }

    public function testARelatedRecordTheRelatedLoaderDoesNotReturnIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'the related loader of type "pets" returned no record of relationship "owner" for parent "1"',
        );

        self::paginator(
            self::registry(readsRelatedId: false),
            BoundImplementations::of([RelatedLoader::class => self::relatedLoader([[]])]),
        )->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenBatches')]
    public function testALoaderThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::paginator(implementations: BoundImplementations::of([
            ResourceLoader::class => self::loader(self::read($batch)),
        ]))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenBatches(): iterable
    {
        yield 'fewer records than ids' => [
            [new Pet('101', 'Ann')],
            'the resource loader of type "customers" returned 1 entries for a request of 2',
        ];
        yield 'more records than ids' => [
            [new Pet('101', 'Ann'), new Pet('102', 'Bob'), new Pet('102', 'Bob')],
            'the resource loader of type "customers" returned 3 entries for a request of 2',
        ];
        // read by position, `102` would stand at `101` and give the first cursor its value
        yield 'the records keyed by their ids in another order' => [
            ['102' => new Pet('102', 'Bob'), '101' => new Pet('101', 'Ann')],
            'the resource loader of type "customers" returned an array keyed by other than its positions',
        ];
        yield 'a result that is no list of records' => [
            ['Ann', new Pet('102', 'Bob')],
            'the resource loader of type "customers" returned string at the place of id "101"',
        ];
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenRelatedBatches')]
    public function testARelatedLoaderThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::paginator(
            self::registry(readsRelatedId: false),
            BoundImplementations::of([RelatedLoader::class => self::relatedLoader(self::related($batch))]),
        )->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(2)),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenRelatedBatches(): iterable
    {
        yield 'fewer parents than asked for' => [
            [[new Pet('101', 'Ann')]],
            'the related loader of relationship "owner" of type "pets" returned 1 entries for a request of 2',
        ];
        yield 'the lists keyed by their parents in another order' => [
            ['2' => [new Pet('102', 'Bob')], '1' => [new Pet('101', 'Ann')]],
            'the related loader of relationship "owner" of type "pets" returned an array keyed by other than'
            . ' its positions',
        ];
        yield 'more related records than the limit' => [
            [[new Pet('101', 'Ann'), new Pet('102', 'Bob')], [new Pet('102', 'Bob')]],
            'the related loader of relationship "owner" of type "pets" returned 2 records for parent "1",'
            . ' and the reading asks for 1 at most',
        ];
        yield 'a parent that carries no list of related records' => [
            ['Ann', [new Pet('102', 'Bob')]],
            'the related loader of relationship "owner" of type "pets" returned string for parent "1", and'
            . ' a loader returns the list of the related records of each parent',
        ];
        yield 'a related record that is no record' => [
            [['Ann'], [new Pet('102', 'Bob')]],
            'the related loader of relationship "owner" of type "pets" returned string among the records of'
            . ' parent "1", and a related record is a record',
        ];
    }

    public function testAnEmptyRelationshipOfABoundaryRecordIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(
            'relationship "owner" of record "1" of type "pets" is empty, and sort field "owner.name" of a'
            . ' boundary record is read through it',
        );

        self::paginator(self::registry(nullable: true))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::read([new Pet('1', 'first')])),
            self::policy(),
            'pets',
            Sort::fromString('owner.name,id'),
        );
    }

    #[DataProvider('valuesNoCursorCarries')]
    public function testASortValueThatIsNoFiniteScalarIsRefused(mixed $written, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::paginator(self::registry(name: Samples::attribute('name', self::writing($written), sortable: true)))
            ->page(
                PageRequest::cursor(),
                new LanguagePreferences([]),
                new Slice(self::pets(1)),
                self::policy(),
                'pets',
                self::sort(),
            );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function valuesNoCursorCarries(): iterable
    {
        yield 'a list' => [['first'], 'sort field "name" of a boundary record was written as array'];
        yield 'an object' => [new stdClass(), 'sort field "name" of a boundary record was written as stdClass'];
        yield 'null' => [null, 'sort field "name" of a boundary record was written as null'];
        yield 'a number that is no number' => [NAN, 'was written as NAN, which is no finite number'];
        yield 'a number without an end' => [INF, 'was written as INF, which is no finite number'];
    }

    #[DataProvider('idsNoCursorCarries')]
    public function testAnIdTheCodecWritesAsNoNonEmptyStringIsRefused(mixed $written, string $message): void
    {
        $resources = self::registry(
            id: new IdMetadata(self::writing($written), static function (Pet $pet): string {
                return $pet->id;
            }, false),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::paginator($resources)->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            self::sort(),
        );
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function idsNoCursorCarries(): iterable
    {
        yield 'a number' => [
            12,
            'the id codec of type "pets" wrote the id "1" of a boundary record as int',
        ];
        yield 'the empty string' => [
            '',
            'the id codec of type "pets" wrote the id "1" of a boundary record as the empty string',
        ];
    }

    /**
     * a client's sort is refused before paging, so such a sort here is a library mistake
     */
    #[DataProvider('sortsNoCursorIsWrittenFrom')]
    public function testASortFieldTheDeclarationDoesNotCoverIsRefused(
        string $sort,
        ?AttributeMetadata $name,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::paginator(self::registry(name: $name))->page(
            PageRequest::cursor(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'pets',
            Sort::fromString($sort),
        );
    }

    /**
     * @return iterable<string, array{string, ?AttributeMetadata, string}>
     */
    public static function sortsNoCursorIsWrittenFrom(): iterable
    {
        yield 'an attribute the type does not declare' => [
            'missing,id',
            null,
            'sort path "missing" ends at "missing", which is no sortable attribute of type "pets"',
        ];
        yield 'an attribute that is not sortable' => [
            'name,id',
            Samples::attribute('name', readable: false),
            'sort path "name" ends at "name", which is no sortable attribute of type "pets"',
        ];
        yield 'a path through an attribute' => [
            'name.name,id',
            null,
            'sort path "name.name" runs through "name", which is no to-one relationship of type "pets"',
        ];
        yield 'a path through a to-many relationship' => [
            'owners.name,id',
            null,
            'sort path "owners.name" runs through "owners", which is no to-one relationship of type "pets"',
        ];
        yield 'a path through two relationships' => [
            'owner.breeder.name,id',
            null,
            'sort path "owner.breeder.name" runs through 2 relationships, and a sort path runs through 1 at most',
        ];
    }

    public function testAnUndeclaredTypeIsRefusedAsAMistakeOfTheLibrary(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "jobs" is not declared');

        self::paginator()->page(
            PageRequest::pageNumber(),
            new LanguagePreferences([]),
            new Slice(self::pets(1)),
            self::policy(),
            'jobs',
            self::sort(),
        );
    }

    public function testTheLinkageIsTheFirstPageOfTheRelationship(): void
    {
        $page = self::paginator()->linkagePage(self::pets(3), 2, 'pets', self::context());

        self::assertSame(['1', '2'], self::ids($page));
        self::assertTrue($page->hasNext);
        self::assertFalse($page->hasPrev);
        self::assertFalse($page->rangeTruncated);
        self::assertSame(2, $page->size);
        self::assertNull($page->offset);
        self::assertNull($page->total);
        self::assertNull($page->estimatedTotal);
        self::assertNull($page->firstCursor);
        self::assertNull($page->lastCursor);
    }

    public function testALinkageWithoutTheRecordPastItHasNoNextPage(): void
    {
        $page = self::paginator()->linkagePage(self::pets(2), 2, 'pets', self::context());

        self::assertSame(['1', '2'], self::ids($page));
        self::assertFalse($page->hasNext);
    }

    #[DataProvider('negotiations')]
    public function testTheLinkageTakesTheStrategyOfTheNegotiatedResponse(
        bool $cursorPagination,
        Strategy $strategy,
        ?int $number,
    ): void {
        $page = self::paginator()->linkagePage(self::pets(1), 2, 'pets', self::context($cursorPagination));

        self::assertSame($strategy, $page->strategy);
        self::assertSame($number, $page->number);
    }

    /**
     * @return iterable<string, array{bool, Strategy, ?int}>
     */
    public static function negotiations(): iterable
    {
        yield 'an Accept field that asks for the cursor profile' => [true, Strategy::Cursor, null];
        yield 'an Accept field that does not' => [false, Strategy::PageNumber, 1];
    }

    public function testTheLastCursorOfEveryPageOfALevelIsWrittenInOneReading(): void
    {
        $loader = self::loader([new Pet('102', 'Bea'), new Pet('103', 'Cid')]);
        $languages = new LanguagePreferences([]);
        $pages = [
            self::paginator()->linkagePage(
                self::read([new Pet('1', 'first', '101'), new Pet('2', 'second', '102')]),
                2,
                'pets',
                self::context(),
            ),
            self::paginator()->linkagePage(self::read([]), 2, 'pets', self::context()),
            self::paginator()->linkagePage(self::read([new Pet('3', 'third', '103')]), 2, 'pets', self::context()),
        ];

        $filled = self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => $loader]))
            ->withLinkageCursors($languages, $pages, 'pets', Sort::fromString('owner.name,id'));

        self::assertCount(1, $loader->asked);
        self::assertSame(['102', '103'], $loader->asked[0]->ids);
        self::assertSame($languages, $loader->asked[0]->languages);
        self::assertSame('2', self::cursor($filled[0]->lastCursor)->id);
        self::assertSame(['owner.name' => 'Bea'], self::cursor($filled[0]->lastCursor)->sortValues);
        self::assertNull($filled[1]->lastCursor);
        self::assertSame('3', self::cursor($filled[2]->lastCursor)->id);
        self::assertSame(['owner.name' => 'Cid'], self::cursor($filled[2]->lastCursor)->sortValues);
        self::assertNull($filled[0]->firstCursor);
        self::assertSame(['1', '2'], self::ids($filled[0]));
        self::assertSame(['3'], self::ids($filled[2]));
    }

    public function testAPageOfAnotherStrategyAndAnEmptyPageAreHandedBackAsTheyWere(): void
    {
        $loader = self::loader([]);
        $pages = [
            self::paginator()->linkagePage(self::pets(1), 2, 'pets', self::context(false)),
            self::paginator()->linkagePage(self::read([]), 2, 'pets', self::context()),
        ];

        $filled = self::paginator(implementations: BoundImplementations::of([ResourceLoader::class => $loader]))
            ->withLinkageCursors(new LanguagePreferences([]), $pages, 'pets', self::sort());

        self::assertSame($pages, $filled);
        self::assertSame([], $loader->asked);
    }

    public function testThePaginatorIsInternalAndImmutable(): void
    {
        $window = new ReflectionClass(Paginator::class);

        self::assertStringContainsString('@internal', (string) $window->getDocComment());
        self::assertTrue($window->isFinal());
        self::assertTrue($window->isReadOnly());
    }

    /**
     * @return list<string>
     */
    private static function ids(Page $page): array
    {
        return array_map(static function (ResourceRecord $record): string {
            return $record->id;
        }, $page->records);
    }

    private static function cursor(?Cursor $cursor): Cursor
    {
        return $cursor ?? self::fail('the page carries no boundary cursor');
    }

    private static function paginator(
        ?ResourceRegistry $resources = null,
        ?ImplementationResolver $implementations = null,
    ): Paginator {
        $resources ??= self::registry();

        return new Paginator(
            $resources,
            new StoreGateway($resources, $implementations ?? BoundImplementations::of([])),
        );
    }

    /**
     * every record is a `Pet`: the `name` of a customer is the name of the `Pet` standing for it,
     * and the `owner` of a pet is its `ownerId`
     */
    private static function registry(
        bool $readsRelatedId = true,
        bool $nullable = false,
        ?IdMetadata $id = null,
        ?AttributeMetadata $name = null,
        ?IdMetadata $customersId = null,
    ): ResourceRegistry {
        $customers = Samples::resource(
            'customers',
            [Samples::attribute('name', sortable: true)],
            capabilities: [Capability::CollectionReader],
            id: $customersId,
        );
        $pets = Samples::resource(
            'pets',
            [$name ?? Samples::attribute('name', sortable: true)],
            [
                Samples::toOne('owner', 'customers', readsRelatedId: $readsRelatedId, nullable: $nullable),
                Samples::toMany('owners', 'customers', includable: false),
            ],
            $readsRelatedId ? [] : [Capability::RelatedLoader],
            id: $id,
        );

        return new ResourceRegistry([$pets, $customers]);
    }

    /**
     * the default and the maximum are equal, so a range without `page[size]`,
     * which reads with the maximum, gets the same window as any other page
     */
    private static function policy(): PaginationPolicy
    {
        return new PaginationPolicy(2, 2);
    }

    private static function query(
        PageRequest $page,
        ?Sort $sort = null,
        ?PaginationPolicy $policy = null,
    ): CollectionQuery {
        return self::paginator()->collectionQuery(
            'pets',
            null,
            $sort ?? self::sort(),
            $page,
            $policy ?? self::policy(),
            new FieldHint(null),
            new LanguagePreferences([]),
            null,
            new Values([]),
        );
    }

    private static function sort(): Sort
    {
        return Sort::fromString('name,id');
    }

    private static function context(bool $cursorPagination = true): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('GET', new Headers(), [], [], ''),
            MediaType::jsonApi(),
            null,
            false,
            $cursorPagination,
            [],
        );
    }

    /**
     * its values are never read here: only whether a request carries a cursor matters
     */
    private static function boundary(): Cursor
    {
        return new Cursor(['name' => 'name 0'], '0');
    }

    /**
     * @return list<object>
     */
    private static function pets(int $count): array
    {
        $pets = [];

        for ($number = 1; $number <= $count; $number++) {
            $pets[] = new Pet((string) $number, 'name ' . $number, '10' . $number);
        }

        return self::read($pets);
    }

    /**
     * the port's list of records is a docblock promise PHP never checks,
     * so a test can hand over what a project may really return, malformed entries included
     *
     * @param array<mixed> $records
     *
     * @return list<object>
     */
    private static function read(array $records): array
    {
        /** @var list<object> $list */
        $list = $records;

        return $list;
    }

    /**
     * @param array<mixed> $batch
     *
     * @return list<list<object>>
     */
    private static function related(array $batch): array
    {
        /** @var list<list<object>> $lists */
        $lists = $batch;

        return $lists;
    }

    /**
     * @param list<?object> $records
     *
     * @return ResourceLoader<object>&object{asked: list<LoadRequest>}
     */
    private static function loader(array $records): ResourceLoader
    {
        return new class ($records) implements ResourceLoader {
            /**
             * @var list<LoadRequest>
             */
            public array $asked = [];

            /**
             * @param list<?object> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<?object>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $this->asked[] = $request;

                return $this->records;
            }
        };
    }

    /**
     * @param list<list<object>> $records
     *
     * @return RelatedLoader<object>&object{asked: list<RelatedLoadRequest>}
     */
    private static function relatedLoader(array $records): RelatedLoader
    {
        return new class ($records) implements RelatedLoader {
            /**
             * @var list<RelatedLoadRequest>
             */
            public array $asked = [];

            /**
             * @param list<list<object>> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<list<object>>
             */
            #[Override]
            public function loadRelated(RelatedLoadRequest $request): array
            {
                $this->asked[] = $request;

                return $this->records;
            }
        };
    }

    /**
     * prefixes what it writes with `wire:`, which tells an encoded cursor value from the raw one
     */
    private static function marking(): SegmentCodec
    {
        return self::writing(null, true);
    }

    /**
     * decodes and describes as `StringCodec`,
     * so its schema admits a scalar and the attribute may be sortable
     */
    private static function writing(mixed $written, bool $marks = false): SegmentCodec
    {
        return new class ($written, $marks) implements SegmentCodec {
            private readonly StringCodec $strings;

            public function __construct(private readonly mixed $written, private readonly bool $marks)
            {
                $this->strings = new StringCodec();
            }

            #[Override]
            public function decode(mixed $input, Pointer $pointer): string
            {
                return $this->strings->decode($input, $pointer);
            }

            #[Override]
            public function encode(mixed $value): mixed
            {
                return $this->marks ? 'wire:' . $this->strings->encode($value) : $this->written;
            }

            #[Override]
            public function schema(): Schema
            {
                return $this->strings->schema();
            }

            #[Override]
            public function segmentPattern(): string
            {
                return '[^/]+';
            }
        };
    }
}
