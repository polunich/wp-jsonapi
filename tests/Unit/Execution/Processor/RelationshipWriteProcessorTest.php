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
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\AcceptedWrites;
use Polunich\WpJsonApi\Execution\Processor\FetchRelationshipProcessor;
use Polunich\WpJsonApi\Execution\Processor\RelationshipWriteProcessor;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\VersionTag;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\CollectionReader;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\RelationshipAdder;
use Polunich\WpJsonApi\Store\RelationshipRemover;
use Polunich\WpJsonApi\Store\RelationshipReplacer;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Query\WrittenInclude;
use Polunich\WpJsonApi\Tests\Fixtures\Store\RecordingStore;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(RelationshipWriteProcessor::class)]
final class RelationshipWriteProcessorTest extends TestCase
{
    private const TYPE = 'pets';

    private const MONITOR = 'jobs';

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $processor = self::processor([]);

        try {
            $processor->process(
                new DeleteResource(new Identifier(self::TYPE, '1'), null, new LanguagePreferences([]), new Values([])),
                self::context(),
            );
        } catch (LogicException $exception) {
            self::assertSame(
                'a relationship write processor processes a replace_relationship, an add_to_relationship or a'
                . ' remove_from_relationship operation, and this one is delete_resource',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('an operation of another kind got a result');
    }

    /**
     * @param class-string $port
     */
    #[DataProvider('writes')]
    public function testEachWriteRunsThroughThePortOfItsKindAndIsGivenTheOperation(
        OperationKind $kind,
        string $port,
    ): void {
        $writer = self::writer();
        $resolver = BoundImplementations::of(self::implementations($writer, [
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]),
            CollectionReader::class => self::reader(),
        ]));
        $operation = self::write($kind);

        self::processorWith(self::registry(), $resolver)->process($operation, self::context());

