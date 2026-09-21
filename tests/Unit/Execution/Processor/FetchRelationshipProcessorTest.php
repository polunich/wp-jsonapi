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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\FetchRelationshipProcessor;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\VersionTag;
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
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
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
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingStore;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FetchRelationshipProcessor::class)]
final class FetchRelationshipProcessorTest extends TestCase
{
    public function testTheToManyLinkageIsOnePageOfIdentifiersReadUnderTheParentScope(): void
    {
        $filter = new Condition(new FieldPath(['name']), '@gt', 'a name');
        $queryParameters = new Values(['placedSince' => '2026']);
        $store = self::store(slice: [new Pet('5', 'first order'), new Pet('6', 'second order')]);

        $result = self::processor($store)->process(
            self::operation(
                'orders',
                filter: $filter,
                page: PageRequest::cursor(after: self::cursor('4')),
                queryParameters: $queryParameters,
            ),
            self::context(new Pet('1', 'first')),
        );

        self::assertCount(1, $store->queries);

        $scope = $store->queries[0]->parent;

        self::assertInstanceOf(ParentScope::class, $scope);
        self::assertSame('orders', $store->queries[0]->type);
        self::assertSame('pets', $scope->type);
        self::assertSame('orders', $scope->relationship);
        self::assertSame($filter, $store->queries[0]->filter);
        self::assertSame('-name,id', $store->queries[0]->sort->toString());
        self::assertSame($queryParameters, $store->queries[0]->queryParameters);
        self::assertTrue($result->linkage->isToMany());
        self::assertSame(['5', '6'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $result->linkage->page()->records,
        ));
    }

    /**
     * `purchases` reads in the sort `buyer.name`,
     * which the cursors read off the buyer of each boundary purchase
     */
    public function testTheBoundaryCursorsReadTheirRelatedRecordsInTheLanguagesOfTheRequest(): void
    {
        $languages = new LanguagePreferences([new LanguageRange('uk')]);
        $store = self::store(
            slice: [new Pet('5', 'first purchase', '9')],
            records: ['customers' => ['9' => new Pet('9', 'nine')]],
        );

        self::processor($store)->process(
            self::operation('purchases', page: PageRequest::cursor(), languages: $languages),
            self::context(new Pet('1', 'first')),
        );

        self::assertSame('buyer.name,id', $store->queries[0]->sort->toString());
        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame($languages, $store->loads[0]->languages);
    }

    /**
     * @param ?list<string> $hinted `null` when the hint names every field
     */
    #[DataProvider('relationshipHints')]
    public function testTheHintOfTheReadingFollowsWhetherTheRequestSuppliesInclude(
        ?IncludePaths $include,
        SparseFieldsets $fields,
        ?array $hinted,
    ): void {
        $store = self::store(slice: [new Pet('5', 'first order', '9')]);

        self::processor($store)->process(
            self::operation('orders', include: $include, fields: $fields),
            self::context(new Pet('1', 'first'), includeSupplied: $include !== null),
        );

        self::assertSame($hinted, $store->queries[0]->fields->fields());
    }

    /**
     * @return iterable<string, array{?IncludePaths, SparseFieldsets, ?list<string>}>
     */
    public static function relationshipHints(): iterable
    {
        yield 'no include: the identifier of the to-one seller and the sort field name' => [
            null,
            new SparseFieldsets(['orders' => ['name']]),
            ['seller', 'name'],
        ];
        yield 'an include that names no path: as without include' => [
            new IncludePaths([]),
            new SparseFieldsets(['orders' => ['name']]),
            ['seller', 'name'],
        ];
        yield 'include: the fieldset of the client and the fields the library reads' => [
            new IncludePaths([new IncludePath(['orders'])]),
            new SparseFieldsets(['orders' => ['name']]),
            ['name', 'seller'],
        ];
        yield 'include without a fieldset of the type: every field' => [
            new IncludePaths([new IncludePath(['orders'])]),
            new SparseFieldsets([]),
            null,
        ];
    }

    public function testTheMembersOfTheLinkageJoinIncludedWhenAPathNamesTheRelationship(): void
    {
        $store = self::store(slice: [new Pet('5', 'first order', '9')]);

        $result = self::processor($store)->process(
            self::operation('orders', include: new IncludePaths([new IncludePath(['orders'])])),
            self::context(new Pet('1', 'first'), includeSupplied: true),
        );

        self::assertTrue($result->graph->isCompound());
        self::assertSame(['5'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $result->graph->included(),
        ));
        self::assertSame('9', $result->graph->linkage('orders', '5', 'seller')?->record()?->id);
    }

