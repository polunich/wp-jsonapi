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
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\FetchResourceProcessor;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\VersionTag;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
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
#[CoversClass(FetchResourceProcessor::class)]
final class FetchResourceProcessorTest extends TestCase
{
    public function testTheLoadedTargetIsRenderedAndNoStoreIsAskedForItAgain(): void
    {
        $store = self::store();
        $record = new Pet('1', 'first');

        $result = self::processor($store)->process(self::operation(), self::context($record));

        self::assertSame([], $store->loads);
        self::assertSame([], $store->relations);
        self::assertSame($record, $result->resource?->requireRecord());
    }

    public function testThePrimaryResourceCarriesTheTypeOfTheTargetAndTheIdTheAccessorRead(): void
    {
        $result = self::processor(self::store())->process(
            self::operation(id: '7'),
            self::context(new Pet('7', 'the seventh')),
        );

        $resource = $result->resource;

        self::assertInstanceOf(ResourceRecord::class, $resource);
        self::assertSame('pets', $result->type);
        self::assertSame('pets', $resource->type);
        self::assertSame('7', $resource->id);
    }

    public function testTheLinkageOfTheRecordIsWrittenFromTheRecordItself(): void
    {
        $store = self::store();

        $graph = self::processor($store)->process(
            self::operation(),
            self::context(new Pet('1', 'first', '7')),
        )->graph;

        self::assertSame('7', $graph->linkage('pets', '1', 'owner')?->record()?->id);
        self::assertNull($graph->linkage('pets', '1', 'orders'));
        self::assertSame([], $store->loads);
    }

    public function testTheDocumentIsCompoundExactlyWhenTheRequestSuppliesIncludeAndThePathsAreLoaded(): void
    {
        $store = self::store(['customers' => ['7' => new Pet('7', 'the owner')]]);
        $record = new Pet('1', 'first', '7');

        $plain = self::processor($store)->process(self::operation(), self::context($record));
        $compound = self::processor($store)->process(
            self::operation(include: new IncludePaths([new IncludePath(['owner'])])),
            self::context($record, includeSupplied: true),
        );

        self::assertFalse($plain->graph->isCompound());
        self::assertTrue($compound->graph->isCompound());
        self::assertSame(['7'], array_map(
            static function (ResourceRecord $resource): string {
                return $resource->id;
            },
            $compound->graph->included(),
        ));
    }

    public function testTheResultIsA200ThatCarriesTheFieldsetsOfTheRequest(): void
    {
        $fields = new SparseFieldsets(['pets' => ['name']]);

        $result = self::processor(self::store())->process(
            self::operation(fields: $fields),
            self::context(new Pet('1', 'first')),
        );

        self::assertSame(200, $result->status());
        self::assertSame($fields, $result->fields);
    }

    public function testTheEntityTagOfTheRepresentationIsWrittenFromTheVersionOfTheRecord(): void
    {
        $result = self::processor(self::store())->process(
            self::operation(type: 'versioned'),
            self::context(new Pet('1', 'first', null, '9')),
        );

        self::assertSame(
            VersionTag::encode('9', null, MediaType::jsonApi())->toHeaderValue(),
            $result->entityTag()?->toHeaderValue(),
        );
    }

    #[DataProvider('untaggedRepresentations')]
    public function testNoEntityTagIsWrittenWhereTheLibraryWritesNone(string $type, bool $includeSupplied): void
    {
        $result = self::processor(self::store())->process(
            self::operation(type: $type, include: $includeSupplied ? new IncludePaths([]) : null),
            self::context(new Pet('1', 'first'), includeSupplied: $includeSupplied),
        );

        self::assertNull($result->entityTag());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function untaggedRepresentations(): iterable
    {
        yield 'a type that declares no version policy' => ['pets', false];
        yield 'a request that supplies include' => ['versioned', true];
    }

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'a fetch_resource processor processes a fetch_resource operation, and this one is fetch_collection',
        );

        self::processor(self::store())->process(
            new FetchCollection(
                'pets',
                null,
                new Sort([]),
                PageRequest::pageNumber(),
                null,
                new SparseFieldsets([]),
                new LanguagePreferences([]),
                new Values([]),
            ),
            self::context(new Pet('1', 'first')),
        );
    }

    public function testAContextWithoutTheLoadedRecordIsRefused(): void
    {
        $this->expectException(LogicException::class);

        self::processor(self::store())->process(self::operation(), self::context(null));
    }

    public function testARecordWhoseIdAccessorReturnsNoIdIsRefused(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::processor(self::store())->process(self::operation(), self::context(new Pet('', 'nameless')));
    }

    public function testTheProcessorIsInternalAndImmutable(): void
    {
        $processor = new ReflectionClass(FetchResourceProcessor::class);

        self::assertStringContainsString('@internal', (string) $processor->getDocComment());
        self::assertTrue($processor->isFinal());
        self::assertTrue($processor->isReadOnly());
    }

    private static function operation(
        string $type = 'pets',
        string $id = '1',
        ?IncludePaths $include = null,
        ?SparseFieldsets $fields = null,
    ): FetchResource {
        return new FetchResource(
            new Identifier($type, $id),
            $include,
            $fields ?? new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    private static function processor(RecordingStore $store): FetchResourceProcessor
    {
        $registry = self::registry();
        $resolver = BoundImplementations::serving($store);

        return new FetchResourceProcessor(
            $registry,
            new IncludeLoader(
                $registry,
                new StoreGateway($registry, $resolver),
                new Paginator($registry, new StoreGateway($registry, $resolver)),
            ),
            new Preconditions($registry),
        );
    }

    /**
     * @param array<array-key, array<array-key, object>>       $records
     * @param array<array-key, array<array-key, list<object>>> $members
     */
    private static function store(array $records = [], array $members = []): RecordingStore
    {
        return new RecordingStore(records: $records, members: $members);
    }

    private static function context(?Pet $record, bool $includeSupplied = false): ExecutionContext
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
        $pets = Samples::resource(
            'pets',
            attributes: [Samples::attribute('name')],
            relationships: [
                Samples::toOne('owner', 'customers'),
                Samples::toMany('orders', 'orders', paginationPolicy: new PaginationPolicy(2, 100)),
            ],
            capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            maxIncludeDepth: 2,
        );
        $customers = Samples::resource('customers', capabilities: [Capability::CollectionReader]);
        $orders = Samples::resource('orders', capabilities: [Capability::CollectionReader]);

        return new ResourceRegistry([$pets, $customers, $orders, self::versioned()]);
    }

    private static function versioned(): ResourceMetadata
    {
        return new ResourceMetadata(
            'versioned',
            Samples::id(),
            'id',
            [],
            [],
            [Capability::ResourceLoader->value => Samples::implementation()],
            [OperationKind::FetchResource->value => Samples::implementation()],
            [],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            new VersionPolicy(static function (Pet $pet): string {
                return $pet->version;
            }, false),
            null,
            PaginationPolicy::libraryDefault(),
            1,
            null,
        );
    }
}
