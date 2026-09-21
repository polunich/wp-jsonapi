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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Processor;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Processor\FetchCollectionProcessor;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingStore;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(FetchCollectionProcessor::class)]
final class FetchCollectionProcessorTest extends TestCase
{
    public function testTheReadingReachesTheProjectAsOneCollectionQueryOfTheType(): void
    {
        $filter = new Condition(new FieldPath(['name']), '@eq', 'hello');
        $languages = new LanguagePreferences([]);
        $queryParameters = new Values(['nearBy' => 'park']);
        $operation = self::operation(
            filter: $filter,
            languages: $languages,
            page: PageRequest::pageNumber(totalRequested: false),
            queryParameters: $queryParameters,
        );
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process($operation, self::context());

        self::assertCount(1, $store->queries);
        self::assertSame('pets', $store->queries[0]->type);
        self::assertSame($filter, $store->queries[0]->filter);
        self::assertSame($languages, $store->queries[0]->languages);
        self::assertFalse($store->queries[0]->totalRequested);
        self::assertNull($store->queries[0]->parent);
        self::assertSame($queryParameters, $store->queries[0]->queryParameters);
    }

    /**
     * `owner.name` is read off the owner of each boundary record, which the cursors load
     */
    public function testTheBoundaryCursorsReadTheirRelatedRecordsInTheLanguagesOfTheRequest(): void
    {
        $languages = new LanguagePreferences([new LanguageRange('uk')]);
        $store = self::store([new Pet('1', 'first', '101')], ['customers' => ['101' => new Pet('101', 'Ann')]]);

        self::processor($store)->process(
            self::operation(
                sort: Sort::fromString('owner.name'),
                page: PageRequest::cursor(),
                languages: $languages,
                queryParameters: new Values(['nearBy' => 'park']),
            ),
            self::context(),
        );

        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame($languages, $store->loads[0]->languages);
        self::assertSame([], $store->loads[0]->queryParameters->names());
    }

