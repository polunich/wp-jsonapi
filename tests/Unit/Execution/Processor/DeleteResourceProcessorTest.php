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
use Polunich\WpJsonApi\Execution\Processor\AcceptedWrites;
use Polunich\WpJsonApi\Execution\Processor\DeleteResourceProcessor;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Store\Accepted;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\ResourceDeleter;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(DeleteResourceProcessor::class)]
final class DeleteResourceProcessorTest extends TestCase
{
    private const TYPE = 'pets';

    private const MONITOR = 'jobs';

    public function testAnOperationOfAnotherKindIsRefused(): void
    {
        $processor = self::processor([ResourceDeleter::class => self::deleter(null)]);

        try {
            $processor->process(
                new FetchResource(
                    new Identifier(self::TYPE, '1'),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                ),
                self::context(),
            );
        } catch (LogicException $exception) {
            self::assertSame(
                'a delete_resource processor processes a delete_resource operation, and this one is fetch_resource',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('an operation of another kind got a result');
    }

    public function testTheDeleterIsResolvedAsTheStorePortAndGivenTheOperation(): void
    {
        $store = self::deleter(null);
        $resolver = BoundImplementations::of([ResourceDeleter::class => $store]);
        $operation = self::delete(precondition: Precondition::anyVersion());

        self::processorWith(self::registry(), $resolver)->process($operation, self::context());

        self::assertSame([$operation], $store->deleted);
        self::assertSame([ResourceDeleter::class], $resolver->ports);
    }

    public function testAFinishedDeleteRespondsWith204WithNoEntityTag(): void
    {
        $result = self::processor([ResourceDeleter::class => self::deleter(null)])
            ->process(self::delete(), self::context());

        self::assertInstanceOf(NoContentResult::class, $result);
        self::assertSame(204, $result->status());
        self::assertNull($result->entityTag());
    }

    public function testAnAcceptedDeleteRespondsWith202WithTheMonitorResourceAsPrimaryData(): void
    {
        $monitor = new Pet('201', 'working');

        $result = self::processor([ResourceDeleter::class => self::deleter(new Accepted(self::MONITOR, $monitor))])
            ->process(self::delete(), self::context());

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame(202, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame(self::MONITOR, $result->monitor->type);
        self::assertSame('201', $result->monitor->id);
        self::assertSame($monitor, $result->monitor->record);
    }

    public function testThe202OfADeleteCarriesTheEmptyFieldsets(): void
    {
        $result = self::processor([ResourceDeleter::class => self::deleter(new Accepted(
            self::MONITOR,
            new Pet('201', 'w'),
        ))])
            ->process(self::delete(), self::context());

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertTrue($result->fields->isEmpty());
        self::assertNull($result->fields->fields(self::MONITOR));
    }

    public function testThe202OfADeleteIncludesNothingAndWritesNoIncludedMember(): void
    {
        $result = self::processor([ResourceDeleter::class => self::deleter(new Accepted(
            self::MONITOR,
            new Pet('201', 'w'),
        ))])
            ->process(self::delete(), self::context());

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame([], $result->graph->included());
        self::assertFalse($result->graph->isCompound());
    }

    #[DataProvider('monitorsTheDeclarationDoesNotName')]
    public function testAnAcceptedOfAnUndeclaredMonitorTypeIsRefused(
        string $returned,
        ?string $declared,
        string $message,
    ): void {
        $registry = self::registry($declared === null ? [] : [OperationKind::DeleteResource->value => $declared]);
        $processor = self::processorWith(
            $registry,
            BoundImplementations::of([ResourceDeleter::class => self::deleter(new Accepted(
                $returned,
                new Pet('201', 'w'),
            ))]),
        );

        try {
            $processor->process(self::delete(), self::context());
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
            'the store accepted the delete_resource of type "pets" with a monitor of type "jobs", and'
            . ' the declaration names no monitor type for that kind',
        ];

        yield 'the declaration names another monitor type' => [
            'customers',
            self::MONITOR,
            'the store accepted the delete_resource of type "pets" with a monitor of type "customers", and'
            . ' the declaration names the monitor type "jobs" for that kind',
        ];
    }

    #[DataProvider('failuresOfADelete')]
    public function testTheFailuresTheStoreReportsLeaveTheProcessorUntranslated(RuntimeException $failure): void
    {
        $processor = self::processor([ResourceDeleter::class => self::deleter($failure)]);

        try {
            $processor->process(self::delete(), self::context());
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);

            return;
        }

        self::fail('the failure of the store did not reach the caller');
    }

    /**
     * exactly the failures `ResourceDeleter::delete()` declares
     *
     * @return iterable<string, array{RuntimeException}>
     */
    public static function failuresOfADelete(): iterable
    {
        yield 'a target that is gone' => [new ResourceNotFoundException()];
        yield 'a constraint the store enforces' => [new ConstraintViolationException('still_referenced')];
        yield 'a precondition the stored version does not meet' => [new VersionMismatchException()];
    }

    public function testADeleteOfATypeThatBindsNoDeleterIsRefused(): void
    {
        $registry = new ResourceRegistry([Samples::resource(self::TYPE)]);
        $processor = self::processorWith($registry, BoundImplementations::of([]));

        try {
            $processor->process(self::delete(), self::context());
        } catch (LogicException $exception) {
            self::assertStringStartsWith('type "pets" binds no resource_deleter: ', $exception->getMessage());

            return;
        }

        self::fail('a type that binds no deleter was executed');
    }

    private static function delete(string $id = '1', ?Precondition $precondition = null): DeleteResource
    {
        return new DeleteResource(
            new Identifier(self::TYPE, $id),
            $precondition,
            new LanguagePreferences([]),
            new Values([]),
        );
    }

    /**
     * @param array<class-string, object> $implementations store port => implementation
     */
    private static function processor(array $implementations): DeleteResourceProcessor
    {
        return self::processorWith(self::registry(), BoundImplementations::of($implementations));
    }

    private static function processorWith(
        ResourceRegistry $registry,
        ImplementationResolver $resolver,
    ): DeleteResourceProcessor {
        $store = new StoreGateway($registry, $resolver);

        return new DeleteResourceProcessor(
            $store,
            new AcceptedWrites($registry, new IncludeLoader($registry, $store, new Paginator($registry, $store))),
        );
    }

    /**
     * @return ResourceDeleter&object{deleted: list<DeleteResource>}
     */
    private static function deleter(Accepted|Throwable|null $result): object
    {
        return new class ($result) implements ResourceDeleter {
            /**
             * @var list<DeleteResource>
             */
            public array $deleted = [];

            public function __construct(private readonly Accepted|Throwable|null $result) {}

            #[Override]
            public function delete(DeleteResource $operation): ?Accepted
            {
                $this->deleted[] = $operation;

                if ($this->result instanceof Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };
    }

    /**
     * @param array<value-of<OperationKind>, string> $monitorTypes
     */
    private static function registry(
        array $monitorTypes = [OperationKind::DeleteResource->value => self::MONITOR],
    ): ResourceRegistry {
        return new ResourceRegistry([
            Samples::resource(
                self::TYPE,
                attributes: [Samples::attribute('name')],
                relationships: [Samples::toOne('owner', 'customers')],
                capabilities: [Capability::ResourceDeleter],
                monitorTypes: $monitorTypes,
            ),
            Samples::resource('customers'),
            Samples::resource(self::MONITOR),
        ]);
    }

    private static function context(?Pet $record = null): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('DELETE', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            false,
            false,
            [],
            $record,
        );
    }
}
