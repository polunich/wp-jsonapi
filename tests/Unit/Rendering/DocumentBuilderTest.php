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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Document\AtomicResultsDocument;
use Polunich\WpJsonApi\Document\DataDocument;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\MetaDocument;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use Polunich\WpJsonApi\Document\ResourceObject;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Rendering\DocumentBuilder;
use Polunich\WpJsonApi\Rendering\LinkQuery;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\ResourceSerializer;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;

/**
 * records of every type are fixture `Pet`s, which the `Samples` accessors read.
 * the serializer, the pagination writer and the link query are the real ones:
 * with the builder they write one document, which doubles would not show
 *
 * @internal
 */
#[CoversClass(DocumentBuilder::class)]
final class DocumentBuilderTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(DocumentBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('resultsWithoutContent')]
    public function testAResultThatCarriesNoContentBecomesNoDocument(Result $result): void
    {
        self::assertNull(self::builder()->document(
            $result,
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        ));
    }

    /**
     * @return array<string, array{Result}>
     */
    public static function resultsWithoutContent(): array
    {
        return [
            'no content' => [new NoContentResult()],
            'not modified' => [new NotModifiedResult(new EntityTag('v1'))],
        ];
    }

    /**
     * `Result` is an open interface:
     * a foreign implementation fails loudly instead of becoming a document without primary data
     */
    public function testAResultOfAnUnknownClassIsRefused(): void
    {
        $result = new class implements Result {
            #[Override]
            public function status(): int
            {
                return 200;
            }

            #[Override]
            public function entityTag(): ?EntityTag
            {
                return null;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is no result this library writes a document of');

        self::builder()->document($result, self::target(RouteKind::Resource), self::request(), MediaType::jsonApi());
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('routesOfADocument')]
    public function testTheSelfLinkOfADocumentIsTheRouteOfTheRequestWithItsQuery(
        RouteKind $kind,
        array $query,
        string $expected,
    ): void {
        $document = self::builder()->document(
            self::resourceResult(),
            self::target($kind),
            self::request($query),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame($expected, self::href($document->links, 'self'));
    }

    /**
     * @return array<string, array{RouteKind, array<array-key, mixed>, string}>
     */
    public static function routesOfADocument(): array
    {
        return [
            'resource route' => [RouteKind::Resource, [], '/pets/1'],
            'resource route with a query' => [
                RouteKind::Resource,
                ['include' => 'owner'],
                '/pets/1?include=owner',
            ],
            'related route' => [RouteKind::Related, [], '/pets/1/owner'],
        ];
    }

    public function testALinkCarriesNoParameterWordPressConsumes(): void
    {
        $document = self::builder()->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            self::request([
                '_method' => 'DELETE',
                'rest_route' => '/fixture/v1/pets/1',
                '_wpnonce' => 'n',
                'include' => 'owner',
            ]),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1?include=owner', self::href($document->links, 'self'));
    }

    public function testTheSelfLinkOfAnUpdateLeavesOutAParameterOnlyTheUpdateReads(): void
    {
        $document = self::builder(self::registryWithParameters())->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            new ServerRequest(
                'PATCH',
                new Headers([]),
                ['notifyOwner' => 'true', 'include' => 'owner', 'previewMode' => 'on'],
                [],
                '',
            ),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1?include=owner&previewMode=on', self::href($document->links, 'self'));
    }

    public function testTheSelfLinkOfACreatedResourceLeavesOutAParameterOnlyTheCreateReads(): void
    {
        $document = self::builder(self::registryWithParameters())->document(
            new ResourceResult(
                'pets',
                self::record('pets', '7'),
                new RecordGraph(false),
                new SparseFieldsets([]),
                201,
                null,
            ),
            self::target(RouteKind::Collection),
            new ServerRequest('POST', new Headers([]), ['include' => 'owner', 'notifyOwner' => 'true'], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/7?include=owner', self::href($document->links, 'self'));
    }

    public function testThePagesOfARelationshipWriteLeaveOutAParameterOnlyTheWriteReads(): void
    {
        $document = self::builder(self::registryWithParameters())->document(
            self::relationshipResult(RecordLinkage::toMany(
                self::page([self::record('orders', '3')], hasNext: true),
            )),
            self::target(RouteKind::Relationship),
            new ServerRequest('POST', new Headers([]), ['notifyOwner' => 'true', 'include' => 'orders'], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1/relationships/orders?include=orders', self::href($document->links, 'self'));
        self::assertSame(
            '/pets/1/relationships/orders?include=orders&page%5Bnumber%5D=2&page%5Bsize%5D=2',
            self::href($document->links, 'next'),
        );
    }

    public function testAnEndpointLinksItsUrlWithoutAParameterWordPressConsumes(): void
    {
        $endpoint = Samples::endpoint(
            method: 'GET',
            queryParameters: [new QueryParameterMetadata('listedAfter', new StringCodec(), false)],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null)],
        );

        $document = self::builder()->endpointDocument(
            CollectionResult::whole('pets', [], new RecordGraph(false), new SparseFieldsets([])),
            $endpoint,
            new ServerRequest('GET', new Headers([]), ['listedAfter' => '2026', '_wpnonce' => 'n'], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1/adopt?listedAfter=2026', self::href($document->links, 'self'));
    }

    public function testTheUpdateOfAResourceWhoseFetchIsExcludedLinksNothing(): void
    {
        $document = self::builder(self::registry(self::exceptResource(OperationKind::FetchResource)))->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            new ServerRequest('PATCH', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertTrue($document->links->isEmpty());
        self::assertInstanceOf(ResourceObject::class, $document->data);
        self::assertTrue($document->data->links->isEmpty());
    }

    public function testACreatedResourceWhoseFetchIsExcludedIsLinkedNowhere(): void
    {
        $document = self::builder(self::registry(self::exceptResource(OperationKind::FetchResource)))->document(
            new ResourceResult(
                'pets',
                self::record('pets', '7'),
                new RecordGraph(false),
                new SparseFieldsets([]),
                201,
                null,
            ),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertTrue($document->links->isEmpty());
    }

    public function testTheRelatedLinkOfARelationshipDocumentStandsWhileTheRelatedRouteResponds(): void
    {
        $document = self::builder(self::registry(orders: self::exceptRelationshipRead(
            OperationKind::FetchRelated,
        )))->document(
            self::relationshipResult(RecordLinkage::toMany(self::page([self::record('orders', '3')]))),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1/relationships/orders', self::href($document->links, 'self'));
        self::assertArrayNotHasKey('related', $document->links->all());
    }

    /**
     * a write of a to-one relationship responds with 200
     * on a path whose GET the relationship excludes
     */
    public function testTheSelfLinkOfARelationshipDocumentStandsWhileTheRelationshipRouteResponds(): void
    {
        $document = self::builder(self::registry(owner: self::exceptRelationshipRead(
            OperationKind::FetchRelationship,
        )))->document(
            new RelationshipResult(
                'pets',
                '1',
                'owner',
                'customers',
                RecordLinkage::toOne(self::record('customers', '9')),
                new RecordGraph(false),
                new SparseFieldsets([]),
                null,
            ),
            new RouteTarget(RouteKind::Relationship, 'pets', '1', 'owner'),
            new ServerRequest('PATCH', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(['related'], array_keys($document->links->all()));
        self::assertSame('/pets/1/owner', self::href($document->links, 'related'));
    }

    public function testAToManyLinkageWithoutItsRelationshipRouteIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the library responds with the linkage of the to-many relationship "orders"'
            . ' of type "pets" where its relationship route');

        self::builder(self::registry(orders: self::exceptRelationshipRead(OperationKind::FetchRelationship)))->document(
            self::relationshipResult(RecordLinkage::toMany(self::page([self::record('orders', '3')]))),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );
    }

    public function testAMonitorWhoseFetchIsExcludedIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the monitor type "pets" excludes the fetch of its resources');

        self::builder(self::registry(self::exceptResource(OperationKind::FetchResource)))->document(
            new AcceptedResult(self::record('pets', '9'), new RecordGraph(false), new SparseFieldsets([])),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );
    }

    public function testThePrimaryDataIsTheResourceOfTheResultAndNullWhereItCarriesNone(): void
    {
        $builder = self::builder();
        $data = $builder->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );
        $empty = $builder->document(
            new ResourceResult('customers', null, new RecordGraph(false), new SparseFieldsets([]), 200, null),
            self::target(RouteKind::Related),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $data);
        self::assertInstanceOf(DataDocument::class, $empty);

        $resource = $data->data;

        self::assertInstanceOf(ResourceObject::class, $resource);
        self::assertSame('pets', $resource->type);
        self::assertSame('1', $resource->id);
        self::assertNull($empty->data);
    }

    /**
     * @param list<string> $attributes
     * @param list<string> $relationships
     */
    #[DataProvider('fieldsetsOfADocument')]
    public function testThePrimaryResourceIsWrittenWithTheFieldsetsOfTheResult(
        SparseFieldsets $fields,
        array $attributes,
        array $relationships,
    ): void {
        $document = self::builder()->document(
            new ResourceResult('pets', self::record('pets', '1'), new RecordGraph(false), $fields, 200, null),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $resource = $document->data;

        self::assertInstanceOf(ResourceObject::class, $resource);
        self::assertSame($attributes, array_keys($resource->attributes));
        self::assertSame($relationships, array_keys($resource->relationships));
    }

    /**
     * @return array<string, array{SparseFieldsets, list<string>, list<string>}>
     */
    public static function fieldsetsOfADocument(): array
    {
        return [
            'no fieldset of the type, so every field' => [
                new SparseFieldsets(['customers' => ['name']]),
                ['name'],
                ['owner', 'orders'],
            ],
            'an attribute alone' => [new SparseFieldsets(['pets' => ['name']]), ['name'], []],
            'a relationship alone' => [new SparseFieldsets(['pets' => ['owner']]), [], ['owner']],
        ];
    }

    public function testTheRelationshipsOfThePrimaryResourceCarryTheLinkageOfTheGraphOfTheResult(): void
    {
        $document = self::builder()->document(
            new ResourceResult(
                'pets',
                self::record('pets', '1'),
                self::linkedGraph(),
                new SparseFieldsets([]),
                200,
                null,
            ),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $resource = $document->data;

        self::assertInstanceOf(ResourceObject::class, $resource);
        self::assertEquals(
            new ResourceIdentifierObject('customers', '9'),
            $resource->relationships['owner']->linkage(),
        );
        self::assertFalse($resource->relationships['orders']->hasLinkage());
    }

    public function testTheSelfLinkOfACreatedResourceIsItsOwnRouteAndNotTheCollectionThePostAddressed(): void
    {
        $document = self::builder()->document(
            new ResourceResult(
                'pets',
                self::record('pets', '7'),
                new RecordGraph(false),
                new SparseFieldsets([]),
                201,
                null,
            ),
            self::target(RouteKind::Collection),
            self::request(['include' => 'owner']),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/7?include=owner', self::href($document->links, 'self'));
    }

    #[DataProvider('graphsOfADocument')]
    public function testTheIncludedMemberIsWrittenExactlyWhenTheGraphIsCompound(
        RecordGraph $graph,
        ?int $expected,
    ): void {
        $document = self::builder()->document(
            new ResourceResult('pets', self::record('pets', '1'), $graph, new SparseFieldsets([]), 200, null),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame($expected, $document->included === null ? null : count($document->included->all()));
    }

    /**
     * @return array<string, array{RecordGraph, ?int}>
     */
    public static function graphsOfADocument(): array
    {
        return [
            'no include supplied' => [new RecordGraph(false), null],
            'include reached nothing' => [new RecordGraph(true), 0],
            'include reached one resource' => [
                new RecordGraph(true, [new ResourceRecord('customers', '9', new Pet('9', 'the owner'))]),
                1,
            ],
        ];
    }

    public function testTheIncludedResourcesAreWrittenAsResourceObjectsInTheOrderOfTheGraph(): void
    {
        $graph = new RecordGraph(true, [
            new ResourceRecord('customers', '9', new Pet('9', 'the owner')),
            new ResourceRecord('orders', '3', new Pet('3', 'the order')),
        ]);

        $document = self::builder()->document(
            new ResourceResult('pets', self::record('pets', '1'), $graph, new SparseFieldsets([]), 200, null),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertNotNull($document->included);

        $included = $document->included->all();

        self::assertSame(
            [['customers', '9'], ['orders', '3']],
            array_map(static function (ResourceObject $object): array {
                return [$object->type, $object->id];
            }, $included),
        );
        self::assertSame('/customers/9', self::href($included[0]->links, 'self'));
    }

    public function testAnIncludedResourceIsWrittenWithTheFieldsetsAndTheGraphOfTheResult(): void
    {
        $graph = new RecordGraph(
            true,
            [self::record('pets', '2')],
            ['pets' => ['2' => ['owner' => RecordLinkage::toOne(self::record('customers', '8'))]]],
        );

        $document = self::builder()->document(
            new ResourceResult(
                'pets',
                self::record('pets', '1'),
                $graph,
                new SparseFieldsets(['pets' => ['owner']]),
                200,
                null,
            ),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertNotNull($document->included);

        $included = $document->included->all()[0];

        self::assertSame([], array_keys($included->attributes));
        self::assertSame(['owner'], array_keys($included->relationships));
        self::assertEquals(
            new ResourceIdentifierObject('customers', '8'),
            $included->relationships['owner']->linkage(),
        );
    }

    #[DataProvider('compoundResults')]
    public function testEveryDocumentWithPrimaryDataWritesTheIncludedMemberOfItsGraph(
        Result $result,
        RouteKind $kind,
    ): void {
        $document = self::builder()->document($result, self::target($kind), self::request(), MediaType::jsonApi());

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertNotNull($document->included);

        $included = $document->included->all();

        self::assertSame(
            [['pets', '2']],
            array_map(static function (ResourceObject $object): array {
                return [$object->type, $object->id];
            }, $included),
        );
        self::assertSame([['name'], []], self::fieldsOf($included[0]));
    }

    /**
     * the primary data is never `pets` 2, which `DataDocument` would drop from `included`
     *
     * @return array<string, array{Result, RouteKind}>
     */
    public static function compoundResults(): array
    {
        $graph = new RecordGraph(true, [self::record('pets', '2')]);
        $fields = new SparseFieldsets(['pets' => ['name']]);

        return [
            'a resource' => [
                new ResourceResult('pets', self::record('pets', '1'), $graph, $fields, 200, null),
                RouteKind::Resource,
            ],
            'a collection' => [
                CollectionResult::paged('pets', self::page([self::record('orders', '3')]), $graph, $fields),
                RouteKind::Collection,
            ],
            'a relationship' => [
                new RelationshipResult(
                    'pets',
                    '1',
                    'orders',
                    'orders',
                    RecordLinkage::toMany(self::page([self::record('orders', '3')])),
                    $graph,
                    $fields,
                    null,
                ),
                RouteKind::Relationship,
            ],
            'an accepted write' => [
                new AcceptedResult(self::record('orders', '5'), $graph, $fields),
                RouteKind::Collection,
            ],
        ];
    }

    public function testACollectionCarriesTheRecordsOfItsPageInOrder(): void
    {
        $document = self::builder()->document(
            CollectionResult::paged(
                'pets',
                self::page([self::record('pets', '1'), self::record('pets', '2')]),
                new RecordGraph(false),
                new SparseFieldsets([]),
            ),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertIsArray($document->data);
        self::assertSame(
            ['1', '2'],
            array_map(static function (ResourceIdentifierObject|ResourceObject $object): string {
                return $object->id;
            }, $document->data),
        );
    }

    public function testEveryRecordOfACollectionIsWrittenWithTheFieldsetsAndTheGraphOfTheResult(): void
    {
        $document = self::builder()->document(
            CollectionResult::paged(
                'pets',
                self::page([self::record('pets', '1'), self::record('pets', '2')]),
                self::linkedGraph(),
                new SparseFieldsets(['pets' => ['owner']]),
            ),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertIsArray($document->data);

        $records = $document->data;

        self::assertSame([[[], ['owner']], [[], ['owner']]], array_map(self::fieldsOf(...), $records));
        self::assertSame(
            ['9', '8'],
            array_map(static function (ResourceIdentifierObject|ResourceObject $object): ?string {
                return self::linkedId(
                    $object,
                    'owner',
                );
            }, $records),
        );
    }

    public function testThePaginationLinksOfACollectionAddressTheRouteOfTheRequestBehindItsSelfLink(): void
    {
        $document = self::builder()->document(
            CollectionResult::paged(
                'pets',
                self::page([self::record('pets', '1')], hasNext: true),
                new RecordGraph(false),
                new SparseFieldsets([]),
            ),
            self::target(RouteKind::Collection),
            self::request(['include' => 'owner']),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $links = $document->links->all();

        self::assertSame('self', array_key_first($links));
        self::assertSame('/pets?include=owner', self::href($document->links, 'self'));
        self::assertSame(
            '/pets?include=owner&page%5Bnumber%5D=2&page%5Bsize%5D=2',
            self::href($document->links, 'next'),
        );
    }

    /**
     * @param ?array<string, mixed> $expected
     */
    #[DataProvider('pagesOfACollection')]
    public function testThePageMetadataOfACollectionStandsUnderThePageMemberOfItsMeta(
        ?int $total,
        ?array $expected,
    ): void {
        $document = self::builder()->document(
            CollectionResult::paged(
                'pets',
                self::page([self::record('pets', '1')], total: $total),
                new RecordGraph(false),
                new SparseFieldsets([]),
            ),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame($expected, $document->meta);
    }

    /**
     * @return array<string, array{?int, ?array<string, mixed>}>
     */
    public static function pagesOfACollection(): array
    {
        return [
            'the store counted the records' => [5, ['page' => ['total' => 5]]],
            'the store counted nothing' => [null, null],
        ];
    }

    public function testTheDocumentOfARelationshipCarriesASelfAndARelatedLinkOfItsRoute(): void
    {
        $document = self::builder()->document(
            self::relationshipResult(RecordLinkage::toOne(self::record('customers', '9'))),
            self::target(RouteKind::Relationship),
            self::request(['include' => 'orders']),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(
            '/pets/1/relationships/orders?include=orders',
            self::href($document->links, 'self'),
        );
        self::assertSame('/pets/1/orders', self::href($document->links, 'related'));
    }

    public function testThePrimaryDataOfARelationshipIsItsLinkage(): void
    {
        $builder = self::builder();
        $one = $builder->document(
            self::relationshipResult(RecordLinkage::toOne(self::record('customers', '9'))),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );
        $none = $builder->document(
            self::relationshipResult(RecordLinkage::toOne(null)),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );
        $many = $builder->document(
            self::relationshipResult(RecordLinkage::toMany(self::page([
                self::record('orders', '3'),
                self::record('orders', '4'),
            ]))),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $one);
        self::assertInstanceOf(DataDocument::class, $none);
        self::assertInstanceOf(DataDocument::class, $many);
        self::assertEquals(new ResourceIdentifierObject('customers', '9'), $one->data);
        self::assertNull($none->data);
        self::assertEquals(
            [new ResourceIdentifierObject('orders', '3'), new ResourceIdentifierObject('orders', '4')],
            $many->data,
        );
    }

    public function testAToManyLinkageCarriesThePaginationLinksAndTheMetadataOfItsPage(): void
    {
        $document = self::builder()->document(
            self::relationshipResult(RecordLinkage::toMany(
                self::page([self::record('orders', '3')], hasNext: true, total: 4),
            )),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertStringStartsWith(
            '/pets/1/relationships/orders?',
            (string) self::href($document->links, 'next'),
        );
        self::assertSame(['page' => ['total' => 4]], $document->meta);
    }

    public function testAToOneLinkageCarriesNoPaginationLinkAndNoPageMetadata(): void
    {
        $document = self::builder()->document(
            self::relationshipResult(RecordLinkage::toOne(self::record('customers', '9'))),
            self::target(RouteKind::Relationship),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(['self', 'related'], array_keys($document->links->all()));
        self::assertNull($document->meta);
    }

    public function testTheDocumentOfAnAcceptedWriteCarriesTheMonitorAndItsOwnRouteWithoutTheQuery(): void
    {
        $document = self::builder()->document(
            new AcceptedResult(self::record('customers', '9'), new RecordGraph(false), new SparseFieldsets([])),
            self::target(RouteKind::Resource),
            self::request(['include' => 'owner', 'fields' => ['pets' => 'name']]),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $monitor = $document->data;

        self::assertInstanceOf(ResourceObject::class, $monitor);
        self::assertSame(['customers', '9'], [$monitor->type, $monitor->id]);
        self::assertSame('/customers/9', self::href($document->links, 'self'));
    }

    public function testTheMonitorOfAnAcceptedWriteIsWrittenWithTheFieldsetsAndTheGraphOfTheResult(): void
    {
        $document = self::builder()->document(
            new AcceptedResult(
                self::record('pets', '1'),
                self::linkedGraph(),
                new SparseFieldsets(['pets' => ['owner']]),
            ),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $monitor = $document->data;

        self::assertInstanceOf(ResourceObject::class, $monitor);
        self::assertSame([[], ['owner']], self::fieldsOf($monitor));
        self::assertSame('9', self::linkedId($monitor, 'owner'));
    }

    public function testAnAtomicResponseCarriesOneResultPerOperationInOperationOrder(): void
    {
        $document = self::builder()->document(
            new AtomicResult([
                new NoContentResult(),
                new ResourceResult(
                    'pets',
                    self::record('pets', '1'),
                    new RecordGraph(false),
                    new SparseFieldsets([]),
                    201,
                    null,
                ),
            ]),
            self::target(RouteKind::Atomic),
            self::request(),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
        );

        self::assertInstanceOf(AtomicResultsDocument::class, $document);
        self::assertSame(
            [null, '1'],
            array_map(static function (?ResourceObject $result): ?string {
                return $result?->id;
            }, $document->results),
        );
    }

    public function testEachEntryOfAnAtomicResponseIsWrittenWithItsOwnFieldsetsAndItsOwnGraph(): void
    {
        $document = self::builder()->document(
            new AtomicResult([
                new ResourceResult(
                    'pets',
                    self::record('pets', '1'),
                    self::linkedGraph(),
                    new SparseFieldsets(['pets' => ['owner']]),
                    201,
                    null,
                ),
                new ResourceResult(
                    'pets',
                    self::record('pets', '2'),
                    new RecordGraph(false),
                    new SparseFieldsets(['pets' => ['name']]),
                    200,
                    null,
                ),
            ]),
            self::target(RouteKind::Atomic),
            self::request(),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
        );

        self::assertInstanceOf(AtomicResultsDocument::class, $document);

        [$created, $updated] = $document->results;

        self::assertInstanceOf(ResourceObject::class, $created);
        self::assertInstanceOf(ResourceObject::class, $updated);
        self::assertSame([[], ['owner']], self::fieldsOf($created));
        self::assertSame([['name'], []], self::fieldsOf($updated));
        self::assertSame('9', self::linkedId($created, 'owner'));
    }

    public function testAnAtomicRequestWhoseOperationsReturnedNothingBecomesNoDocument(): void
    {
        self::assertNull(self::builder()->document(
            new AtomicResult([new NoContentResult(), new NoContentResult()]),
            self::target(RouteKind::Atomic),
            self::request(),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
        ));
    }

    public function testTheJsonApiObjectListsTheVersionAndTheExtensionsAndProfilesOfTheResponse(): void
    {
        $contentType = MediaType::jsonApi([MediaType::ATOMIC_EXTENSION], ['https://example.test/profile']);

        $document = self::builder()->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            self::request(),
            $contentType,
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('1.1', $document->jsonApi->version);
        self::assertSame([MediaType::ATOMIC_EXTENSION], $document->jsonApi->ext);
        self::assertSame(['https://example.test/profile'], $document->jsonApi->profile);
    }

    public function testAnAtomicResponseCarriesTheJsonApiObjectOfItsMediaType(): void
    {
        $document = self::builder()->document(
            new AtomicResult([new ResourceResult(
                'pets',
                self::record('pets', '1'),
                new RecordGraph(false),
                new SparseFieldsets([]),
                201,
                null,
            )]),
            self::target(RouteKind::Atomic),
            self::request(),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
        );

        self::assertInstanceOf(AtomicResultsDocument::class, $document);
        self::assertSame([MediaType::ATOMIC_EXTENSION], $document->jsonApi->ext);
    }

    #[DataProvider('mediaTypesOfAResponse')]
    public function testTheTopLevelSelfLinkNamesTheMediaTypeWhereAnExtensionOrAProfileIsApplied(
        MediaType $contentType,
        ?string $expected,
    ): void {
        $document = self::builder()->document(
            self::resourceResult(),
            self::target(RouteKind::Resource),
            self::request(),
            $contentType,
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $self = $document->links->all()['self'];

        self::assertInstanceOf(Link::class, $self);
        self::assertSame($expected, $self->type);
        self::assertSame($expected === null, $self->isHrefOnly());
    }

    /**
     * @return array<string, array{MediaType, ?string}>
     */
    public static function mediaTypesOfAResponse(): array
    {
        return [
            'nothing applied' => [MediaType::jsonApi(), null],
            'an extension applied' => [
                MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
                'application/vnd.api+json;ext="' . MediaType::ATOMIC_EXTENSION . '"',
            ],
            'a profile applied' => [
                MediaType::jsonApi([], ['https://example.test/profile']),
                'application/vnd.api+json;profile="https://example.test/profile"',
            ],
        ];
    }

    public function testARouteThatAddressesNoRepresentationCarriesNoDocumentWithPrimaryData(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('an atomic route carries a document of results and no primary data');

        self::builder()->document(
            CollectionResult::paged(
                'pets',
                self::page([self::record('pets', '1')]),
                new RecordGraph(false),
                new SparseFieldsets([]),
            ),
            self::target(RouteKind::Atomic),
            self::request(),
            MediaType::jsonApi(),
        );
    }

    public function testAnEndpointLinksItsOwnUrlWhereItRespondsToGet(): void
    {
        $get = Samples::endpoint('petCard', 'GET', '/pets/{id}/card');
        $post = Samples::endpoint();
        $request = new ServerRequest('GET', new Headers([]), ['fields' => ['pets' => 'name']], ['id' => '1'], '');

        $read = self::builder()->endpointDocument(self::resourceResult(), $get, $request, MediaType::jsonApi());
        $write = self::builder()->endpointDocument(
            self::resourceResult(),
            $post,
            new ServerRequest('POST', new Headers([]), [], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $read);
        self::assertInstanceOf(DataDocument::class, $write);
        self::assertSame('/pets/1/card?fields%5Bpets%5D=name', self::href($read->links, 'self'));
        self::assertTrue($write->links->isEmpty());
        self::assertSame('1', $write->data instanceof ResourceObject ? $write->data->id : null);
    }

    public function testTheCreatedResourceOfAnEndpointIsLinkedAsTheCreateLinksIt(): void
    {
        $created = new ResourceResult(
            'pets',
            self::record('pets', '7'),
            new RecordGraph(false),
            new SparseFieldsets([]),
            201,
            null,
        );

        $endpoint = Samples::endpoint(responses: [201 => new ResponseMetadata(
            ResponseShapeKind::Created,
            'pets',
            null,
            null,
            null,
            null,
        )]);

        $document = self::builder()->endpointDocument(
            $created,
            $endpoint,
            new ServerRequest('POST', new Headers([]), [], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/7', self::href($document->links, 'self'));
    }

    public function testTheCollectionOfAnEndpointHasNoPages(): void
    {
        $collection = CollectionResult::whole(
            'pets',
            [self::record('pets', '1'), self::record('pets', '2')],
            new RecordGraph(false),
            new SparseFieldsets([]),
        );
        $endpoint = Samples::endpoint(
            'recentPets',
            'GET',
            '/pets/recent',
            [],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null)],
        );

        $document = self::builder()->endpointDocument(
            $collection,
            $endpoint,
            new ServerRequest('GET', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(['self'], array_keys($document->links->all()));
        self::assertSame('/pets/recent', self::href($document->links, 'self'));
        self::assertNull($document->meta);
        self::assertCount(2, is_array($document->data) ? $document->data : []);
    }

    /**
     * the pagination links lead to the URL of the endpoint with the query of its self link,
     * the page family replaced
     */
    public function testThePageOfAnEndpointLinksItsPagesAtItsOwnUrl(): void
    {
        $result = CollectionResult::paged(
            'pets',
            self::page([self::record('pets', '1'), self::record('pets', '2')], hasNext: true, total: 5),
            new RecordGraph(false),
            new SparseFieldsets([]),
        );

        $document = self::builder()->endpointDocument(
            $result,
            Samples::pageEndpoint(),
            self::request(['sort' => '-name', 'page' => ['size' => '2']]),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(['self', 'first', 'prev', 'next', 'last'], array_keys($document->links->all()));
        self::assertSame('/pets/search?sort=-name&page%5Bsize%5D=2', self::href($document->links, 'self'));
        self::assertSame(
            '/pets/search?sort=-name&page%5Bnumber%5D=2&page%5Bsize%5D=2',
            self::href($document->links, 'next'),
        );
        self::assertSame(
            '/pets/search?sort=-name&page%5Bnumber%5D=3&page%5Bsize%5D=2',
            self::href($document->links, 'last'),
        );
        self::assertSame(['page' => ['total' => 5]], $document->meta);
    }

    /**
     * the related link names the parent the endpoint is bound to, where the related route responds
     */
    public function testTheLinkageOfAnEndpointHasNoPagesAndLinksTheRelatedRoute(): void
    {
        $linkage = new RelationshipResult(
            'pets',
            '1',
            'orders',
            'orders',
            RecordLinkage::toManyWhole([self::record('orders', '5')]),
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );
        $endpoint = Samples::endpoint(
            'petOrders',
            'GET',
            '/pets/{id}/order-ids',
            responses: [200 => new ResponseMetadata(
                ResponseShapeKind::Linkage,
                'orders',
                'id',
                'orders',
                Cardinality::ToMany,
                null,
            )],
        );

        $document = self::builder()->endpointDocument(
            $linkage,
            $endpoint,
            new ServerRequest('GET', new Headers([]), [], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(['self', 'related'], array_keys($document->links->all()));
        self::assertSame('/pets/1/orders', self::href($document->links, 'related'));
        self::assertNull($document->meta);
        self::assertCount(1, is_array($document->data) ? $document->data : []);
    }

    public function testTheLinksOfARelationshipDocumentCarryTheLanguageParameter(): void
    {
        $document = self::builder(languageSources: self::uiLocaleThenField())->document(
            self::relationshipResult(RecordLinkage::toOne(self::record('customers', '9'))),
            self::target(RouteKind::Relationship),
            self::request(['include' => 'orders', 'uiLocale' => 'uk']),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame(
            '/pets/1/relationships/orders?include=orders&uiLocale=uk',
            self::href($document->links, 'self'),
        );
        self::assertSame('/pets/1/orders?uiLocale=uk', self::href($document->links, 'related'));
    }

    public function testTheMonitorOfAnAcceptedWriteIsLinkedWithTheLanguageParameterAlone(): void
    {
        $document = self::builder(languageSources: self::uiLocaleThenField())->document(
            new AcceptedResult(self::record('customers', '9'), new RecordGraph(false), new SparseFieldsets([])),
            self::target(RouteKind::Resource),
            self::request(['include' => 'owner', 'uiLocale' => 'en-GB']),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertInstanceOf(ResourceObject::class, $document->data);
        self::assertSame('/customers/9?uiLocale=en-GB', self::href($document->links, 'self'));
        self::assertSame('/customers/9?uiLocale=en-GB', self::href($document->data->links, 'self'));
    }

    public function testAnIncludedResourceAndAnAtomicResultCarryTheLanguageParameter(): void
    {
        $builder = self::builder(languageSources: self::uiLocaleThenField());
        $compound = $builder->document(
            new ResourceResult(
                'pets',
                self::record('pets', '1'),
                new RecordGraph(true, [self::record('customers', '9')]),
                new SparseFieldsets([]),
                200,
                null,
            ),
            self::target(RouteKind::Resource),
            self::request(['include' => 'owner', 'uiLocale' => 'uk']),
            MediaType::jsonApi(),
        );
        $atomic = $builder->document(
            new AtomicResult([new ResourceResult(
                'pets',
                self::record('pets', '1'),
                new RecordGraph(false),
                new SparseFieldsets([]),
                201,
                null,
            )]),
            self::target(RouteKind::Atomic),
            self::request(['uiLocale' => 'uk']),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
        );

        self::assertInstanceOf(DataDocument::class, $compound);
        self::assertNotNull($compound->included);
        self::assertSame('/customers/9?uiLocale=uk', self::href($compound->included->all()[0]->links, 'self'));
        self::assertInstanceOf(AtomicResultsDocument::class, $atomic);
        self::assertInstanceOf(ResourceObject::class, $atomic->results[0]);
        self::assertSame('/pets/1?uiLocale=uk', self::href($atomic->results[0]->links, 'self'));
    }

    public function testTheLinkageOfAnEndpointLinksTheRelatedRouteWithTheLanguageParameter(): void
    {
        $linkage = new RelationshipResult(
            'pets',
            '1',
            'orders',
            'orders',
            RecordLinkage::toManyWhole([self::record('orders', '5')]),
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );
        $endpoint = Samples::endpoint(
            'petOrders',
            'GET',
            '/pets/{id}/order-ids',
            responses: [200 => new ResponseMetadata(
                ResponseShapeKind::Linkage,
                'orders',
                'id',
                'orders',
                Cardinality::ToMany,
                null,
            )],
        );

        $document = self::builder(languageSources: self::uiLocaleThenField())->endpointDocument(
            $linkage,
            $endpoint,
            new ServerRequest('GET', new Headers([]), ['uiLocale' => 'uk'], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1/orders?uiLocale=uk', self::href($document->links, 'related'));
    }

    public function testAParameterThatDidNotDecideTheLanguageStaysOffTheLinks(): void
    {
        $document = self::builder(languageSources: new LanguageSources([
            LanguageSourceMetadata::acceptLanguage(),
            LanguageSourceMetadata::queryParameter('uiLocale'),
        ]))->document(
            self::relationshipResult(RecordLinkage::toOne(self::record('customers', '9'))),
            self::target(RouteKind::Relationship),
            new ServerRequest('GET', new Headers(['Accept-Language' => 'en']), ['uiLocale' => 'uk'], [], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);
        self::assertSame('/pets/1/orders', self::href($document->links, 'related'));
    }

    public function testTheMetaOfAnEndpointIsAMetaDocument(): void
    {
        $endpoint = Samples::endpoint(responses: [200 => new ResponseMetadata(
            ResponseShapeKind::Meta,
            null,
            null,
            null,
            null,
            new IntegerCodec(),
        )]);

        $document = self::builder()->endpointDocument(
            new MetaResult(['pets' => 3]),
            $endpoint,
            new ServerRequest('POST', new Headers([]), [], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(MetaDocument::class, $document);
        self::assertSame(['pets' => 3], $document->meta);
    }

    public function testAnAtomicResultIsNoneAnEndpointRespondsWith(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is none an endpoint responds with');

        self::builder()->endpointDocument(
            new AtomicResult([new NoContentResult()]),
            Samples::endpoint(),
            new ServerRequest('POST', new Headers([]), [], ['id' => '1'], ''),
            MediaType::jsonApi(),
        );
    }

    private static function builder(
        ?ResourceRegistry $registry = null,
        ?LanguageSources $languageSources = null,
    ): DocumentBuilder {
        $registry ??= self::registry();
        $routes = RouteTable::of($registry, [], []);
        $urls = new RouteUrls($routes, PlainUrls::httpQuery());
        $paginationWriter = new PaginationWriter($urls, NamingPolicy::SnakeCase);

        return new DocumentBuilder(
            new ResourceSerializer($registry, $routes, $urls, $paginationWriter),
            $paginationWriter,
            $urls,
            $routes,
            new LinkQuery($registry, $languageSources ?? Samples::languageSources()),
        );
    }

    /**
     * `$excluded` names the routes of `pets` that leave;
     * `$owner` and `$orders` exclude reads of those relationships,
     * and `orders` is not includable once it excludes one, so the API builds
     */
    private static function registry(
        ?RouteExclusion $excluded = null,
        ?RouteExclusion $owner = null,
        ?RouteExclusion $orders = null,
    ): ResourceRegistry {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name')],
                relationships: [
                    Samples::toOne('owner', 'customers', excluded: $owner),
                    Samples::toMany('orders', 'orders', includable: $orders === null, excluded: $orders),
                ],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader, Capability::ResourceLoader],
                excluded: $excluded,
            ),
            Samples::resource('customers', capabilities: [Capability::CollectionReader]),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);
    }

    /**
     * `notifyOwner` is read by the create, the update and the addition to `orders` alone,
     * `previewMode` by the fetch of a pet alone
     */
    private static function registryWithParameters(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name')],
                relationships: [
                    Samples::toOne('owner', 'customers'),
                    Samples::toMany('orders', 'orders', updates: [RelationshipUpdate::Add], queryParameters: [
                        OperationKind::AddToRelationship->value => [self::optional('notifyOwner')],
                    ]),
                ],
                capabilities: [
                    Capability::CollectionReader,
                    Capability::RelatedLoader,
                    Capability::ResourceLoader,
                    Capability::ResourceCreator,
                    Capability::ResourceUpdater,
                    Capability::RelationshipAdder,
                ],
                queryParameters: [
                    OperationKind::CreateResource->value => [self::optional('notifyOwner')],
                    OperationKind::UpdateResource->value => [self::optional('notifyOwner')],
                    OperationKind::FetchResource->value => [self::optional('previewMode')],
                ],
            ),
            Samples::resource('customers', capabilities: [Capability::CollectionReader]),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);
    }

    private static function optional(string $name): QueryParameterMetadata
    {
        return new QueryParameterMetadata($name, new StringCodec(), false);
    }

    private static function exceptResource(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Resource, [$kind], 'type "pets"');
    }

    private static function exceptRelationshipRead(OperationKind $kind): RouteExclusion
    {
        return RouteExclusion::except(RouteOwner::Relationship, [$kind], 'a relationship');
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(array $query = []): ServerRequest
    {
        return new ServerRequest('GET', new Headers([]), $query, [], '');
    }

    private static function uiLocaleThenField(): LanguageSources
    {
        return new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]);
    }

    private static function target(RouteKind $kind): RouteTarget
    {
        return match ($kind) {
            RouteKind::Collection => new RouteTarget($kind, 'pets', null, null),
            RouteKind::Resource => new RouteTarget($kind, 'pets', '1', null),
            RouteKind::Related => new RouteTarget($kind, 'pets', '1', 'owner'),
            RouteKind::Relationship => new RouteTarget($kind, 'pets', '1', 'orders'),
            RouteKind::Atomic => new RouteTarget($kind, null, null, null),
        };
    }

    private static function record(string $type, string $id): ResourceRecord
    {
        return new ResourceRecord($type, $id, new Pet($id, 'the record ' . $id));
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records, bool $hasNext = false, ?int $total = null): Page
    {
        return new Page($records, Strategy::PageNumber, 2, 1, null, $hasNext, false, false, $total, null, null, null);
    }

    private static function resourceResult(): ResourceResult
    {
        return new ResourceResult(
            'pets',
            self::record('pets', '1'),
            new RecordGraph(false),
            new SparseFieldsets([]),
            200,
            null,
        );
    }

    private static function relationshipResult(RecordLinkage $linkage): RelationshipResult
    {
        return new RelationshipResult(
            'pets',
            '1',
            'orders',
            'orders',
            $linkage,
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );
    }

    private static function linkedGraph(): RecordGraph
    {
        return new RecordGraph(false, [], [
            'pets' => [
                '1' => ['owner' => RecordLinkage::toOne(self::record('customers', '9'))],
                '2' => ['owner' => RecordLinkage::toOne(self::record('customers', '8'))],
            ],
        ]);
    }

    /**
     * @return ?array{list<array-key>, list<array-key>}
     */
    private static function fieldsOf(ResourceIdentifierObject|ResourceObject $object): ?array
    {
        return $object instanceof ResourceObject
            ? [array_keys($object->attributes), array_keys($object->relationships)]
            : null;
    }

    private static function linkedId(ResourceIdentifierObject|ResourceObject $object, string $relationship): ?string
    {
        if (!$object instanceof ResourceObject) {
            return null;
        }

        $linkage = ($object->relationships[$relationship] ?? null)?->linkage();

        return $linkage instanceof ResourceIdentifierObject ? $linkage->id : null;
    }

    private static function href(Links $links, string $name): ?string
    {
        return ($links->all()[$name] ?? null)?->href;
    }
}