    public function testWithoutIncludeNoRelatedResourceJoinsTheDocument(): void
    {
        $result = self::processor(self::store(slice: [new Pet('5', 'first order', '9')]))->process(
            self::operation('orders'),
            self::context(new Pet('1', 'first')),
        );

        self::assertFalse($result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    public function testTheToOneIdentifierIsReadOffTheParentAndNothingIsLoadedWithoutInclude(): void
    {
        $store = self::store();

        $result = self::processor($store)->process(
            self::operation('owner'),
            self::context(new Pet('1', 'first', '7')),
        );

        self::assertSame([], $store->loads);
        self::assertSame([], $store->relations);
        self::assertSame([], $store->queries);
        $identifier = $result->linkage->record();

        self::assertFalse($result->linkage->isToMany());
        self::assertInstanceOf(ResourceRecord::class, $identifier);
        self::assertSame('customers', $identifier->type);
        self::assertSame('7', $identifier->id);
    }

    public function testWithIncludeTheRelatedRecordOfAToOneIsReadOnceForIncluded(): void
    {
        $store = self::store(records: ['customers' => ['7' => new Pet('7', 'the owner')]]);

        $result = self::processor($store)->process(
            self::operation(
                'owner',
                include: new IncludePaths([new IncludePath(['owner'])]),
                queryParameters: new Values(['since' => '2026']),
            ),
            self::context(new Pet('1', 'first', '7'), includeSupplied: true),
        );

        self::assertCount(1, $store->loads);
        self::assertSame('customers', $store->loads[0]->type);
        self::assertSame(['7'], $store->loads[0]->ids);
        self::assertSame([], $store->loads[0]->queryParameters->names());
        self::assertSame('7', $result->linkage->record()?->id);
        self::assertSame(['7'], array_map(
            static function (ResourceRecord $record): string {
                return $record->id;
            },
            $result->graph->included(),
        ));
    }

    public function testAnIncludeThatNamesNoPathReadsNoRelatedRecordOfAToOne(): void
    {
        $store = self::store(records: ['customers' => ['7' => new Pet('7', 'the owner')]]);

        $result = self::processor($store)->process(
            self::operation('owner', include: new IncludePaths([])),
            self::context(new Pet('1', 'first', '7'), includeSupplied: true),
        );

        self::assertSame([], $store->loads);
        self::assertSame('7', $result->linkage->record()?->id);
        self::assertTrue($result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    public function testAnInvisibleRelatedRecordKeepsItsIdentifierAndJoinsNoIncluded(): void
    {
        $result = self::processor(self::store())->process(
            self::operation('owner', include: new IncludePaths([new IncludePath(['owner'])])),
            self::context(new Pet('1', 'first', '7'), includeSupplied: true),
        );

        self::assertSame('7', $result->linkage->record()?->id);
        self::assertTrue($result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    public function testTheEmptyToOneRelationshipIsLinkageNull(): void
    {
        $store = self::store();

        $result = self::processor($store)->process(
            self::operation('owner'),
            self::context(new Pet('1', 'first', null)),
        );

        self::assertNull($result->linkage->record());
        self::assertSame([], $store->loads);
    }

    public function testTheToOneWithoutAnAccessorIsReadThroughTheRelatedLoaderWithTheLimitOne(): void
    {
        $queryParameters = new Values(['since' => '2026']);
        $store = self::store(members: ['breeder' => ['1' => [new Pet('9', 'the breeder')]]]);

        $result = self::processor($store)->process(
            self::operation('breeder', queryParameters: $queryParameters),
            self::context(new Pet('1', 'first')),
        );

        self::assertCount(1, $store->relations);
        self::assertSame($queryParameters, $store->relations[0]->queryParameters);
        self::assertSame('pets', $store->relations[0]->type);
        self::assertSame(['1'], $store->relations[0]->parentIds);
        self::assertSame('breeder', $store->relations[0]->relationship);
        self::assertSame('id', $store->relations[0]->sort->toString());
        self::assertSame(1, $store->relations[0]->limit);
        self::assertSame([], $store->relations[0]->fields->fields());
        self::assertSame('9', $result->linkage->record()?->id);
    }

    public function testAnEmptyReadingOfAToOneWithoutAnAccessorIsLinkageNull(): void
    {
        $result = self::processor(self::store())->process(
            self::operation('breeder'),
            self::context(new Pet('1', 'first')),
        );

        self::assertNull($result->linkage->record());
    }

    public function testTheEntityTagIsTheTagOfTheParent(): void
    {
        $tagged = self::processor(self::store())->process(
            self::operation('owner'),
            self::context(new Pet('1', 'first', '7', '9')),
        );
        $untagged = self::processor(self::store())->process(
            self::operation('owner', include: new IncludePaths([new IncludePath(['owner'])])),
            self::context(new Pet('1', 'first', '7', '9'), includeSupplied: true),
        );

        self::assertSame(
            VersionTag::encode('9', null, MediaType::jsonApi())->toHeaderValue(),
            $tagged->entityTag()?->toHeaderValue(),
        );
        self::assertNull($untagged->entityTag());
    }

    public function testTheResultNamesTheParentTheRelationshipAndTheRelatedType(): void
    {
        $fields = new SparseFieldsets(['customers' => ['name']]);

        $result = self::processor(self::store())->process(
            self::operation('owner', fields: $fields),
            self::context(new Pet('1', 'first', '7')),
        );

        self::assertSame(200, $result->status());
        self::assertSame('pets', $result->parentType);
        self::assertSame('1', $result->parentId);
        self::assertSame('owner', $result->relationship);
        self::assertSame('customers', $result->relatedType);
        self::assertSame($fields, $result->fields);
    }

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'a fetch_relationship processor processes a fetch_relationship operation, and this one is fetch_resource',
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

    public function testAContextWithoutTheLoadedRecordIsRefused(): void
    {
        $this->expectException(LogicException::class);

        self::processor(self::store())->process(self::operation('owner'), self::context(null));
    }

    public function testTheProcessorIsInternalAndImmutable(): void
    {
        $processor = new ReflectionClass(FetchRelationshipProcessor::class);

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
        ?PageRequest $page = null,
        ?IncludePaths $include = null,
        ?SparseFieldsets $fields = null,
        ?LanguagePreferences $languages = null,
        ?Values $queryParameters = null,
    ): FetchRelationship {
        return new FetchRelationship(
            new Identifier('pets', '1'),
            $relationship,
            $filter,
            $page ?? PageRequest::pageNumber(),
            $include,
            $fields ?? new SparseFieldsets([]),
            $languages ?? new LanguagePreferences([]),
            $queryParameters ?? new Values([]),
        );
    }

    /**
     * keyed like the reading sort of the `orders` linkage, `-name,id`
     */
    private static function cursor(string $id): Cursor
    {
        return new Cursor(['name' => 'a name'], $id);
    }

    private static function processor(RecordingStore $store): FetchRelationshipProcessor
    {
        $registry = self::registry();
        $resolver = BoundImplementations::serving($store);
        $store = new StoreGateway($registry, $resolver);
        $paginator = new Paginator($registry, $store);

        return new FetchRelationshipProcessor(
            $registry,
            $store,
            $paginator,
            new IncludeLoader($registry, $store, $paginator),
            new Preconditions($registry),
        );
    }

    /**
     * @param list<object>                                     $slice
     * @param array<array-key, array<array-key, object>>       $records
     * @param array<array-key, array<array-key, list<object>>> $members
     */
    private static function store(array $slice = [], array $records = [], array $members = []): RecordingStore
    {
        return new RecordingStore($slice, $records, $members);
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
        $orders = Samples::resource(
            'orders',
            attributes: [Samples::attribute('name', sortable: true)],
            relationships: [Samples::toOne('seller', 'customers')],
            capabilities: [Capability::CollectionReader],
        );
        $purchases = Samples::resource(
            'purchases',
            relationships: [Samples::toOne('buyer', 'customers', nullable: false)],
            capabilities: [Capability::CollectionReader],
        );
        $customers = Samples::resource('customers', attributes: [Samples::attribute('name', sortable: true)]);

        return new ResourceRegistry([self::pets(), $orders, $purchases, $customers]);
    }

    /**
     * the only versioned type, so a tag on this route can only be the parent's
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
                'purchases' => Samples::toMany(
                    'purchases',
                    'purchases',
                    defaultSort: Sort::fromString('buyer.name'),
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
}
