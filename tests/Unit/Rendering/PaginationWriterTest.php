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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(PaginationWriter::class)]
final class PaginationWriterTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(PaginationWriter::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testAppendsThePaginationLinksToTheLinksTheObjectAlreadyCarries(): void
    {
        $given = new Links(['self' => '/pets/1/relationships/orders', 'related' => '/pets/1/orders']);

        $written = self::written(
            self::page([], Strategy::PageNumber, size: 2, number: 1, hasNext: true),
            self::route(RouteKind::Relationship),
            links: $given,
        );

        self::assertSame(
            [
                'self' => '/pets/1/relationships/orders',
                'related' => '/pets/1/orders',
                'first' => '/pets/1/relationships/orders?{"page":{"number":1,"size":2}}',
                'prev' => null,
                'next' => '/pets/1/relationships/orders?{"page":{"number":2,"size":2}}',
            ],
            $written,
        );
    }

    public function testThePageNumberStrategyWritesTheFourLinksAroundThePage(): void
    {
        $written = self::written(self::page(
            [self::record('3'), self::record('4')],
            Strategy::PageNumber,
            size: 2,
            number: 2,
            hasNext: true,
            hasPrev: true,
            total: 7,
        ));

        self::assertSame(
            [
                'first' => '/pets?{"page":{"number":1,"size":2}}',
                'prev' => '/pets?{"page":{"number":1,"size":2}}',
                'next' => '/pets?{"page":{"number":3,"size":2}}',
                'last' => '/pets?{"page":{"number":4,"size":2}}',
            ],
            $written,
        );
    }

    public function testTheOffsetStrategyWritesTheFourLinksOnTheGridOfTheFirstRecord(): void
    {
        $written = self::written(self::page(
            [self::record('3'), self::record('4')],
            Strategy::Offset,
            size: 2,
            offset: 2,
            hasNext: true,
            hasPrev: true,
            total: 7,
        ));

        self::assertSame(
            [
                'first' => '/pets?{"page":{"offset":0,"limit":2}}',
                'prev' => '/pets?{"page":{"offset":0,"limit":2}}',
                'next' => '/pets?{"page":{"offset":4,"limit":2}}',
                'last' => '/pets?{"page":{"offset":6,"limit":2}}',
            ],
            $written,
        );
    }

    public function testThePrevLinkOfTheOffsetStrategyStopsAtTheFirstRecord(): void
    {
        $written = self::written(self::page(
            [self::record('2')],
            Strategy::Offset,
            size: 5,
            offset: 1,
            hasPrev: true,
        ));

        self::assertSame(
            [
                'first' => '/pets?{"page":{"offset":0,"limit":5}}',
                'prev' => '/pets?{"page":{"offset":0,"limit":5}}',
                'next' => null,
            ],
            $written,
        );
    }

    /**
     * @param array<string, int> $family the page member of the query of the last link
     */
    #[DataProvider('lastPages')]
    public function testTheLastLinkStandsAtThePageOfTheLastRecord(
        Strategy $strategy,
        int $size,
        int $total,
        array $family,
    ): void {
        $page = self::page(
            [],
            $strategy,
            size: $size,
            number: $strategy === Strategy::PageNumber ? 1 : null,
            offset: $strategy === Strategy::Offset ? 0 : null,
            total: $total,
        );

        self::assertSame(
            '/pets?' . json_encode(['page' => $family]),
            self::written($page)['last'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{Strategy, int, int, array<string, int>}>
     */
    public static function lastPages(): iterable
    {
        yield 'a number strategy whose total fills its pages exactly' => [
            Strategy::PageNumber,
            10,
            30,
            ['number' => 3, 'size' => 10],
        ];
        yield 'a number strategy whose last page is partial' => [
            Strategy::PageNumber,
            10,
            31,
            ['number' => 4, 'size' => 10],
        ];
        yield 'a number strategy of a single record' => [
            Strategy::PageNumber,
            10,
            1,
            ['number' => 1, 'size' => 10],
        ];
        yield 'an empty collection by number, whose first page is its last' => [
            Strategy::PageNumber,
            10,
            0,
            ['number' => 1, 'size' => 10],
        ];
        yield 'an offset strategy whose total fills its pages exactly' => [
            Strategy::Offset,
            10,
            30,
            ['offset' => 20, 'limit' => 10],
        ];
        yield 'an offset strategy whose last page is partial' => [
            Strategy::Offset,
            10,
            31,
            ['offset' => 30, 'limit' => 10],
        ];
        yield 'an empty collection by offset, which starts and ends at 0' => [
            Strategy::Offset,
            10,
            0,
            ['offset' => 0, 'limit' => 10],
        ];
    }

    #[DataProvider('strategiesThatWalkByNumbers')]
    public function testTheLastLinkIsLeftOutWithoutATotalWhilePrevAndNextAreWrittenAsNull(
        Strategy $strategy,
        ?int $number,
        ?int $offset,
    ): void {
        $written = self::written(self::page([], $strategy, number: $number, offset: $offset));

        self::assertArrayNotHasKey('last', $written);
        self::assertArrayHasKey('prev', $written);
        self::assertArrayHasKey('next', $written);
        self::assertNull($written['prev']);
        self::assertNull($written['next']);
    }

    /**
     * @return iterable<string, array{Strategy, ?int, ?int}>
     */
    public static function strategiesThatWalkByNumbers(): iterable
    {
        yield 'page number' => [Strategy::PageNumber, 1, null];
        yield 'offset' => [Strategy::Offset, null, 0];
    }

    #[DataProvider('everyStrategy')]
    public function testPrevAndNextAreWrittenForEveryStrategy(Page $page): void
    {
        $written = self::written($page);

        self::assertArrayHasKey('prev', $written);
        self::assertArrayHasKey('next', $written);
    }

    /**
     * @return iterable<string, array{Page}>
     */
    public static function everyStrategy(): iterable
    {
        yield 'page number' => [self::page([], Strategy::PageNumber, number: 1)];
        yield 'offset' => [self::page([], Strategy::Offset, offset: 0)];
        yield 'cursor' => [self::page([], Strategy::Cursor)];
    }

    /**
     * the total is set on purpose: it does not make a last link computable
     */
    public function testACursorPageWritesNeitherAFirstNorALastLink(): void
    {
        $written = self::written(self::page(
            [self::record('3')],
            Strategy::Cursor,
            hasNext: true,
            hasPrev: true,
            total: 40,
            firstCursor: new Cursor(['name' => 'a'], '3'),
            lastCursor: new Cursor(['name' => 'b'], '3'),
        ));

        self::assertSame(['prev', 'next'], array_keys($written));
    }

    public function testACursorPageWalksFromItsOwnBoundaryRecords(): void
    {
        $first = new Cursor(['name' => 'a'], '3');
        $last = new Cursor(['name' => 'b'], '4');

        $written = self::written(self::page(
            [self::record('3'), self::record('4')],
            Strategy::Cursor,
            size: 2,
            hasNext: true,
            hasPrev: true,
            firstCursor: $first,
            lastCursor: $last,
        ));

        self::assertSame(
            [
                'prev' => '/pets?' . json_encode(['page' => ['before' => CursorText::encode($first), 'size' => 2]]),
                'next' => '/pets?' . json_encode(['page' => ['after' => CursorText::encode($last), 'size' => 2]]),
            ],
            $written,
        );
    }

    public function testTheCursorTravelsAsItsUnsignedBase64urlText(): void
    {
        $written = self::written(self::page(
            [self::record('1')],
            Strategy::Cursor,
            size: 2,
            hasNext: true,
            lastCursor: new Cursor(['name' => 'a'], '1'),
        ));

        self::assertSame(
            '/pets?{"page":{"after":"eyJzb3J0Ijp7Im5hbWUiOiJhIn0sImlkIjoiMSJ9","size":2}}',
            $written['next'] ?? null,
        );
    }

    /**
     * an empty page carries the boundary the request named as its first cursor,
     * so a client that ran past the end can walk back
     */
    public function testAnEmptyCursorPageWalksBackToTheBoundaryTheRequestNamed(): void
    {
        $after = new Cursor(['name' => 'z'], '9');

        $written = self::written(self::page(
            [],
            Strategy::Cursor,
            size: 2,
            hasPrev: true,
            firstCursor: $after,
        ));

        self::assertSame(
            [
                'prev' => '/pets?' . json_encode(['page' => ['before' => CursorText::encode($after), 'size' => 2]]),
                'next' => null,
            ],
            $written,
        );
    }

    /**
     * @param array<string, int|string> $family
     */
    #[DataProvider('firstPages')]
    public function testEveryLinkRepeatsTheUsedPageSizeTheClientNeverSent(Page $page, array $family): void
    {
        self::assertSame(
            '/pets?' . json_encode(['page' => $family]),
            self::written($page, query: [])['next'] ?? null,
        );
    }

    /**
     * @return iterable<string, array{Page, array<string, int|string>}>
     */
    public static function firstPages(): iterable
    {
        yield 'page number' => [
            self::page([], Strategy::PageNumber, size: 3, number: 1, hasNext: true),
            ['number' => 2, 'size' => 3],
        ];
        yield 'offset' => [
            self::page([], Strategy::Offset, size: 3, offset: 0, hasNext: true),
            ['offset' => 3, 'limit' => 3],
        ];
        yield 'cursor' => [
            self::page([], Strategy::Cursor, size: 3, hasNext: true, lastCursor: new Cursor(['name' => 'a'], '1')),
            ['after' => 'eyJzb3J0Ijp7Im5hbWUiOiJhIn0sImlkIjoiMSJ9', 'size' => 3],
        ];
    }

    public function testALinkRepeatsTheQueryOfTheRequestAndReplacesThePositionMembersAlone(): void
    {
        $written = self::written(
            self::page([], Strategy::PageNumber, size: 2, number: 1, hasNext: true),
            query: [
                'include' => 'owner',
                'page' => [
                    'number' => '1',
                    'size' => '2',
                    'offset' => '0',
                    'limit' => '2',
                    'after' => 'x',
                    'before' => 'y',
                    'with_count' => 'false',
                ],
                'sort' => 'name',
            ],
        );

        self::assertSame(
            '/pets?{"include":"owner","page":{"with_count":"false","number":2,"size":2},"sort":"name"}',
            $written['next'] ?? null,
        );
    }

    public function testAPageMemberNamedWithDigitsIsRepeatedAndNeverMistakenForAPosition(): void
    {
        $written = self::written(
            self::page([], Strategy::PageNumber, size: 2, number: 1, hasNext: true),
            query: ['page' => [0 => 'kept']],
        );

        self::assertSame('/pets?{"page":{"0":"kept","number":2,"size":2}}', $written['next'] ?? null);
    }

    public function testAPageParameterThatIsNoArrayIsReplacedWhole(): void
    {
        $written = self::written(
            self::page([], Strategy::PageNumber, size: 2, number: 1, hasNext: true),
            query: ['page' => '1'],
        );

        self::assertSame('/pets?{"page":{"number":2,"size":2}}', $written['next'] ?? null);
    }

    public function testThePaginationLinksOfARelationshipObjectCarryThePageFamilyAlone(): void
    {
        $written = self::written(
            self::page([self::record('1')], Strategy::PageNumber, size: 1, number: 1, hasNext: true),
            self::route(RouteKind::Relationship),
            query: [],
        );

        self::assertSame(
            [
                'first' => '/pets/1/relationships/orders?{"page":{"number":1,"size":1}}',
                'prev' => null,
                'next' => '/pets/1/relationships/orders?{"page":{"number":2,"size":1}}',
            ],
            $written,
        );
    }

    public function testThePaginationLinksOfTheRelatedRouteAddressThatRoute(): void
    {
        $written = self::written(
            self::page([], Strategy::PageNumber, size: 1, number: 1, hasNext: true),
            self::route(RouteKind::Related),
        );

        self::assertSame('/pets/1/orders?{"page":{"number":2,"size":1}}', $written['next'] ?? null);
    }

    /**
     * the links of a page of an endpoint lead to the URL of the endpoint,
     * filled with the path of the request
     */
    public function testThePaginationLinksOfAnEndpointLeadToItsOwnUrl(): void
    {
        $endpoint = Samples::endpoint(
            'recentOrders',
            'GET',
            '/customers/{customer_id}/recent-orders',
            [],
            ['customer_id' => new DigitsCodec()],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Page, 'orders', null, null, null, null)],
        );

        $links = self::writer()->endpointLinks(
            new Links([Member::SELF => '/customers/4/recent-orders']),
            self::page([], Strategy::PageNumber, size: 2, number: 2, hasNext: true, hasPrev: true),
            $endpoint,
            ['customer_id' => '4'],
            ['sort' => '-placedAt'],
        );

        self::assertSame(
            [
                'self' => '/customers/4/recent-orders',
                'first' => '/customers/4/recent-orders?{"sort":"-placedAt","page":{"number":1,"size":2}}',
                'prev' => '/customers/4/recent-orders?{"sort":"-placedAt","page":{"number":1,"size":2}}',
                'next' => '/customers/4/recent-orders?{"sort":"-placedAt","page":{"number":3,"size":2}}',
            ],
            array_map(static function (?Link $link): ?string {
                return $link?->href;
            }, $links->all()),
        );
    }

    public function testACursorOfAnEndpointPageThatCannotBeWrittenNamesTheEndpoint(): void
    {
        $page = self::page(
            [self::record('1')],
            Strategy::Cursor,
            hasNext: true,
            lastCursor: new Cursor(['name' => "\xB1\x31"], '1'),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the boundary cursor of the next link of a page of the endpoint "searchPets"'
            . ' cannot be written as text');

        self::writer()->endpointLinks(new Links([]), $page, Samples::pageEndpoint(), [], []);
    }

    /**
     * @param array{RouteKind, ?string, ?string, ?string} $route
     */
    #[DataProvider('routesThatPaginateNothing')]
    public function testRefusesARouteThatCarriesNoPaginatedData(array $route): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('the %s route carries no paginated data', $route[0]->value));

        self::written(self::page([], Strategy::PageNumber, number: 1), new RouteTarget(...$route));
    }

    /**
     * @return iterable<string, array{array{RouteKind, ?string, ?string, ?string}}>
     */
    public static function routesThatPaginateNothing(): iterable
    {
        yield 'the resource route' => [[RouteKind::Resource, 'pets', '1', null]];
        yield 'the atomic route' => [[RouteKind::Atomic, null, null, null]];
    }

    public function testRefusesSuchARouteEvenWhereEveryLinkWouldBeNull(): void
    {
        $this->expectException(LogicException::class);

        self::written(self::page([], Strategy::Cursor), new RouteTarget(RouteKind::Atomic, null, null, null));
    }

    #[DataProvider('pagesThatLeftTheirOwnMemberNull')]
    public function testRefusesAPageWhoseStrategyLeftItsOwnMemberNull(Page $page, string $member): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf(
            'a page of the %s strategy carries no %s',
            $page->strategy->value,
            $member,
        ));

        self::written($page);
    }

    /**
     * @return iterable<string, array{Page, string}>
     */
    public static function pagesThatLeftTheirOwnMemberNull(): iterable
    {
        yield 'a prev link without page[number]' => [
            self::page([], Strategy::PageNumber, hasPrev: true),
            'page[number]',
        ];
        yield 'a next link without page[number]' => [
            self::page([], Strategy::PageNumber, hasNext: true),
            'page[number]',
        ];
        yield 'a prev link without page[offset]' => [
            self::page([], Strategy::Offset, hasPrev: true),
            'page[offset]',
        ];
        yield 'a next link without page[offset]' => [
            self::page([], Strategy::Offset, hasNext: true),
            'page[offset]',
        ];
        yield 'a prev link without the cursor of the first record' => [
            self::page([], Strategy::Cursor, hasPrev: true),
            'the cursor of its first record',
        ];
        yield 'a next link without the cursor of the last record' => [
            self::page([], Strategy::Cursor, hasNext: true),
            'the cursor of its last record',
        ];
    }

    public function testRefusesABoundaryCursorThatCannotBeWrittenAsText(): void
    {
        $page = self::page(
            [self::record('1')],
            Strategy::Cursor,
            hasNext: true,
            lastCursor: new Cursor(['name' => "\xB1\x31"], '1'),
        );

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the boundary cursor of the next link of a page of the collection route cannot'
            . ' be written as text');

        self::written($page);
    }

    public function testTheMetadataCarriesTheThreeMembersTheStoreAndTheRangeReported(): void
    {
        $page = self::page([], Strategy::Cursor, total: 40, estimatedTotal: 50, rangeTruncated: true);

        self::assertSame(
            ['total' => 40, 'estimated_total' => ['best_guess' => 50], 'range_truncated' => true],
            self::writer()->metadata($page),
        );
    }

    public function testTheMetadataNamesTheMembersOfTheProfileAsTheCamelCasePolicySpellsThem(): void
    {
        $page = self::page([], Strategy::Cursor, total: 40, estimatedTotal: 50, rangeTruncated: true);

        self::assertSame(
            ['total' => 40, 'estimatedTotal' => ['bestGuess' => 50], 'rangeTruncated' => true],
            self::writer(NamingPolicy::CamelCase)->metadata($page),
        );
    }

    public function testAZeroTotalIsWrittenAndAnUncountedPageWritesNone(): void
    {
        self::assertSame(
            ['total' => 0, 'estimated_total' => ['best_guess' => 0]],
            self::writer()->metadata(self::page([], Strategy::PageNumber, number: 1, total: 0, estimatedTotal: 0)),
        );
    }

    public function testAPageThatReportsNothingWritesNoMetadata(): void
    {
        self::assertSame([], self::writer()->metadata(self::page([], Strategy::PageNumber, number: 1)));
    }

    public function testALinkagePageReportsNothing(): void
    {
        $linkage = self::page([self::record('1')], Strategy::PageNumber, size: 1, number: 1, hasNext: true);

        self::assertSame([], self::writer()->metadata($linkage));
    }

    /**
     * link name => href; a link written as null stays a null entry, apart from an absent one
     *
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, string|null>
     */
    private static function written(
        Page $page,
        ?RouteTarget $route = null,
        array $query = [],
        ?Links $links = null,
    ): array {
        $written = self::writer()->links(
            $links ?? new Links([]),
            $page,
            $route ?? self::route(RouteKind::Collection),
            $query,
        );

        return array_map(static function (?Link $link): ?string {
            return $link?->href;
        }, $written->all());
    }

    /**
     * `pets` responds to the reads of its collection and of its to-many relationship `orders`,
     * which the targets of the tests name
     */
    private static function writer(NamingPolicy $naming = NamingPolicy::SnakeCase): PaginationWriter
    {
        $registry = new ResourceRegistry([
            Samples::resource(
                'pets',
                relationships: [Samples::toMany('orders', 'orders')],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);

        return new PaginationWriter(
            new RouteUrls(RouteTable::of($registry, [], []), PlainUrls::jsonQuery()),
            $naming,
        );
    }

    private static function route(RouteKind $kind): RouteTarget
    {
        return match ($kind) {
            RouteKind::Collection => new RouteTarget($kind, 'pets', null, null),
            RouteKind::Resource => new RouteTarget($kind, 'pets', '1', null),
            RouteKind::Related, RouteKind::Relationship => new RouteTarget($kind, 'pets', '1', 'orders'),
            RouteKind::Atomic => new RouteTarget($kind, null, null, null),
        };
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
