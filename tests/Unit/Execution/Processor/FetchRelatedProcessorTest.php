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
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Processor\FetchRelatedProcessor;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingStore;
use ReflectionClass;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(FetchRelatedProcessor::class)]
final class FetchRelatedProcessorTest extends TestCase
{
    public function testTheToManyReadingReachesTheProjectAsOneQueryOfTheRelatedTypeUnderTheParentScope(): void
    {
        $languages = new LanguagePreferences([]);
        $queryParameters = new Values(['placedSince' => '2026']);
        $store = self::store(slice: [new Pet('5', 'an order')]);

        self::processor($store)->process(
            self::operation('orders', languages: $languages, queryParameters: $queryParameters),
            self::context(new Pet('1', 'first')),
        );

        self::assertCount(1, $store->queries);

        $scope = $store->queries[0]->parent;

        self::assertInstanceOf(ParentScope::class, $scope);
        self::assertSame('orders', $store->queries[0]->type);
        self::assertSame('pets', $scope->type);
        self::assertSame('1', $scope->id);
        self::assertSame('orders', $scope->relationship);
        self::assertSame($languages, $store->queries[0]->languages);
        self::assertSame($queryParameters, $store->queries[0]->queryParameters);
    }

    /**
     * `seller.name` is read off the seller of each boundary order, which the cursors load
     */
    public function testTheBoundaryCursorsReadTheirRelatedRecordsInTheLanguagesOfTheRequest(): void
    {
        $languages = new LanguagePreferences([new LanguageRange('uk')]);
        $store = self::store(
            slice: [new Pet('5', 'an order', '9')],
            records: ['customers' => ['9' => new Pet('9', 'nine')]],
        );

        self::processor($store)->process(
            self::operation(
                'orders',
                sort: Sort::fromString('seller.name'),
                page: PageRequest::cursor(),
                languages: $languages,
            ),
            self::context(new Pet('1', 'first')),
        );

        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame($languages, $store->loads[0]->languages);
    }

