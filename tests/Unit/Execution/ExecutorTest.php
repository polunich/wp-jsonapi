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

use Closure;
use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\OperationProcessor;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Access\ClosurePermission;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedIdentity;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\NoChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * the translation tests may name fields the registry does not declare,
 * such as `slug` or the relationship `2`: the translation reads the operation alone
 *
 * @internal
 */
#[CoversClass(Executor::class)]
final class ExecutorTest extends TestCase
{
    private const TYPE = 'pets';

    private const ID = '1';

    #[DataProvider('kinds')]
    public function testAnExecutorIsBuiltWithAProcessorForEveryKind(OperationKind $missing): void
    {
        $processors = self::unusedProcessors();
        unset($processors[$missing->value]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no processor is bound for the kind ' . $missing->value);

        new Executor(self::registry(), new StoreGateway(self::registry(), self::resolver()), self::gate(), $processors);
    }

    /**
     * @return iterable<string, array{OperationKind}>
     */
    public static function kinds(): iterable
    {
        foreach (OperationKind::cases() as $kind) {
            yield $kind->value => [$kind];
        }
    }

    /**
     * @param (
     *     FetchResource
     *     |FetchRelated
     *     |FetchRelationship
     *     |UpdateResource
     *     |DeleteResource
     *     |ReplaceRelationship
     *     |AddToRelationship
     *     |RemoveFromRelationship
     * ) $operation
     */
    #[DataProvider('operationsOnAnExistingResource')]
    public function testTheTargetRequestNamesTheResourceTheOperationActsOn(Operation $operation): void
    {
        $request = self::executor()->targetRequest($operation);

        self::assertSame(self::TYPE, $request->type);
        self::assertSame([self::ID], $request->ids);
    }

    /**
     * @param (
     *     FetchResource
     *     |FetchRelated
     *     |FetchRelationship
     *     |UpdateResource
     *     |DeleteResource
     *     |ReplaceRelationship
     *     |AddToRelationship
     *     |RemoveFromRelationship
     * ) $operation
     */
    #[DataProvider('operationsOnAnExistingResource')]
    public function testTheTargetRequestCarriesTheLanguagesTheClientStated(Operation $operation): void
    {
        self::assertEquals(self::languages(), self::executor()->targetRequest($operation)->languages);
    }

    public function testTheTargetRequestCarriesTheQueryParametersOfTheRoute(): void
    {
        $queryParameters = new Values(['hardDelete' => 'yes']);
        $operation = new DeleteResource(new Identifier(self::TYPE, '1'), null, self::languages(), $queryParameters);

        self::assertSame($queryParameters, self::executor()->targetRequest($operation)->queryParameters);
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function operationsOnAnExistingResource(): iterable
    {
        yield 'fetch_resource' => [self::fetchResource()];
        yield 'update_resource' => [self::update()];
        yield 'delete_resource' => [self::delete()];
        yield 'fetch_related' => [self::fetchRelated()];
        yield 'fetch_relationship' => [self::fetchRelationship()];
        yield 'replace_relationship' => [self::replace()];
        yield 'add_to_relationship' => [self::add()];
        yield 'remove_from_relationship' => [self::remove()];
    }

    /**
     * `owner` joins any fieldset of `pets`: its linkage is read off the record
     *
     * @param ?list<string> $expected
     */
    #[DataProvider('fieldsetsOfAFetch')]
    public function testTheTargetRequestOfAFetchOfAResourceCarriesTheFieldsetOfTheRequest(
        SparseFieldsets $fields,
        ?array $expected,
    ): void {
        $request = self::executor()->targetRequest(self::fetchResource(fields: $fields));

        self::assertSame($expected, $request->fields->fields());
    }

    /**
     * @return iterable<string, array{SparseFieldsets, ?list<string>}>
     */
    public static function fieldsetsOfAFetch(): iterable
    {
        yield 'a fieldset of the type, widened by what the library reads' => [
            new SparseFieldsets([self::TYPE => ['name']]),
            ['name', 'owner'],
        ];
        yield 'a fieldset that names none of the fields of the type' => [
            new SparseFieldsets([self::TYPE => []]),
            ['owner'],
        ];
        yield 'a fieldset of another type, which leaves this one every field' => [
            new SparseFieldsets(['orders' => ['address']]),
            null,
        ];
        yield 'no fieldset at all' => [new SparseFieldsets([]), null];
    }

    /**
     * @param (
     *     FetchRelated
     *     |FetchRelationship
     *     |UpdateResource
     *     |DeleteResource
     *     |ReplaceRelationship
     *     |AddToRelationship
     *     |RemoveFromRelationship
     * ) $operation
     */
    #[DataProvider('operationsThatRenderNoTargetRecord')]
    public function testTheTargetRequestOfEveryOtherKindNamesEveryField(Operation $operation): void
    {
        self::assertTrue(self::executor()->targetRequest($operation)->fields->hasEveryField());
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function operationsThatRenderNoTargetRecord(): iterable
    {
        $fields = new SparseFieldsets([self::TYPE => ['name']]);

        yield 'update_resource' => [self::update(fields: $fields)];
        yield 'delete_resource' => [self::delete()];
        yield 'fetch_related' => [self::fetchRelated(fields: $fields)];
        yield 'fetch_relationship' => [self::fetchRelationship(fields: $fields)];
        yield 'replace_relationship' => [self::replace(fields: $fields)];
        yield 'add_to_relationship' => [self::add(fields: $fields)];
        yield 'remove_from_relationship' => [self::remove(fields: $fields)];
    }

    #[DataProvider('operationsOnNoExistingResource')]
    public function testAKindThatActsOnNoExistingResourceHasNoTargetRequest(Operation $operation): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a ' . $operation->kind()->value . ' operation names no target and no parent');

        self::executor()->targetRequest($operation);
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function operationsOnNoExistingResource(): iterable
    {
        yield 'fetch_collection' => [self::fetchCollection()];
        yield 'create_resource' => [self::create()];
    }

    public function testTheTargetRequestOfAFetchOfAnUndeclaredTypeIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "jobs" is not declared');

        self::executor()->targetRequest(self::fetchResource(type: 'jobs'));
    }

    public function testTheLoaderOfTheTypeIsAskedForTheRequestAndReturnsTheTarget(): void
    {
        $record = new Pet(self::ID, 'one');
        $loader = self::loader([$record]);
        $request = self::loadRequest();

        $target = self::executor(loader: $loader)
            ->loadTarget($request, OperationKind::FetchResource, null, self::context());

        self::assertSame($record, $target);
        self::assertSame([$request], $loader->asked);
    }

    public function testTheGateIsCheckedAtLevel2WithTheLoadedRecord(): void
    {
        $record = new Pet(self::ID, 'one');
        $seen = [];
        $context = self::context();

        self::executor(
            loader: self::loader([$record]),
            permission: new ClosurePermission(static function (OperationAttempt $attempt) use (&$seen): bool {
                $seen[] = $attempt;

                return true;
            }),
        )->loadTarget(self::loadRequest(), OperationKind::FetchRelated, 'orders', $context);

        self::assertCount(1, $seen);
        self::assertSame(OperationKind::FetchRelated, $seen[0]->kind);
        self::assertSame(self::TYPE, $seen[0]->type);
        self::assertSame('orders', $seen[0]->relationship);
        self::assertSame($context->request, $seen[0]->request);
        self::assertSame($record, $seen[0]->record);
    }

    public function testTheRefusalOfTheGateAtLevel2IsTheResponse(): void
    {
        $this->expectException(ForbiddenException::class);

        self::executor(
            loader: self::loader([new Pet(self::ID, 'one')]),
            permission: new ClosurePermission(static function (OperationAttempt $attempt): bool {
                return false;
            }),
        )->loadTarget(self::loadRequest(), OperationKind::FetchResource, null, self::context());
    }

    public function testARefusalAtLevel2AsksForTheChallengesOfTheSchemesOfTheContext(): void
    {
        $challenges = new RecordingChallenges([new Challenge('Basic', ['realm' => 'WordPress'])]);
        $schemes = [SecurityScheme::restNonce()];

        try {
            self::executor(
                loader: self::loader([new Pet(self::ID, 'one')]),
                permission: new ClosurePermission(static function (OperationAttempt $attempt): bool {
                    return false;
                }),
                denials: new DenialPolicy(new FixedIdentity(false), $challenges),
            )->loadTarget(self::loadRequest(), OperationKind::FetchResource, null, self::context($schemes));
            self::fail('the executor returned a target the gate refused');
        } catch (UnauthorizedException) {
            self::assertSame([$schemes], $challenges->asked);
        }
    }

    public function testALoaderThatReturnsNullIsTheGeneric404(): void
    {
        try {
            self::executor(loader: self::loader([null]))
                ->loadTarget(self::loadRequest(), OperationKind::FetchResource, null, self::context());
            self::fail('the executor returned a record for a target that was not found');
        } catch (NotFoundException $exception) {
            self::assertSame(404, $exception->status());
            self::assertCount(1, $exception->descriptions());
            self::assertSame(ErrorCode::NotFound, $exception->descriptions()[0]->code);
            self::assertSame([], $exception->descriptions()[0]->parameters);
            self::assertNull($exception->descriptions()[0]->pointer);
            self::assertNull($exception->descriptions()[0]->parameter);
            self::assertNull($exception->descriptions()[0]->header);
        }
    }

    public function testNoPermissionIsAskedForATargetThatIsNotFound(): void
    {
        $asked = 0;

        try {
            self::executor(
                loader: self::loader([null]),
                permission: new ClosurePermission(static function (OperationAttempt $attempt) use (&$asked): bool {
                    ++$asked;

                    return true;
                }),
            )->loadTarget(self::loadRequest(), OperationKind::FetchResource, null, self::context());
            self::fail('the executor returned a record for a target that was not found');
        } catch (NotFoundException) {
            self::assertSame(0, $asked);
        }
    }

    /**
     * @param array<mixed> $batch
     */
    #[DataProvider('brokenBatchResults')]
    public function testALoaderThatBreaksTheBatchRuleIsRefused(array $batch, string $message): void
    {
        $loader = self::createStub(ResourceLoader::class);
        $loader->method('load')->willReturn($batch);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::executor(loader: $loader)
            ->loadTarget(self::loadRequest(), OperationKind::FetchResource, null, self::context());
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function brokenBatchResults(): iterable
    {
        yield 'a map instead of a list' => [
            ['1' => new Pet('1', 'one')],
            'returned an array keyed by other than its positions',
        ];
        yield 'no record for the one id' => [[], 'returned 0 entries for a request of 1'];
        yield 'two records for the one id' => [
            [new Pet('1', 'one'), new Pet('2', 'two')],
            'returned 2 entries for a request of 1',
        ];
        yield 'a value that is no record' => [['one'], 'returned string at the place of id "1"'];
    }

    public function testARequestOfMoreThanOneIdIsRefused(): void
    {
        $loader = self::loader([new Pet(self::ID, 'one'), new Pet('2', 'two')]);

        try {
            self::executor(loader: $loader)->loadTarget(
                new LoadRequest(self::TYPE, [self::ID, '2'], self::everyField(), self::languages(), new Values([])),
                OperationKind::FetchResource,
                null,
                self::context(),
            );
            self::fail('the executor loaded a target from a request of two ids');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('names 2 ids', $refusal->getMessage());
            self::assertSame([], $loader->asked);
        }
    }

    public function testTheTargetOfAnUndeclaredTypeIsRefused(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "jobs" is not declared');

        self::executor()->loadTarget(
            new LoadRequest('jobs', [self::ID], self::everyField(), self::languages(), new Values([])),
            OperationKind::FetchResource,
            null,
            self::context(),
        );
    }

    public function testATargetLoadedForAKindThatActsOnNoExistingResourceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::executor(loader: self::loader([new Pet(self::ID, 'one')]))
            ->loadTarget(self::loadRequest(), OperationKind::FetchCollection, null, self::context());
    }

    #[DataProvider('everyKindOfOperation')]
    public function testTheProcessorOfTheKindRunsWithTheOperationAndTheContext(Operation $operation): void
    {
        $result = new NoContentResult();
        $processor = self::processor(static function (
            Operation $given,
            ExecutionContext $context,
        ) use ($result): Result {
            return $result;
        });
        $context = self::context();

        $returned = self::executor(processors: self::processors($operation->kind(), $processor))
            ->execute($operation, $context);

        self::assertSame($result, $returned);
        self::assertCount(1, $processor->seen);
        self::assertSame($operation, $processor->seen[0][0]);
        self::assertSame($context, $processor->seen[0][1]);
    }

    /**
     * @return iterable<string, array{Operation}>
     */
    public static function everyKindOfOperation(): iterable
    {
        yield 'fetch_collection' => [self::fetchCollection()];
        yield 'create_resource' => [self::create()];

        yield from self::operationsOnAnExistingResource();
    }

    public function testAnErrorTheProcessorRaisesPassesThrough(): void
    {
        $raised = new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);

        try {
            self::raise(self::fetchResource(), $raised);
            self::fail('the executor swallowed the error the processor raised');
        } catch (JsonApiException $exception) {
            self::assertSame($raised, $exception);
        }
    }

    public function testAThrowableThatIsNoFailureOfAStorePassesThrough(): void
    {
        $raised = new RuntimeException('the project broke');

        $this->expectExceptionObject($raised);

        self::raise(self::fetchResource(), $raised);
    }

    public function testAResourceThatIsGoneWhenTheWriteRunsIsTheGeneric404(): void
    {
        $failure = new ResourceNotFoundException();
        $exception = self::translated(self::update(), $failure);

        self::assertInstanceOf(NotFoundException::class, $exception);
        self::assertSame(404, $exception->status());
        self::assertSame($failure, $exception->getPrevious());
        self::assertCount(1, $exception->descriptions());
        self::assertSame(ErrorCode::NotFound, $exception->descriptions()[0]->code);
        self::assertNull($exception->descriptions()[0]->pointer);
        self::assertNull($exception->descriptions()[0]->parameter);
        self::assertNull($exception->descriptions()[0]->header);
    }

    /**
     * @param list<string> $pointers
     */
    #[DataProvider('missingRelatedResources')]
    public function testAMissingRelatedResourceIsPointedAtWhereTheRequestNamesIt(
        Operation $operation,
        RelatedResourceNotFoundException $failure,
        array $pointers,
    ): void {
        $exception = self::translated($operation, $failure);

        self::assertInstanceOf(NotFoundException::class, $exception);
        self::assertSame(404, $exception->status());
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame($pointers, array_map(
            static function (ErrorDescription $description): ?string {
                return $description->pointer;
            },
            $exception->descriptions(),
        ));
    }

    /**
     * @return iterable<string, array{Operation, RelatedResourceNotFoundException, list<string>}>
     */
    public static function missingRelatedResources(): iterable
    {
        $owner = new Identifier('customers', '9');
        $first = new Identifier('orders', '1');
        $second = new Identifier('orders', '2');

        yield 'the to-one linkage of a create' => [
            self::create(relationships: ['owner' => Linkage::toOne($owner)]),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            ['/data/relationships/owner/data'],
        ];
        yield 'the to-many linkage of a create' => [
            self::create(relationships: ['orders' => Linkage::toMany([$first, $second])]),
            new RelatedResourceNotFoundException(['orders' => [$second]]),
            ['/data/relationships/orders/data/1'],
        ];
        yield 'the to-one linkage of an update' => [
            self::update(relationships: ['owner' => Linkage::toOne($owner)]),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            ['/data/relationships/owner/data'],
        ];
        yield 'the to-many linkage of an update' => [
            self::update(relationships: ['orders' => Linkage::toMany([$first, $second])]),
            new RelatedResourceNotFoundException(['orders' => [$first, $second]]),
            ['/data/relationships/orders/data/0', '/data/relationships/orders/data/1'],
        ];
        yield 'the to-one linkage of a replacement' => [
            self::replace(relationship: 'owner', linkage: Linkage::toOne($owner)),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            ['/data'],
        ];
        yield 'the to-many linkage of a replacement' => [
            self::replace(linkage: Linkage::toMany([$first, $second])),
            new RelatedResourceNotFoundException(['orders' => [$second]]),
            ['/data/1'],
        ];
        yield 'the to-many linkage of an addition' => [
            self::add(linkage: Linkage::toMany([$first, $second])),
            new RelatedResourceNotFoundException(['orders' => [$first]]),
            ['/data/0'],
        ];
        yield 'the to-many linkage of a removal, which declares the exception for no port' => [
            self::remove(linkage: Linkage::toMany([$first, $second])),
            new RelatedResourceNotFoundException(['orders' => [$second]]),
            ['/data/1'],
        ];
        yield 'a relationship whose name is digits alone, which PHP holds under an int key' => [
            self::create(relationships: ['2' => Linkage::toMany([$first])]),
            new RelatedResourceNotFoundException(['2' => [$first]]),
            ['/data/relationships/2/data/0'],
        ];
    }

    public function testAnIdentifierTheRequestNamesTwiceIsPointedAtTwice(): void
    {
        $twice = new Identifier('orders', '1');
        $exception = self::translated(
            self::add(linkage: Linkage::toMany([$twice, new Identifier('orders', '2'), $twice])),
            new RelatedResourceNotFoundException(['orders' => [$twice]]),
        );

        self::assertSame(['/data/0', '/data/2'], array_map(
            static function (ErrorDescription $description): ?string {
                return $description->pointer;
            },
            $exception->descriptions(),
        ));
    }

    public function testEveryMissingRelatedResourceNamesTheRelationshipTheTypeAndTheId(): void
    {
        $exception = self::translated(
            self::create(relationships: ['owner' => Linkage::toOne(new Identifier('customers', '9'))]),
            new RelatedResourceNotFoundException(['owner' => [new Identifier('customers', '9')]]),
        );

        self::assertCount(1, $exception->descriptions());
        self::assertSame(ErrorCode::RelatedResourceNotFound, $exception->descriptions()[0]->code);
        self::assertSame(
            ['relationship' => 'owner', 'type' => 'customers', 'id' => '9'],
            $exception->descriptions()[0]->parameters,
        );
    }

    public function testTheErrorsStandInTheOrderTheStoreNamedTheMissingIdentifiers(): void
    {
        $first = new Identifier('orders', '1');
        $second = new Identifier('orders', '2');
        $owner = new Identifier('customers', '9');

        $exception = self::translated(
            self::create(relationships: [
                'orders' => Linkage::toMany([$first, $second]),
                'owner' => Linkage::toOne($owner),
            ]),
            new RelatedResourceNotFoundException([
                'orders' => [$second, $first],
                'owner' => [$owner],
            ]),
        );

        self::assertSame(
            [
                '/data/relationships/orders/data/1',
                '/data/relationships/orders/data/0',
                '/data/relationships/owner/data',
            ],
            array_map(
                static function (ErrorDescription $description): ?string {
                    return $description->pointer;
                },
                $exception->descriptions(),
            ),
        );
    }

    #[DataProvider('missingResourcesTheOperationCannotCarry')]
    public function testAMissingRelatedResourceTheOperationCannotCarryIs500(
        Operation $operation,
        RelatedResourceNotFoundException $failure,
        string $message,
    ): void {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::raise($operation, $failure);
    }

    /**
     * @return iterable<string, array{Operation, RelatedResourceNotFoundException, string}>
     */
    public static function missingResourcesTheOperationCannotCarry(): iterable
    {
        $owner = new Identifier('customers', '9');
        $order = new Identifier('orders', '1');

        yield 'a relationship the document of a create does not carry' => [
            self::create(),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            'which the document of the create_resource operation does not carry',
        ];
        yield 'a relationship the document of an update does not carry' => [
            self::update(),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            'which the document of the update_resource operation does not carry',
        ];
        yield 'another relationship than the one a relationship write writes' => [
            self::replace(linkage: Linkage::toMany([$order])),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            'the replace_relationship operation writes relationship "orders"',
        ];
        yield 'an identifier the to-many linkage does not name' => [
            self::add(linkage: Linkage::toMany([$order])),
            new RelatedResourceNotFoundException(['orders' => [new Identifier('orders', '7')]]),
            'the orders resource "7" missing from relationship "orders"',
        ];
        yield 'an identifier the empty to-one linkage does not name' => [
            self::replace(relationship: 'owner', linkage: Linkage::toOne(null)),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            'the customers resource "9" missing from relationship "owner"',
        ];
        yield 'an operation that references no related resource at all' => [
            self::delete(),
            new RelatedResourceNotFoundException(['owner' => [$owner]]),
            'missing from a delete_resource operation',
        ];
    }

    public function testAClientGeneratedIdThatAlreadyExistsIs409(): void
    {
        $failure = new ResourceExistsException();
        $exception = self::translated(self::create(id: '7'), $failure);

        self::assertInstanceOf(ConflictException::class, $exception);
        self::assertSame(409, $exception->status());
        self::assertSame($failure, $exception->getPrevious());
        self::assertCount(1, $exception->descriptions());
        self::assertSame(ErrorCode::ResourceAlreadyExists, $exception->descriptions()[0]->code);
        self::assertSame('/data/id', $exception->descriptions()[0]->pointer);
        self::assertSame(['type' => self::TYPE, 'id' => '7'], $exception->descriptions()[0]->parameters);
    }

    #[DataProvider('createsThatCarryNoClientId')]
    public function testAResourceThatAlreadyExistsOutsideACreateWithAnIdIs500(
        Operation $operation,
        string $message,
    ): void {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::raise($operation, new ResourceExistsException());
    }

    /**
     * @return iterable<string, array{Operation, string}>
     */
    public static function createsThatCarryNoClientId(): iterable
    {
        yield 'a create whose id the server assigns' => [
            self::create(),
            'the request sends none, so the pointer /data/id names no value of its document',
        ];
        yield 'an update, which names the resource by its route' => [
            self::update(),
            'the client-generated id of a update_resource operation taken',
        ];
        yield 'a fetch, which creates nothing' => [
            self::fetchResource(),
            'the client-generated id of a fetch_resource operation taken',
        ];
    }

    /**
     * @param list<string>  $fields
     * @param list<?string> $pointers
     */
    #[DataProvider('violatedConstraints')]
    public function testAViolatedConstraintIs409AtTheSourceOfTheWrite(
        Operation $operation,
        array $fields,
        array $pointers,
    ): void {
        $failure = new ConstraintViolationException('one_name_per_owner', $fields);
        $exception = self::translated($operation, $failure);

        self::assertInstanceOf(ConflictException::class, $exception);
        self::assertSame(409, $exception->status());
        self::assertSame($failure, $exception->getPrevious());
        self::assertSame($pointers, array_map(
            static function (ErrorDescription $description): ?string {
                return $description->pointer;
            },
            $exception->descriptions(),
        ));
    }

    /**
     * @return iterable<string, array{Operation, list<string>, list<?string>}>
     */
    public static function violatedConstraints(): iterable
    {
        $carried = [
            'attributes' => ['name' => 'one'],
            'relationships' => ['owner' => Linkage::toOne(new Identifier('customers', '9'))],
        ];

        yield 'an attribute of a create the request carries' => [
            self::create(attributes: $carried['attributes']),
            ['name'],
            ['/data/attributes/name'],
        ];
        yield 'a relationship of a create the request carries' => [
            self::create(relationships: $carried['relationships']),
            ['owner'],
            ['/data/relationships/owner'],
        ];
        yield 'every named field of an update the request carries, in the order the store named them' => [
            self::update(attributes: $carried['attributes'], relationships: $carried['relationships']),
            ['owner', 'name'],
            ['/data/relationships/owner', '/data/attributes/name'],
        ];
        yield 'a field the request does not carry, which is skipped' => [
            self::update(attributes: $carried['attributes']),
            ['name', 'slug'],
            ['/data/attributes/name'],
        ];
        yield 'named fields the request carries none of' => [
            self::update(attributes: $carried['attributes']),
            ['slug'],
            ['/data'],
        ];
        yield 'a constraint about no field in particular' => [self::update(), [], ['/data']];
        yield 'a replacement, whose document is the linkage' => [self::replace(), ['orders'], ['/data']];
        yield 'an addition, whose document is the linkage' => [self::add(), ['orders'], ['/data']];
        yield 'a removal, whose document is the linkage' => [self::remove(), ['orders'], ['/data']];
        yield 'a deletion, whose request carries no document' => [self::delete(), ['name'], [null]];
    }

    /**
     * `constraint` is a detail parameter, not `source.parameter`, which names a query parameter
     */
    public function testEveryViolatedConstraintNamesTheConstraintAsItsParameter(): void
    {
        $exception = self::translated(
            self::update(attributes: ['name' => 'one']),
            new ConstraintViolationException('one_name_per_owner', ['name']),
        );

        self::assertCount(1, $exception->descriptions());
        self::assertSame(ErrorCode::ConstraintViolation, $exception->descriptions()[0]->code);
        self::assertSame(['constraint' => 'one_name_per_owner'], $exception->descriptions()[0]->parameters);
        self::assertNull($exception->descriptions()[0]->parameter);
        self::assertNull($exception->descriptions()[0]->header);
    }

    public function testAViolatedConstraintOfADeletionNamesNoSource(): void
    {
        $description = self::translated(self::delete(), new ConstraintViolationException('referenced_by_orders'))
            ->descriptions()[0];

        self::assertNull($description->pointer);
        self::assertNull($description->parameter);
        self::assertNull($description->header);
    }

    public function testAViolatedConstraintOfAReadingIs500(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('violated by a fetch_resource operation');

        self::raise(self::fetchResource(), new ConstraintViolationException('one_name_per_owner'));
    }

    public function testAVersionMismatchIsThe412TheEvaluationBeforeTheWriteRespondsWith(): void
    {
        $exception = self::translated(
            self::update(precondition: Precondition::listedVersions(['1'])),
            new VersionMismatchException(),
        );

        self::assertInstanceOf(PreconditionFailedException::class, $exception);
        self::assertSame(412, $exception->status());
        self::assertEquals(
            Preconditions::preconditionFailed(FieldName::IF_MATCH)->descriptions(),
            $exception->descriptions(),
        );
        self::assertSame(ErrorCode::PreconditionFailed, $exception->descriptions()[0]->code);
        self::assertSame(FieldName::IF_MATCH, $exception->descriptions()[0]->header);
    }

    #[DataProvider('operationsWithoutAPrecondition')]
    public function testAVersionMismatchWithoutAPreconditionIs500(Operation $operation, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        self::raise($operation, new VersionMismatchException());
    }

    /**
     * @return iterable<string, array{Operation, string}>
     */
    public static function operationsWithoutAPrecondition(): iterable
    {
        yield 'a conditional write the request sent no If-Match for' => [
            self::update(),
            'the write carries none, so it has no condition that could fail',
        ];
        yield 'a relationship write the request sent no If-Match for' => [
            self::replace(),
            'the write carries none, so it has no condition that could fail',
        ];
        yield 'a write under If-Match: *, which admits every version' => [
            self::update(precondition: Precondition::anyVersion()),
            'the write carries If-Match: *, which admits every stored version',
        ];
        yield 'a create, which changes no existing resource' => [
            self::create(),
            'the precondition of a create_resource operation refused',
        ];
        yield 'a fetch, which writes nothing' => [
            self::fetchResource(),
            'the precondition of a fetch_resource operation refused',
        ];
    }

    private static function translated(Operation $operation, Throwable $failure): JsonApiException
    {
        try {
            self::raise($operation, $failure);
        } catch (JsonApiException $exception) {
            return $exception;
        }

        self::fail('the executor raised no error for the failure of the store');
    }

    private static function raise(Operation $operation, Throwable $failure): void
    {
        $processor = self::processor(
            static function (Operation $given, ExecutionContext $context) use ($failure): Result {
                throw $failure;
            },
        );

        self::executor(processors: self::processors($operation->kind(), $processor))
            ->execute($operation, self::context());
    }

    /**
     * @param array<value-of<OperationKind>, OperationProcessor> $processors
     * @param ?ResourceLoader<object>                            $loader
     */
    private static function executor(
        array $processors = [],
        ?ResourceLoader $loader = null,
        ?OperationPermission $permission = null,
        ?DenialPolicy $denials = null,
    ): Executor {
        $resolver = self::resolver($loader, $permission);

        return new Executor(
            self::registry(),
            new StoreGateway(self::registry(), $resolver),
            self::gate($resolver, $denials),
            $processors === [] ? self::unusedProcessors() : $processors,
        );
    }

    /**
     * @return array<value-of<OperationKind>, OperationProcessor>
     */
    private static function processors(OperationKind $kind, OperationProcessor $processor): array
    {
        $bound = self::unusedProcessors();
        $bound[$kind->value] = $processor;

        return $bound;
    }

    /**
     * a processor for every kind lets the executor build, and each one fails the test when it runs,
     * which reports a dispatch to the wrong kind
     *
     * @return array<value-of<OperationKind>, OperationProcessor>
     */
    private static function unusedProcessors(): array
    {
        $bound = [];

        foreach (OperationKind::cases() as $kind) {
            $bound[$kind->value] = self::processor(
                static function (Operation $operation, ExecutionContext $context) use ($kind): Result {
                    self::fail(sprintf(
                        'the processor bound under %s ran for a %s operation',
                        $kind->value,
                        $operation->kind()->value,
                    ));
                },
            );
        }

        return $bound;
    }

    /**
     * @param Closure(Operation, ExecutionContext): Result $result
     *
     * @return OperationProcessor&object{seen: list<array{Operation, ExecutionContext}>}
     */
    private static function processor(Closure $result): OperationProcessor
    {
        return new class ($result) implements OperationProcessor {
            /**
             * @var list<array{Operation, ExecutionContext}>
             */
            public array $seen = [];

            /**
             * @param Closure(Operation, ExecutionContext): Result $result
             */
            public function __construct(private readonly Closure $result) {}

            #[Override]
            public function process(Operation $operation, ExecutionContext $context): Result
            {
                $this->seen[] = [$operation, $context];

                return ($this->result)($operation, $context);
            }
        };
    }

    /**
     * @param list<object|null> $records
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
             * @param list<object|null> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @return list<object|null>
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
     * @param ?ResourceLoader<object> $loader
     */
    private static function resolver(
        ?ResourceLoader $loader = null,
        ?OperationPermission $permission = null,
    ): ImplementationResolver {
        return new class (
            $loader ?? self::loader([new Pet(self::ID, 'one')]),
            $permission ?? new ClosurePermission(static function (OperationAttempt $attempt): bool {
                return true;
            }),
        ) implements ImplementationResolver {
            /**
             * @param ResourceLoader<object> $loader
             */
            public function __construct(
                private readonly ResourceLoader $loader,
                private readonly OperationPermission $permission,
            ) {}

            /**
             * @template T of object
             *
             * @param class-string<T> $port
             *
             * @return T
             */
            #[Override]
            public function resolve(ImplementationReference $reference, string $port): object
            {
                $implementation = $this->loader instanceof $port ? $this->loader : $this->permission;

                if (!$implementation instanceof $port) {
                    throw new UnexpectedValueException('no implementation of this test is bound as the port');
                }

                return $implementation;
            }
        };
    }

    /**
     * without `$denials` the identity is authenticated, so a denial is 403 and not 401
     */
    private static function gate(?ImplementationResolver $resolver = null, ?DenialPolicy $denials = null): Gate
    {
        return new Gate(
            self::registry(),
            $resolver ?? self::resolver(),
            $denials ?? new DenialPolicy(self::identity(), new NoChallenges()),
        );
    }

    private static function identity(): Identity
    {
        return new class implements Identity {
            #[Override]
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    /**
     * `orders` binds a collection reader, as the related type of a to-many relationship must;
     * `pets` binds every port, so it enables every kind
     */
    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                self::TYPE,
                [Samples::attribute('name')],
                [
                    Samples::toOne('owner', 'customers'),
                    Samples::toMany('orders', 'orders', updates: [
                        RelationshipUpdate::Replace,
                        RelationshipUpdate::Add,
                        RelationshipUpdate::Remove,
                    ]),
                ],
                [
                    Capability::CollectionReader,
                    Capability::RelatedLoader,
                    Capability::ResourceCreator,
                    Capability::ResourceUpdater,
                    Capability::ResourceDeleter,
                    Capability::RelationshipReplacer,
                    Capability::RelationshipAdder,
                    Capability::RelationshipRemover,
                ],
            ),
            Samples::resource('orders', capabilities: [Capability::CollectionReader]),
            Samples::resource('customers'),
        ]);
    }

    private static function loadRequest(): LoadRequest
    {
        return new LoadRequest(self::TYPE, [self::ID], self::everyField(), self::languages(), new Values([]));
    }

    private static function everyField(): FieldHint
    {
        return new FieldHint(null);
    }

    /**
     * @param list<SecurityScheme> $schemes
     */
    private static function context(array $schemes = []): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('GET', new Headers([]), [], [], ''),
            MediaType::jsonApi(),
            null,
            false,
            false,
            $schemes,
        );
    }

    private static function languages(): LanguagePreferences
    {
        return new LanguagePreferences([]);
    }

    private static function fetchResource(
        string $type = self::TYPE,
        string $id = self::ID,
        ?SparseFieldsets $fields = null,
    ): FetchResource {
        return new FetchResource(
            new Identifier($type, $id),
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function fetchCollection(): FetchCollection
    {
        return new FetchCollection(
            self::TYPE,
            null,
            new Sort([]),
            PageRequest::pageNumber(),
            null,
            new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function fetchRelated(?SparseFieldsets $fields = null): FetchRelated
    {
        return new FetchRelated(
            new Identifier(self::TYPE, self::ID),
            'orders',
            null,
            new Sort([]),
            PageRequest::pageNumber(),
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function fetchRelationship(?SparseFieldsets $fields = null): FetchRelationship
    {
        return new FetchRelationship(
            new Identifier(self::TYPE, self::ID),
            'orders',
            null,
            PageRequest::pageNumber(),
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    /**
     * @param array<array-key, mixed>   $attributes
     * @param array<array-key, Linkage> $relationships
     */
    private static function create(
        ?string $id = null,
        array $attributes = [],
        array $relationships = [],
    ): CreateResource {
        return new CreateResource(
            self::TYPE,
            $id,
            new Values($attributes),
            new Values($relationships),
            null,
            new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    /**
     * @param array<array-key, mixed>   $attributes
     * @param array<array-key, Linkage> $relationships
     */
    private static function update(
        array $attributes = [],
        array $relationships = [],
        ?Precondition $precondition = null,
        ?SparseFieldsets $fields = null,
    ): UpdateResource {
        return new UpdateResource(
            new Identifier(self::TYPE, self::ID),
            new Values($attributes),
            new Values($relationships),
            $precondition,
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function delete(?Precondition $precondition = null): DeleteResource
    {
        return new DeleteResource(
            new Identifier(self::TYPE, self::ID),
            $precondition,
            self::languages(),
            new Values([]),
        );
    }

    private static function replace(
        string $relationship = 'orders',
        ?Linkage $linkage = null,
        ?SparseFieldsets $fields = null,
    ): ReplaceRelationship {
        return new ReplaceRelationship(
            new Identifier(self::TYPE, self::ID),
            $relationship,
            $linkage ?? Linkage::toMany([]),
            null,
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function add(?Linkage $linkage = null, ?SparseFieldsets $fields = null): AddToRelationship
    {
        return new AddToRelationship(
            new Identifier(self::TYPE, self::ID),
            'orders',
            $linkage ?? Linkage::toMany([]),
            null,
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }

    private static function remove(
        ?Linkage $linkage = null,
        ?SparseFieldsets $fields = null,
    ): RemoveFromRelationship {
        return new RemoveFromRelationship(
            new Identifier(self::TYPE, self::ID),
            'orders',
            $linkage ?? Linkage::toMany([]),
            null,
            null,
            $fields ?? new SparseFieldsets([]),
            self::languages(),
            new Values([]),
        );
    }
}
