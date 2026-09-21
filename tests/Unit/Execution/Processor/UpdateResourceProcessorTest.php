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
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\AcceptedWrites;
use Polunich\WpJsonApi\Execution\Processor\UpdateResourceProcessor;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
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
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceUpdater;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Query\WrittenInclude;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(UpdateResourceProcessor::class)]
final class UpdateResourceProcessorTest extends TestCase
{
    private const TYPE = 'pets';

    private const MONITOR = 'jobs';

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $processor = self::processor([ResourceUpdater::class => self::updater(new Pet('1', 'a'))]);

        try {
            $processor->process(
                new CreateResource(
                    self::TYPE,
                    null,
                    new Values([]),
                    new Values([]),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                ),
                self::context(),
            );
        } catch (LogicException $exception) {
            self::assertSame(
                'an update_resource processor processes an update_resource operation, and this one is create_resource',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('an operation of another kind got a result');
    }

    public function testTheUpdaterIsResolvedAsTheStorePortAndGivenTheOperation(): void
    {
        $store = self::updater(new Pet('1', 'a'));
        $resolver = BoundImplementations::of([ResourceUpdater::class => $store]);
        $operation = self::update();

        self::processorWith(self::registry(), $resolver)->process($operation, self::context());

        self::assertSame([$operation], $store->updated);
        self::assertSame([ResourceUpdater::class], $resolver->ports);
    }

    public function testAFinishedUpdateRespondsWith200WithTheRecordTheStoreReturned(): void
    {
        $updated = new Pet('7', 'after');
        $operation = self::update('7');

        $result = self::processor([ResourceUpdater::class => self::updater($updated)])
            ->process($operation, self::context(record: new Pet('7', 'before')));

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertSame(self::TYPE, $result->type);
        self::assertInstanceOf(ResourceRecord::class, $result->resource);
        self::assertSame(self::TYPE, $result->resource->type);
        self::assertSame('7', $result->resource->id);
        self::assertSame($updated, $result->resource->record);
        self::assertSame($operation->fields, $result->fields);
    }

    public function testThe200CarriesTheEntityTagWrittenOffTheUpdatedRecord(): void
    {
        $registry = self::versionedRegistry();
        $updated = new Pet('1', 'a', null, 'v9');

        $result = self::processorWith(
            $registry,
            BoundImplementations::of([ResourceUpdater::class => self::updater($updated)]),
        )
            ->process(self::update(), self::context(record: new Pet('1', 'a', null, 'v8'), contentLanguage: 'en'));

        self::assertSame(
            VersionTag::encode('v9', 'en', MediaType::jsonApi())->toHeaderValue(),
            $result->entityTag()?->toHeaderValue(),
        );
    }

    public function testThe200CarriesNoEntityTagInsideAnAtomicRequest(): void
    {
        $registry = self::versionedRegistry();

        $result = self::processorWith(
            $registry,
            BoundImplementations::of([ResourceUpdater::class => self::updater(new Pet('1', 'a'))]),
        )
            ->process(self::update(), self::context(position: 0));

        self::assertNull($result->entityTag());
    }

    public function testThe200CarriesTheResourcesTheIncludePathsOfTheRequestReach(): void
    {
        $owner = new Pet('101', 'owner');
        $processor = self::processor([
            ResourceUpdater::class => self::updater(new Pet('1', 'a', '101')),
            ResourceLoader::class => self::loader(['customers' => ['101' => $owner]]),
        ]);

        $result = $processor->process(self::update(include: 'owner'), self::context(includeSupplied: true));

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertTrue($result->graph->isCompound());
        self::assertCount(1, $result->graph->included());
        self::assertSame('customers', $result->graph->included()[0]->type);
        self::assertSame('101', $result->graph->included()[0]->id);
        self::assertSame($owner, $result->graph->included()[0]->record);
    }

    public function testAnAcceptedUpdateRespondsWith202WithTheMonitorResourceAsPrimaryData(): void
    {
        $monitor = new Pet('201', 'working');
        $operation = self::update();

        $result = self::processor([ResourceUpdater::class => self::updater(new Accepted(self::MONITOR, $monitor))])
            ->process($operation, self::context());

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame(202, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame(self::MONITOR, $result->monitor->type);
        self::assertSame('201', $result->monitor->id);
        self::assertSame($monitor, $result->monitor->record);
        self::assertSame($operation->fields, $result->fields);
    }

    #[DataProvider('includeSupplied')]
    public function testTheIncludedMemberOfA202IsEmptyAndPresentOnlyWhereIncludeWasSupplied(bool $supplied): void
    {
        $result = self::processor([ResourceUpdater::class => self::updater(new Accepted(
            self::MONITOR,
            new Pet('201', 'w'),
        ))])
            ->process(self::update(include: $supplied ? '' : null), self::context(includeSupplied: $supplied));

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame($supplied, $result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function includeSupplied(): iterable
    {
        yield 'include supplied' => [true];
        yield 'include not supplied' => [false];
    }

    #[DataProvider('monitorsTheDeclarationDoesNotName')]
    public function testAnAcceptedOfAnUndeclaredMonitorTypeIsRefused(
        string $returned,
        ?string $declared,
        string $message,
    ): void {
        $registry = self::registry($declared === null ? [] : [OperationKind::UpdateResource->value => $declared]);
        $processor = self::processorWith(
            $registry,
            BoundImplementations::of([ResourceUpdater::class => self::updater(new Accepted(
                $returned,
                new Pet('201', 'w'),
            ))]),
        );

        try {
            $processor->process(self::update(), self::context());
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
            'the store accepted the update_resource of type "pets" with a monitor of type "jobs", and'
            . ' the declaration names no monitor type for that kind',
        ];

        yield 'the declaration names another monitor type' => [
            'customers',
            self::MONITOR,
            'the store accepted the update_resource of type "pets" with a monitor of type "customers", and'
            . ' the declaration names the monitor type "jobs" for that kind',
        ];
    }

    #[DataProvider('failuresOfAnUpdate')]
    public function testTheFailuresTheStoreReportsLeaveTheProcessorUntranslated(RuntimeException $failure): void
    {
        $processor = self::processor([ResourceUpdater::class => self::updater($failure)]);

        try {
            $processor->process(self::update(), self::context());
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);

            return;
        }

        self::fail('the failure of the store did not reach the caller');
    }

    /**
     * exactly the failures `ResourceUpdater::update()` declares
     *
     * @return iterable<string, array{RuntimeException}>
     */
    public static function failuresOfAnUpdate(): iterable
    {
        yield 'a target that is gone' => [new ResourceNotFoundException()];
        yield 'a related resource that does not exist' => [
            new RelatedResourceNotFoundException(['owner' => [new Identifier('customers', '109')]]),
        ];
        yield 'a constraint the store enforces' => [new ConstraintViolationException('unique_name')];
        yield 'a precondition the stored version does not meet' => [new VersionMismatchException()];
    }

    public function testAnUpdateOfATypeThatBindsNoUpdaterIsRefused(): void
    {
        $registry = new ResourceRegistry([Samples::resource(self::TYPE)]);
        $processor = self::processorWith($registry, BoundImplementations::of([]));

        try {
            $processor->process(self::update(), self::context());
        } catch (LogicException $exception) {
            self::assertStringStartsWith('type "pets" binds no resource_updater: ', $exception->getMessage());

            return;
        }

        self::fail('a type that binds no updater was executed');
    }

    private static function update(string $id = '1', ?string $include = null): UpdateResource
    {
        return new UpdateResource(
            new Identifier(self::TYPE, $id),
            new Values([]),
            new Values([]),
            null,
            WrittenInclude::paths($include),
            new SparseFieldsets([self::TYPE => ['name']]),
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @param array<class-string, object> $implementations store port => implementation
     */
    private static function processor(array $implementations): UpdateResourceProcessor
    {
        return self::processorWith(self::registry(), BoundImplementations::of($implementations));
    }

    private static function processorWith(
        ResourceRegistry $registry,
        ImplementationResolver $resolver,
    ): UpdateResourceProcessor {
        $store = new StoreGateway($registry, $resolver);
        $includes = new IncludeLoader($registry, $store, new Paginator($registry, $store));

        return new UpdateResourceProcessor(
            $registry,
            $store,
            $includes,
            new Preconditions($registry),
            new AcceptedWrites($registry, $includes),
        );
    }

    /**
     * @return ResourceUpdater<object>&object{updated: list<UpdateResource>}
     */
    private static function updater(object $result): object
    {
        return new class ($result) implements ResourceUpdater {
            /**
             * @var list<UpdateResource>
             */
            public array $updated = [];

            public function __construct(private readonly object $result) {}

            #[Override]
            public function update(UpdateResource $operation): object
            {
                $this->updated[] = $operation;

                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    /**
     * @param array<array-key, array<array-key, Pet>> $records type => id => record
     *
     * @return ResourceLoader<object>
     */
    private static function loader(array $records): ResourceLoader
    {
        return new class ($records) implements ResourceLoader {
            /**
             * @param array<array-key, array<array-key, Pet>> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<Pet|null>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $loaded = [];

                foreach ($request->ids as $id) {
                    $loaded[] = $this->records[$request->type][$id] ?? null;
                }

                return $loaded;
            }
        };
    }

    /**
     * @param array<value-of<OperationKind>, string> $monitorTypes
     */
    private static function registry(
        array $monitorTypes = [OperationKind::UpdateResource->value => self::MONITOR],
    ): ResourceRegistry {
        return new ResourceRegistry([
            Samples::resource(
                self::TYPE,
                attributes: [Samples::attribute('name')],
                relationships: [Samples::toOne('owner', 'customers')],
                capabilities: [Capability::ResourceUpdater],
                monitorTypes: $monitorTypes,
            ),
            Samples::resource('customers'),
            Samples::resource(self::MONITOR),
        ]);
    }

    /**
     * an unversioned type writes no tag at all,
     * so only a versioned one shows that an atomic request is what drops the tag
     */
    private static function versionedRegistry(): ResourceRegistry
    {
        return new ResourceRegistry([new ResourceMetadata(
            self::TYPE,
            Samples::id(),
            'id',
            [],
            [],
            [
                Capability::ResourceLoader->value => Samples::implementation(),
                Capability::ResourceUpdater->value => Samples::implementation(),
            ],
            [
                OperationKind::FetchResource->value => Samples::implementation(),
                OperationKind::UpdateResource->value => Samples::implementation(),
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
        )]);
    }

    private static function context(
        bool $includeSupplied = false,
        ?string $contentLanguage = null,
        ?int $position = null,
        ?Pet $record = null,
    ): ExecutionContext {
        return new ExecutionContext(
            new ServerRequest('PATCH', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            $contentLanguage,
            $includeSupplied,
            false,
            [],
            $record,
            $position,
        );
    }
}