    #[DataProvider('readingSorts')]
    public function testTheToManyReadingRunsTheClientSortOrTheDefaultSortOfTheRelationship(
        string $written,
        string $read,
    ): void {
        $store = self::store(slice: [new Pet('5', 'an order')]);

        self::processor($store)->process(
            self::operation('orders', sort: $written === '' ? new Sort([]) : Sort::fromString($written)),
            self::context(new Pet('1', 'first')),
        );

        self::assertSame($read, $store->queries[0]->sort->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readingSorts(): iterable
    {
        yield 'a request without a sort reads the default sort of the relationship' => ['', '-name,id'];
        yield 'a sort of the client is read as written, the route having the full collection features' => [
            'name',
            'name,id',
        ];
    }

    public function testTheToManyPageIsReadWithAProbeTrimmedAndCarriesTheRelatedType(): void
    {
        $store = self::store(slice: [
            new Pet('5', 'first order'),
            new Pet('6', 'second order'),
            new Pet('7', 'third order'),
        ]);

        $result = self::processor($store)->process(
            self::operation('orders'),
            self::context(new Pet('1', 'first')),
        );

        self::assertInstanceOf(CollectionResult::class, $result);
        self::assertSame(3, $store->queries[0]->window->limit);
        self::assertSame('orders', $result->type);
        self::assertSame(['5', '6'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $result->records,
        ));
        self::assertTrue(self::pageOf($result)->hasNext);
    }

    public function testTheGraphOfTheRelatedRouteIsWalkedFromTheRelatedRecords(): void
    {
        $store = self::store(
            slice: [new Pet('5', 'an order', '9')],
            records: ['customers' => ['9' => new Pet('9', 'the seller')]],
        );

        $graph = self::processor($store)->process(
            self::operation('orders', include: new IncludePaths([new IncludePath(['seller'])])),
            self::context(new Pet('1', 'first'), includeSupplied: true),
        )->graph;

        self::assertTrue($graph->isCompound());
        self::assertSame(['9'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $graph->included(),
        ));
        self::assertSame('9', $graph->linkage('orders', '5', 'seller')?->record()?->id);
    }

    public function testTheEmptyToOneRelationshipWithAnAccessorGetsNullAndNoReading(): void
    {
        $store = self::store();

        $result = self::processor($store)->process(
            self::operation('owner'),
            self::context(new Pet('1', 'first', null)),
        );

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertNull($result->resource);
        self::assertSame('customers', $result->type);
        self::assertSame([], $store->loads);
    }

    public function testTheToOneRelationshipWithAnAccessorReadsTheRecordByItsIdWithTheFieldHint(): void
    {
        $languages = new LanguagePreferences([]);
        $queryParameters = new Values(['placedSince' => '2026']);
        $store = self::store(records: ['customers' => ['7' => new Pet('7', 'the owner')]]);

        $result = self::processor($store)->process(
            self::operation(
                'owner',
                fields: new SparseFieldsets(['customers' => ['name']]),
                languages: $languages,
                queryParameters: $queryParameters,
            ),
            self::context(new Pet('1', 'first', '7')),
        );

        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame(['7'], $store->loads[0]->ids);
        self::assertSame(['name'], $store->loads[0]->fields->fields());
        self::assertSame($languages, $store->loads[0]->languages);
        self::assertSame($queryParameters, $store->loads[0]->queryParameters);
        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame('7', $result->resource?->id);
    }

    public function testAnInvisibleRelatedRecordOfAToOneWithAnAccessorGets404(): void
    {
        try {
            self::processor(self::store())->process(
                self::operation('owner'),
                self::context(new Pet('1', 'first', '7')),
            );
            self::fail('a related record the loader did not give got data: null');
        } catch (NotFoundException $missing) {
            self::assertSame(404, $missing->status());
            self::assertCount(1, $missing->descriptions());
            self::assertSame(ErrorCode::NotFound, $missing->descriptions()[0]->code);
        }
    }

    public function testTheToOneRelationshipWithoutAnAccessorIsReadThroughTheRelatedLoaderWithTheLimitOne(): void
    {
        $queryParameters = new Values(['placedSince' => '2026']);
        $store = self::store(members: ['breeder' => ['1' => [new Pet('9', 'the breeder')]]]);

        $result = self::processor($store)->process(
            self::operation('breeder', queryParameters: $queryParameters),
            self::context(new Pet('1', 'first')),
        );

        self::assertCount(1, $store->relations);
        self::assertSame('pets', $store->relations[0]->type);
        self::assertSame(['1'], $store->relations[0]->parentIds);
        self::assertSame('breeder', $store->relations[0]->relationship);
        self::assertSame('id', $store->relations[0]->sort->toString());
        self::assertSame(1, $store->relations[0]->limit);
        self::assertSame($queryParameters, $store->relations[0]->queryParameters);
        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame('9', $result->resource?->id);
    }

    public function testAnEmptyReadingOfAToOneWithoutAnAccessorIs200WithNullAndNever404(): void
    {
        $result = self::processor(self::store())->process(
            self::operation('breeder'),
            self::context(new Pet('1', 'first')),
        );

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertNull($result->resource);
    }

    #[DataProvider('relatedRoutes')]
    public function testTheRelatedRouteWritesNoEntityTagOnEitherBranch(string $relationship): void
    {
        $store = self::store(
            slice: [new Pet('5', 'an order')],
            records: ['customers' => ['7' => new Pet('7', 'the owner')]],
        );

        $result = self::processor($store)->process(
            self::operation($relationship),
            self::context(new Pet('1', 'first', '7')),
        );

        self::assertNull($result->entityTag());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function relatedRoutes(): iterable
    {
        yield 'a to-many relationship' => ['orders'];
        yield 'a to-one relationship the parent identifies' => ['owner'];
    }

    public function testTheDocumentOfAnEmptyToOneIsCompoundWhenTheRequestSuppliesInclude(): void
    {
        $result = self::processor(self::store())->process(
            self::operation('owner', include: new IncludePaths([])),
            self::context(new Pet('1', 'first', null), includeSupplied: true),
        );

        self::assertTrue($result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'a fetch_related processor processes a fetch_related operation, and this one is fetch_resource',
        );

        self::processor(self::store())->process(
            new FetchResource(
                new Identifier('pets', '1'),
                null,
                new SparseFieldsets([]),
                new LanguagePreferences([]),
                new Values([]),
            ),
            self::context(new Pet('1', 'first')),
        );
    }

    public function testARelationshipTheParentDoesNotDeclareIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "pets" declares no relationship "breeders"');

        self::processor(self::store())->process(
            self::operation('breeders'),
            self::context(new Pet('1', 'first')),
        );
    }

    public function testAResourceLoaderThatBreaksTheBatchRuleIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::processor(self::brokenLoader([]))->process(
            self::operation('owner'),
            self::context(new Pet('1', 'first', '7')),
        );
    }

    public function testARelatedLoaderThatReturnsMoreRecordsThanTheLimitIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::processor(self::store(members: [
            'breeder' => ['1' => [new Pet('8', 'one breeder'), new Pet('9', 'another breeder')]],
        ], honoursLimit: false))->process(
            self::operation('breeder'),
            self::context(new Pet('1', 'first')),
        );
    }