    #[DataProvider('readingSorts')]
    public function testTheSortOfTheReadingIsTheClientSortOrTheDefaultSortWithIdAppended(
        string $written,
        string $read,
    ): void {
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process(
            self::operation(sort: $written === '' ? new Sort([]) : Sort::fromString($written)),
            self::context(),
        );

        self::assertSame($read, $store->queries[0]->sort->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readingSorts(): iterable
    {
        yield 'a request without a sort reads the declared default sort' => ['', '-name,id'];
        yield 'a sort of the client is read as written' => ['name', 'name,id'];
        yield 'a sort that already orders by id is left as it is' => ['-id', '-id'];
        yield 'id is appended last, behind every field the client wrote' => ['-name', '-name,id'];
    }

    public function testACollectionWithoutADeclaredDefaultSortIsReadByIdAscending(): void
    {
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process(self::operation(type: 'customers'), self::context());

        self::assertSame('id', $store->queries[0]->sort->toString());
    }

    #[DataProvider('windows')]
    public function testTheWindowOfTheReadingIsThePageAndOneProbeRecord(
        PageRequest $page,
        int $offset,
        int $limit,
    ): void {
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process(self::operation(page: $page), self::context());

        self::assertSame($offset, $store->queries[0]->window->offset);
        self::assertSame($limit, $store->queries[0]->window->limit);
    }

    /**
     * @return iterable<string, array{PageRequest, int, int}>
     */
    public static function windows(): iterable
    {
        yield 'the first page of the declared size' => [PageRequest::pageNumber(), 0, 3];
        yield 'the third page of a size the client named' => [PageRequest::pageNumber(3, 5), 10, 6];
        yield 'an offset page' => [PageRequest::offset(7, 4), 7, 5];
        yield 'a cursor page, whose seek is in the filter' => [PageRequest::cursor(self::cursor('a')), 0, 3];
    }

    public function testAPageBeforeACursorIsReadBackwardsAndTheRecordsAreTurnedBackAround(): void
    {
        $store = self::store([new Pet('2', 'second'), new Pet('1', 'first')]);

        $result = self::processor($store)->process(
            self::operation(page: PageRequest::cursor(before: self::cursor('9'), size: 2)),
            self::context(),
        );

        self::assertSame('name,-id', $store->queries[0]->sort->toString());
        self::assertSame(['1', '2'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $result->records,
        ));
    }

    public function testTheProbeRecordIsTrimmedAndSaysThatANextPageFollows(): void
    {
        $store = self::store([new Pet('1', 'first'), new Pet('2', 'second'), new Pet('3', 'third')]);

        $page = self::pageOf(self::processor($store)->process(self::operation(), self::context()));

        self::assertSame(['1', '2'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $page->records,
        ));
        self::assertTrue($page->hasNext);
        self::assertSame(2, $page->size);
        self::assertSame(Strategy::PageNumber, $page->strategy);
    }

    public function testAPageThatFillsNoProbeHasNoNextPageAndCarriesTheTotalsOfTheStore(): void
    {
        $store = self::store([new Pet('1', 'first')], total: 17, estimatedTotal: 20);

        $page = self::pageOf(self::processor($store)->process(self::operation(), self::context()));

        self::assertFalse($page->hasNext);
        self::assertSame(17, $page->total);
        self::assertSame(20, $page->estimatedTotal);
    }

    public function testTheGraphIsBuiltOverTheRecordsOfThePageAndNotOverTheProbe(): void
    {
        $store = self::store([
            new Pet('1', 'first', '7'),
            new Pet('2', 'second', '8'),
            new Pet('3', 'third', '9'),
        ]);

        $graph = self::processor($store)->process(self::operation(), self::context())->graph;

        self::assertSame('7', $graph->linkage('pets', '1', 'owner')?->record()?->id);
        self::assertSame('8', $graph->linkage('pets', '2', 'owner')?->record()?->id);
        self::assertNull($graph->linkage('pets', '3', 'owner'));
    }

    public function testTheDocumentIsCompoundExactlyWhenTheRequestSuppliesIncludeAndThePathsAreLoaded(): void
    {
        $store = self::store(
            [new Pet('1', 'first', '7')],
            records: ['customers' => ['7' => new Pet('7', 'the owner')]],
        );

        $plain = self::processor($store)->process(self::operation(), self::context());
        $compound = self::processor($store)->process(
            self::operation(include: new IncludePaths([new IncludePath(['owner'])])),
            self::context(includeSupplied: true),
        );

        self::assertFalse($plain->graph->isCompound());
        self::assertSame([], $plain->graph->included());
        self::assertTrue($compound->graph->isCompound());
        self::assertSame(['7'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $compound->graph->included(),
        ));
    }

    public function testTheHintOfTheReadingCarriesTheFieldsetOfTheClientAndTheFieldsTheLibraryReads(): void
    {
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process(
            self::operation(sort: Sort::fromString('name'), fields: new SparseFieldsets(['pets' => ['name']])),
            self::context(),
        );

        self::assertSame(['name', 'owner'], $store->queries[0]->fields->fields());
    }

    public function testAReadingWithoutAFieldsetOfTheTypeHintsEveryField(): void
    {
        $store = self::store([new Pet('1', 'first')]);

        self::processor($store)->process(self::operation(), self::context());

        self::assertTrue($store->queries[0]->fields->hasEveryField());
    }

    public function testTheResultIsA200OfTheTypeWithTheFieldsetsAndNoEntityTag(): void
    {
        $fields = new SparseFieldsets(['pets' => ['name']]);
        $store = self::store([new Pet('1', 'first')]);

        $result = self::processor($store)->process(self::operation(fields: $fields), self::context());

        self::assertSame('pets', $result->type);
        self::assertSame(200, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame($fields, $result->fields);
    }

    public function testAnEmptyCollectionIsAnEmptyPageAndNoOtherResult(): void
    {
        $result = self::processor(self::store([]))->process(self::operation(), self::context());

        self::assertSame([], $result->records);
        self::assertFalse(self::pageOf($result)->hasNext);
        self::assertSame(200, $result->status());
    }

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'a fetch_collection processor processes a fetch_collection operation, and this one is fetch_resource',
        );

        self::processor(self::store([]))->process(
            new FetchResource(
                new Identifier('pets', '1'),
                null,
                new SparseFieldsets([]),
                new LanguagePreferences([]),
                new Values([]),
            ),
            self::context(),
        );
    }

    public function testATypeThatBindsNoCollectionReaderIsRefusedBeforeAnyReading(): void
    {
        $store = self::store([]);

        try {
            self::processor($store)->process(self::operation(type: 'tags'), self::context());
            self::fail('a collection was read for a type that binds no collection reader');
        } catch (LogicException $refusal) {
            self::assertStringStartsWith('type "tags" binds no collection_reader: ', $refusal->getMessage());
            self::assertSame([], $store->queries);
        }
    }

    public function testAReaderThatReturnsMoreRecordsThanTheWindowAskedForIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::processor(self::store([
            new Pet('1', 'first'),
            new Pet('2', 'second'),
            new Pet('3', 'third'),
            new Pet('4', 'fourth'),
        ]))->process(self::operation(), self::context());
    }