        self::assertSame([$operation], $writer->written);
        self::assertSame($port, $resolver->ports[0]);
    }

    /**
     * @return iterable<string, array{OperationKind, class-string}>
     */
    public static function writes(): iterable
    {
        yield 'a replacement' => [OperationKind::ReplaceRelationship, RelationshipReplacer::class];
        yield 'an addition' => [OperationKind::AddToRelationship, RelationshipAdder::class];
        yield 'a removal' => [OperationKind::RemoveFromRelationship, RelationshipRemover::class];
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kinds(): iterable
    {
        foreach (self::writes() as $name => [$kind]) {
            yield $name => [$kind];
        }
    }

    /**
     * @param class-string $port
     */
    #[DataProvider('writes')]
    public function testAWriteInsideAnAtomicRequestRespondsWithNoContentAndReadsNothingBack(
        OperationKind $kind,
        string $port,
    ): void {
        $store = new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]);
        $resolver = BoundImplementations::of(self::implementations(self::writer(), [
            ResourceLoader::class => $store,
            CollectionReader::class => self::reader(new Pet('5', 'five')),
        ]));

        $result = self::processorWith(self::registry(), $resolver)
            ->process(self::write($kind), self::context(record: new Pet('1', 'one', '101'), position: 0));

        self::assertInstanceOf(NoContentResult::class, $result);
        self::assertSame(204, $result->status());
        self::assertSame([], $store->loads);
        self::assertSame([$port], $resolver->ports);
    }

    public function testAFinishedReplacementRespondsWith200WithTheRelationshipTheWriteLeftBehind(): void
    {
        $operation = self::write(OperationKind::ReplaceRelationship, 'owner');

        $result = self::processor([
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]),
        ])->process($operation, self::context(record: new Pet('1', 'one', '101')));

        self::assertInstanceOf(RelationshipResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertSame(self::TYPE, $result->parentType);
        self::assertSame('1', $result->parentId);
        self::assertSame('owner', $result->relationship);
        self::assertSame('customers', $result->relatedType);
        self::assertSame('102', $result->linkage->record()?->id);
        self::assertSame($operation->fields, $result->fields);
    }

    #[DataProvider('kinds')]
    public function testAFinishedWriteOfAToManyRespondsWith200WithTheMembersTheWriteLeftBehind(
        OperationKind $kind,
    ): void {
        $result = self::processor([
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one')]]),
            CollectionReader::class => self::reader(new Pet('6', 'six')),
        ])->process(self::write($kind), self::context(record: new Pet('1', 'one')));

        self::assertInstanceOf(RelationshipResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertSame('orders', $result->relationship);
        self::assertSame('orders', $result->relatedType);
        self::assertSame(['6'], array_column($result->linkage->page()->records, 'id'));
    }

    public function testTheParentIsLoadedAgainWithEveryFieldAndTheLanguagesOfTheOperation(): void
    {
        $store = new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]);
        $operation = self::write(OperationKind::ReplaceRelationship, 'owner');

        self::processor([ResourceLoader::class => $store])->process($operation, self::context());

        self::assertCount(1, $store->loads);
        self::assertSame(self::TYPE, $store->loads[0]->type);
        self::assertSame(['1'], $store->loads[0]->ids);
        self::assertTrue($store->loads[0]->fields->hasEveryField());
        self::assertSame($operation->languages, $store->loads[0]->languages);
        self::assertSame($operation->queryParameters, $store->loads[0]->queryParameters);
    }

    public function testTheReadBackCarriesTheTagOfTheParentAsTheWriteLeftIt(): void
    {
        $resolver = BoundImplementations::of(self::implementations(self::writer(), [
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet(
                '1',
                'one',
                '102',
                'v2',
            )]]),
        ]));

        $result = self::processorWith(self::versionedRegistry(), $resolver)->process(
            self::write(OperationKind::ReplaceRelationship, 'owner'),
            self::context(record: new Pet('1', 'one', '101', 'v1')),
        );

        self::assertSame(
            VersionTag::encode('v2', null, MediaType::jsonApi())->toHeaderValue(),
            $result->entityTag()?->toHeaderValue(),
        );
    }

    #[DataProvider('kinds')]
    public function testAReloadThatReturnsNullRespondsWithNoContent(OperationKind $kind): void
    {
        $result = self::processor([
            ResourceLoader::class => new RecordingStore(records: []),
            CollectionReader::class => self::reader(new Pet('5', 'five')),
        ])->process(self::write($kind), self::context(record: new Pet('1', 'one', '101')));

        self::assertInstanceOf(NoContentResult::class, $result);
        self::assertSame(204, $result->status());
        self::assertNull($result->entityTag());
    }

    public function testTheIncludePathsOfTheWriteAreFollowedFromTheRelationshipReadBack(): void
    {
        $owner = new Pet('102', 'owner');

        $result = self::processor([
            ResourceLoader::class => new RecordingStore(records: [
                self::TYPE => ['1' => new Pet('1', 'one', '102')],
                'customers' => ['102' => $owner],
            ]),
        ])->process(
            self::write(OperationKind::ReplaceRelationship, 'owner', 'owner'),
            self::context(includeSupplied: true),
        );

        self::assertInstanceOf(RelationshipResult::class, $result);
        self::assertTrue($result->graph->isCompound());
        self::assertCount(1, $result->graph->included());
        self::assertSame('customers', $result->graph->included()[0]->type);
        self::assertSame($owner, $result->graph->included()[0]->record);
    }

    public function testTheReadBackAsksNoPermission(): void
    {
        $resolver = BoundImplementations::of(self::implementations(self::writer(), [
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]),
            CollectionReader::class => self::reader(),
        ]));

        self::processorWith(
            self::registry(),
            $resolver,
        )->process(self::write(OperationKind::AddToRelationship), self::context());

        self::assertSame([RelationshipAdder::class, ResourceLoader::class, CollectionReader::class], $resolver->ports);
    }

    #[DataProvider('pagesOfTheReadBack')]
    public function testTheReadBackPagesByCursorOnlyForAToManyUnderTheCursorProfile(
        string $relationship,
        bool $cursorPagination,
        ?Strategy $strategy,
    ): void {
        $result = self::processor([
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]),
            CollectionReader::class => self::reader(new Pet('5', 'five')),
        ])->process(
            self::write(OperationKind::ReplaceRelationship, $relationship),
            self::context(record: new Pet('1', 'one', '101'), cursorPagination: $cursorPagination),
        );

        self::assertInstanceOf(RelationshipResult::class, $result);

        if ($strategy === null) {
            self::assertFalse($result->linkage->isToMany());

            return;
        }

        self::assertSame($strategy, $result->linkage->page()->strategy);
    }

    /**
     * @return iterable<string, array{string, bool, ?Strategy}>
     */
    public static function pagesOfTheReadBack(): iterable
    {
        yield 'a to-many under the cursor profile' => ['orders', true, Strategy::Cursor];
        yield 'a to-many without the cursor profile' => ['orders', false, Strategy::PageNumber];
        yield 'a to-one under the cursor profile' => ['owner', true, null];
        yield 'a to-one without the cursor profile' => ['owner', false, null];
    }

    #[DataProvider('kinds')]
    public function testAnAcceptedWriteRespondsWith202WithTheMonitorResource(OperationKind $kind): void
    {
        $monitor = new Pet('201', 'working');
        $store = new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]);
        $operation = self::write($kind);

        $result = self::processorWith(
            self::registry(),
            BoundImplementations::of(self::implementations(
                self::writer(new Accepted(self::MONITOR, $monitor)),
                [ResourceLoader::class => $store],
            )),
        )->process($operation, self::context());

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame(202, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame(self::MONITOR, $result->monitor->type);
        self::assertSame('201', $result->monitor->id);
        self::assertSame($monitor, $result->monitor->record);
        self::assertSame($operation->fields, $result->fields);
        self::assertSame([], $store->loads);
    }

    /**
     * refusing an accepted write inside an atomic request is left to the caller,
     * so the processor returns the 202 there as anywhere else
     */
    public function testAnAcceptedWriteInsideAnAtomicRequestIsStillA202(): void
    {
        $result = self::processorWith(
            self::registry(),
            BoundImplementations::of(self::implementations(self::writer(new Accepted(
                self::MONITOR,
                new Pet('201', 'w'),
            )))),
        )->process(self::write(OperationKind::ReplaceRelationship, 'owner'), self::context(position: 0));

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame(202, $result->status());
    }

    #[DataProvider('monitorsTheDeclarationDoesNotName')]
    public function testAnAcceptedOfAnUndeclaredMonitorTypeIsRefused(
        string $returned,
        ?string $declared,
        string $message,
    ): void {
        $registry = self::registry($declared === null ? [] : [OperationKind::ReplaceRelationship->value => $declared]);
        $processor = self::processorWith(
            $registry,
            BoundImplementations::of(self::implementations(self::writer(new Accepted($returned, new Pet('201', 'w'))))),
        );

        try {
            $processor->process(self::write(OperationKind::ReplaceRelationship, 'owner'), self::context());
        } catch (UnexpectedValueException $exception) {
            self::assertSame($message, $exception->getMessage());

            return;
        }

        self::fail('a monitor of an undeclared type was returned');
    }

    /**
     * @return iterable<string, array{string, ?string, string}>
     */
    public static function monitorsTheDeclarationDoesNotName(): iterable
    {
        yield 'the declaration names no monitor type for the kind' => [
            self::MONITOR,
            null,
            'the store accepted the replace_relationship of type "pets" with a monitor of type "jobs",'
            . ' and the declaration names no monitor type for that kind',
        ];

        yield 'the declaration names another monitor type' => [
            'customers',
            self::MONITOR,
            'the store accepted the replace_relationship of type "pets" with a monitor of type'
            . ' "customers", and the declaration names the monitor type "jobs" for that kind',
        ];
    }

    #[DataProvider('failuresOfTheWrites')]
    public function testTheFailuresTheStoreReportsLeaveTheProcessorUntranslated(
        OperationKind $kind,
        RuntimeException $failure,
    ): void {
        $processor = self::processorWith(
            self::registry(),
            BoundImplementations::of(self::implementations(self::writer($failure))),
        );

        try {
            $processor->process(self::write($kind), self::context());
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);

            return;
        }

        self::fail('the failure of the store did not reach the caller');
    }

    /**
     * the failures each port declares,
     * which for `RelationshipRemover` exclude `RelatedResourceNotFoundException`
     *
     * @return iterable<string, array{OperationKind, RuntimeException}>
     */
    public static function failuresOfTheWrites(): iterable
    {
        $failures = [
            'a parent that is gone' => new ResourceNotFoundException(),
            'a member that does not exist' => new RelatedResourceNotFoundException(['orders' => [new Identifier(
                'orders',
                '9',
            )]]),
            'a constraint the store enforces' => new ConstraintViolationException('too_many_orders'),
            'a precondition that does not hold' => new VersionMismatchException(),
        ];

        foreach ([
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ] as $kind) {
            foreach ($failures as $name => $failure) {
                if (
                    $kind === OperationKind::RemoveFromRelationship
                    && $failure instanceof RelatedResourceNotFoundException
                ) {
                    continue;
                }

                yield $kind->value . ': ' . $name => [$kind, $failure];
            }
        }
    }

    /**
     * @param array<mixed> $loaded
     */
    #[DataProvider('brokenReloads')]
    public function testALoaderThatBreaksTheBatchRuleOfTheReloadIsRefused(array $loaded, string $message): void
    {
        $loader = self::createStub(ResourceLoader::class);
        $loader->method('load')->willReturn($loaded);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::processor([ResourceLoader::class => $loader])
            ->process(self::write(OperationKind::ReplaceRelationship, 'owner'), self::context());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenReloads(): iterable
    {
        yield 'no record for the one id' => [
            [],
            'the resource loader of type "pets" returned 0 entries for a request of 1, and "The Array of'
            . ' values must be the same length as the Array of keys."',
        ];
        yield 'two records for the one id' => [
            [new Pet('1', 'one'), new Pet('2', 'two')],
            'the resource loader of type "pets" returned 2 entries for a request of 1, and "The Array of'
            . ' values must be the same length as the Array of keys."',
        ];
        yield 'a record keyed by its id' => [
            ['1' => new Pet('1', 'one')],
            'the resource loader of type "pets" returned an array keyed by other than its positions',
        ];
        yield 'a value that is no record' => [
            ['one'],
            'the resource loader of type "pets" returned string at the place of id "1", and a loader'
            . ' returns the record of each id or null for the record it does not give',
        ];
    }

    #[DataProvider('writes')]
    public function testAWriteOfATypeThatBindsNoPortOfItsKindIsRefused(OperationKind $kind, string $port): void
    {
        $registry = new ResourceRegistry([
            Samples::resource(
                self::TYPE,
                relationships: [Samples::toMany('orders', 'orders')],
                capabilities: [Capability::RelatedLoader],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
        ]);

        try {
            self::processorWith($registry, BoundImplementations::of([]))->process(self::write($kind), self::context());
        } catch (LogicException $exception) {
            self::assertStringStartsWith(
                sprintf('type "pets" binds no %s: ', array_search($port, self::ports(), true)),
                $exception->getMessage(),
            );

            return;
        }

        self::fail('a type that binds no port of the kind was executed');
    }

    public function testAWriteOfARelationshipTheTypeDoesNotDeclareIsRefused(): void
    {
        $processor = self::processor([
            ResourceLoader::class => new RecordingStore(records: [self::TYPE => ['1' => new Pet('1', 'one', '102')]]),
        ]);

        try {
            $processor->process(self::write(OperationKind::ReplaceRelationship, 'breeder'), self::context());
        } catch (OutOfBoundsException $exception) {
            self::assertSame('type "pets" declares no relationship "breeder"', $exception->getMessage());

            return;
        }

        self::fail('a relationship the type does not declare was read back');
    }

    /**
     * mirrors the port of each write capability in `StoreGateway`
     *
     * @return array<value-of<Capability>, class-string>
     */
    private static function ports(): array
    {
        return [
            Capability::RelationshipReplacer->value => RelationshipReplacer::class,
            Capability::RelationshipAdder->value => RelationshipAdder::class,
            Capability::RelationshipRemover->value => RelationshipRemover::class,
        ];
    }

    private static function write(
        OperationKind $kind,
        string $relationship = 'orders',
        ?string $include = null,
    ): ReplaceRelationship|AddToRelationship|RemoveFromRelationship {
        $parent = new Identifier(self::TYPE, '1');
        $linkage = $relationship === 'owner'
            ? Linkage::toOne(new Identifier('customers', '102'))
            : Linkage::toMany([new Identifier('orders', '5')]);
        $fields = new SparseFieldsets([self::TYPE => ['name']]);
        $languages = new LanguagePreferences([]);

        return match ($kind) {
            OperationKind::AddToRelationship => new AddToRelationship(
                $parent,
                $relationship,
                $linkage,
                null,
                WrittenInclude::paths($include),
                $fields,
                $languages,
                new Values([]),
            ),
            OperationKind::RemoveFromRelationship => new RemoveFromRelationship(
                $parent,
                $relationship,
                $linkage,
                null,
                WrittenInclude::paths($include),
                $fields,
                $languages,
                new Values([]),
            ),
            default => new ReplaceRelationship(
                $parent,
                $relationship,
                $linkage,
                null,
                WrittenInclude::paths($include),
                $fields,
                $languages,
                new Values([]),
            ),
        };
    }

    /**
     * @param array<class-string, object> $implementations store port => implementation,
     *                                                    besides the write ports
     */
    private static function processor(array $implementations): RelationshipWriteProcessor
    {
        return self::processorWith(
            self::registry(),
            BoundImplementations::of(self::implementations(self::writer(), $implementations)),
        );
    }

    private static function processorWith(
        ResourceRegistry $registry,
        ImplementationResolver $resolver,
    ): RelationshipWriteProcessor {
        $store = new StoreGateway($registry, $resolver);
        $paginator = new Paginator($registry, $store);
        $includes = new IncludeLoader($registry, $store, $paginator);

        return new RelationshipWriteProcessor(
            $registry,
            $store,
            new AcceptedWrites($registry, $includes),
            new FetchRelationshipProcessor($registry, $store, $paginator, $includes, new Preconditions($registry)),
        );
    }

    /**
     * @param array<class-string, object> $implementations
     *
     * @return array<class-string, object>
     */
    private static function implementations(object $writer, array $implementations = []): array
    {
        return [
            RelationshipReplacer::class => $writer,
            RelationshipAdder::class => $writer,
            RelationshipRemover::class => $writer,
            ...$implementations,
        ];
    }

    /**
     * @return RelationshipReplacer&RelationshipAdder&RelationshipRemover&object{
     *     written: list<ReplaceRelationship|AddToRelationship|RemoveFromRelationship>,
     * }
     */
    private static function writer(Accepted|Throwable|null $result = null): object
    {
        return new class ($result) implements RelationshipReplacer, RelationshipAdder, RelationshipRemover {
            /**
             * @var list<ReplaceRelationship|AddToRelationship|RemoveFromRelationship>
             */
            public array $written = [];

            public function __construct(private readonly Accepted|Throwable|null $result) {}

            #[Override]
            public function replace(ReplaceRelationship $operation): ?Accepted
            {
                return $this->result($operation);
            }

            #[Override]
            public function add(AddToRelationship $operation): ?Accepted
            {
                return $this->result($operation);
            }

            #[Override]
            public function remove(RemoveFromRelationship $operation): ?Accepted
            {
                return $this->result($operation);
            }

            private function result(ReplaceRelationship|AddToRelationship|RemoveFromRelationship $operation): ?Accepted
            {
                $this->written[] = $operation;

                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    /**
     * @return CollectionReader<object>&object{queries: list<CollectionQuery>}
     */
    private static function reader(Pet ...$records): object
    {
        return new class (array_values($records)) implements CollectionReader {
            /**
             * @var list<CollectionQuery>
             */
            public array $queries = [];

            /**
             * @param list<object> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return Slice<object>
             */
            #[Override]
            public function read(CollectionQuery $query): Slice
            {
                $this->queries[] = $query;

                return new Slice(array_slice($this->records, 0, $query->window->limit));
            }
        };
    }

    /**
     * @param array<value-of<OperationKind>, string> $monitorTypes
     */
    private static function registry(
        array $monitorTypes = [
            OperationKind::ReplaceRelationship->value => self::MONITOR,
            OperationKind::AddToRelationship->value => self::MONITOR,
            OperationKind::RemoveFromRelationship->value => self::MONITOR,
        ],
    ): ResourceRegistry {
        return new ResourceRegistry([
            Samples::resource(
                self::TYPE,
                attributes: [Samples::attribute('name')],
                relationships: [
                    Samples::toOne('owner', 'customers', updates: [RelationshipUpdate::Replace]),
                    Samples::toMany(
                        'orders',
                        'orders',
                        updates: [RelationshipUpdate::Replace, RelationshipUpdate::Add, RelationshipUpdate::Remove],
                        paginationPolicy: new PaginationPolicy(2, 100),
                    ),
                ],
                capabilities: [
                    Capability::RelationshipReplacer,
                    Capability::RelationshipAdder,
                    Capability::RelationshipRemover,
                    Capability::RelatedLoader,
                ],
                monitorTypes: $monitorTypes,
            ),
            Samples::resource('customers'),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource(self::MONITOR),
        ]);
    }

    private static function versionedRegistry(): ResourceRegistry
    {
        return new ResourceRegistry([
            new ResourceMetadata(
                self::TYPE,
                Samples::id(),
                'id',
                [],
                ['owner' => Samples::toOne('owner', 'customers', updates: [RelationshipUpdate::Replace])],
                [
                    Capability::ResourceLoader->value => Samples::implementation(),
                    Capability::RelationshipReplacer->value => Samples::implementation(),
                ],
                [
                    OperationKind::FetchResource->value => Samples::implementation(),
                    OperationKind::FetchRelated->value => Samples::implementation(),
                    OperationKind::FetchRelationship->value => Samples::implementation(),
                    OperationKind::ReplaceRelationship->value => Samples::implementation(),
                ],
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
            ),
            Samples::resource('customers'),
        ]);
    }

    private static function context(
        ?Pet $record = null,
        bool $includeSupplied = false,
        bool $cursorPagination = false,
        ?int $position = null,
    ): ExecutionContext {
        return new ExecutionContext(
            new ServerRequest('PATCH', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            $includeSupplied,
            $cursorPagination,
            [],
            $record,
            $position,
        );
    }
}