    public function testTheProcessorIsInternalAndImmutable(): void
    {
        $processor = new ReflectionClass(FetchRelatedProcessor::class);

        self::assertStringContainsString('@internal', (string) $processor->getDocComment());
        self::assertTrue($processor->isFinal());
        self::assertTrue($processor->isReadOnly());
    }

    /**
     * @param ?Values<mixed> $queryParameters
     */
    private static function operation(
        string $relationship,
        ?FilterNode $filter = null,
        ?Sort $sort = null,
        ?PageRequest $page = null,
        ?IncludePaths $include = null,
        ?SparseFieldsets $fields = null,
        ?LanguagePreferences $languages = null,
        ?Values $queryParameters = null,
    ): FetchRelated {
        return new FetchRelated(
            new Identifier('pets', '1'),
            $relationship,
            $filter,
            $sort ?? new Sort([]),
            $page ?? PageRequest::pageNumber(),
            $include,
            $fields ?? new SparseFieldsets([]),
            $languages ?? new LanguagePreferences([]),
            $queryParameters ?? new Values([]),
        );
    }

    private static function processor(object $store): FetchRelatedProcessor
    {
        $registry = self::registry();
        $resolver = BoundImplementations::serving($store);
        $store = new StoreGateway($registry, $resolver);
        $paginator = new Paginator($registry, $store);

        return new FetchRelatedProcessor(
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
        array $slice = [],
        array $records = [],
        array $members = [],
        bool $honoursLimit = true,
    ): RecordingStore {
        return new RecordingStore($slice, $records, $members, honoursLimit: $honoursLimit);
    }

    /**
     * @param list<object|null> $records
     *
     * @return ResourceLoader<object>
     */
    private static function brokenLoader(array $records): ResourceLoader
    {
        return new class ($records) implements ResourceLoader {
            /**
             * @param list<object|null> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<object|null>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                return $this->records;
            }
        };
    }

    private static function context(Pet $record, bool $includeSupplied = false): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('GET', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            $includeSupplied,
            false,
            [],
            $record,
        );
    }

    private static function registry(): ResourceRegistry
    {
        $orders = Samples::resource(
            'orders',
            attributes: [Samples::attribute('name', sortable: true)],
            relationships: [Samples::toOne('seller', 'customers')],
            capabilities: [Capability::CollectionReader],
        );
        $customers = Samples::resource('customers', attributes: [Samples::attribute('name', sortable: true)]);

        return new ResourceRegistry([self::pets(), $orders, $customers]);
    }

    /**
     * versioned, since only a versioned parent shows that the related route writes no tag
     */
    private static function pets(): ResourceMetadata
    {
        return new ResourceMetadata(
            'pets',
            Samples::id(),
            'id',
            ['name' => Samples::attribute('name', sortable: true)],
            [
                'orders' => Samples::toMany(
                    'orders',
                    'orders',
                    defaultSort: Sort::fromString('-name'),
                    paginationPolicy: new PaginationPolicy(2, 100),
                ),
                'owner' => Samples::toOne('owner', 'customers'),
                'breeder' => Samples::toOne('breeder', 'customers', readsRelatedId: false),
            ],
            [
                Capability::ResourceLoader->value => Samples::implementation(),
                Capability::CollectionReader->value => Samples::implementation(),
                Capability::RelatedLoader->value => Samples::implementation(),
            ],
            [
                OperationKind::FetchResource->value => Samples::implementation(),
                OperationKind::FetchCollection->value => Samples::implementation(),
                OperationKind::FetchRelated->value => Samples::implementation(),
                OperationKind::FetchRelationship->value => Samples::implementation(),
            ],
            [],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            new VersionPolicy(static function (Pet $pet): string {
                return $pet->version;
            }, false),
            null,
            new PaginationPolicy(2, 100),
            2,
            null,
        );
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