    public function testTheProcessorIsInternalAndImmutable(): void
    {
        $processor = new ReflectionClass(FetchCollectionProcessor::class);

        self::assertStringContainsString('@internal', (string) $processor->getDocComment());
        self::assertTrue($processor->isFinal());
        self::assertTrue($processor->isReadOnly());
    }

    /**
     * @param ?Values<mixed> $queryParameters
     */
    private static function operation(
        string $type = 'pets',
        ?FilterNode $filter = null,
        ?Sort $sort = null,
        ?PageRequest $page = null,
        ?IncludePaths $include = null,
        ?SparseFieldsets $fields = null,
        ?LanguagePreferences $languages = null,
        ?Values $queryParameters = null,
    ): FetchCollection {
        return new FetchCollection(
            $type,
            $filter,
            $sort ?? new Sort([]),
            $page ?? PageRequest::pageNumber(),
            $include,
            $fields ?? new SparseFieldsets([]),
            $languages ?? new LanguagePreferences([]),
            $queryParameters ?? new Values([]),
        );
    }

    /**
     * keyed like the default reading sort of `pets`, `-name,id`
     */
    private static function cursor(string $id): Cursor
    {
        return new Cursor(['name' => 'a name'], $id);
    }

    private static function processor(RecordingStore $store): FetchCollectionProcessor
    {
        $registry = self::registry();
        $resolver = BoundImplementations::serving($store);
        $store = new StoreGateway($registry, $resolver);
        $paginator = new Paginator($registry, $store);

        return new FetchCollectionProcessor(
            $registry,
            $store,
            $paginator,
            new IncludeLoader($registry, $store, $paginator),
        );
    }

    /**
     * @param list<object>                                     $slice
     * @param array<array-key, array<array-key, object>>       $records
     * @param array<array-key, array<array-key, list<object>>> $members
     */
    private static function store(
        array $slice,
        array $records = [],
        array $members = [],
        ?int $total = null,
        ?int $estimatedTotal = null,
    ): RecordingStore {
        return new RecordingStore($slice, $records, $members, $total, $estimatedTotal);
    }

    private static function context(bool $includeSupplied = false): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('GET', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            $includeSupplied,
            false,
            [],
        );
    }

    private static function registry(): ResourceRegistry
    {
        $pets = Samples::resource(
            'pets',
            attributes: [Samples::attribute('name', sortable: true)],
            relationships: [
                Samples::toOne('owner', 'customers'),
                Samples::toMany('orders', 'orders', paginationPolicy: new PaginationPolicy(2, 100)),
            ],
            capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            defaultSort: Sort::fromString('-name'),
            paginationPolicy: new PaginationPolicy(2, 100),
            maxIncludeDepth: 2,
        );
        $customers = Samples::resource(
            'customers',
            attributes: [Samples::attribute('name', sortable: true)],
            capabilities: [Capability::CollectionReader],
            paginationPolicy: new PaginationPolicy(2, 100),
        );
        $orders = Samples::resource('orders', capabilities: [Capability::CollectionReader]);
        $tags = Samples::resource('tags');

        return new ResourceRegistry([$pets, $customers, $orders, $tags]);
    }

    /**
     * a processor returns a page, which the result of a list without pages lacks
     */
    private static function pageOf(CollectionResult $result): Page
    {
        self::assertNotNull($result->page);

        return $result->page;
    }
}
