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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Atomic;

use ArrayObject;
use Closure;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionRequiredException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Execution\Atomic\AtomicExecutor;
use Polunich\WpJsonApi\Execution\Atomic\OperationFailedException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\OperationProcessor;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedIdentity;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\NoChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use RuntimeException;
use Throwable;

/**
 * every double writes into one journal, so a test asserts the order of the whole run:
 * `level1:<kind>` and `level2:<kind>` for the permission,
 * `begin`, `commit` and `rollback` for the transaction,
 * `build:<kind>` for the deferred build of an operation,
 * `load:<ids>` for the resource loader and `process:<kind>` for the processor
 *
 * @internal
 */
#[CoversClass(AtomicExecutor::class)]
final class AtomicExecutorTest extends TestCase
{
    private const TYPE = 'pets';

    private const RELATIONSHIP = 'related';

    public function testLevel1IsCheckedForEveryOperationBeforeTheTransactionBegins(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [],
            permits: static function (OperationAttempt $attempt): bool {
                return $attempt->kind !== OperationKind::DeleteResource;
            },
        );

        try {
            $executor->execute(
                new AtomicOperations([self::create($log), self::delete($log, '1')]),
                self::context(),
            );
            self::fail('the executor ran a request the gate refused');
        } catch (OperationFailedException $failure) {
            self::assertSame(1, $failure->position);
            self::assertInstanceOf(ForbiddenException::class, $failure->failure);
        }

        self::assertSame(['level1:create_resource', 'level1:delete_resource'], $log->getArrayCopy());
    }

    /**
     * @param Closure(OperationAttempt): bool $permits refuses the delete at one level
     */
    #[DataProvider('denialsOfTheSecondOperation')]
    public function testADenialAsksForTheChallengesOfTheSchemesOfTheRequest(Closure $permits): void
    {
        $log = self::log();
        $challenges = new RecordingChallenges([new Challenge('Basic', ['realm' => 'WordPress'])]);
        $schemes = [SecurityScheme::restNonce()];
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function (): Result {
                return self::resource('10');
            }],
            ['1' => new Pet('1', 'a name')],
            $permits,
            authenticated: false,
            challenges: $challenges,
        );

        try {
            $executor->execute(
                new AtomicOperations([self::create($log), self::delete($log, '1')]),
                self::context($schemes),
            );
            self::fail('the executor ran a request the gate refused');
        } catch (OperationFailedException $failure) {
            self::assertSame(1, $failure->position);
            self::assertInstanceOf(UnauthorizedException::class, $failure->failure);
        }

        self::assertSame([$schemes], $challenges->asked);
    }

    /**
     * @return iterable<string, array{Closure(OperationAttempt): bool}>
     */
    public static function denialsOfTheSecondOperation(): iterable
    {
        yield 'a denial of the kind, before the transaction' => [
            static function (OperationAttempt $attempt): bool {
                return $attempt->kind !== OperationKind::DeleteResource;
            },
        ];
        yield 'a denial of the loaded record' => [
            static function (OperationAttempt $attempt): bool {
                return $attempt->kind !== OperationKind::DeleteResource || $attempt->record === null;
            },
        ];
    }

    public function testAnOperationIsBuiltLoadedAskedAtLevel2AndThenProcessed(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::UpdateResource->value => static function (): Result {
                return self::resource('1');
            }],
            ['1' => new Pet('1', 'a name')],
        );

        $executor->execute(new AtomicOperations([self::update($log, '1')]), self::context());

        self::assertSame([
            'level1:update_resource',
            'begin',
            'build:update_resource',
            'load:1',
            'level2:update_resource',
            'process:update_resource',
            'commit',
        ], $log->getArrayCopy());
    }

    public function testTheUnconditionalOperationOfAVersionedTypeIsRefusedBeforeItsProcessor(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::UpdateResource->value => static function (): Result {
                return self::resource('1');
            }],
            ['1' => new Pet('1', 'a name')],
            versions: new VersionPolicy(static function (Pet $pet): string {
                return $pet->version;
            }, true),
        );

        try {
            $executor->execute(new AtomicOperations([self::update($log, '1')]), self::context());
            self::fail('the executor wrote without the precondition the type requires');
        } catch (OperationFailedException $failure) {
            self::assertSame(0, $failure->position);
            self::assertInstanceOf(PreconditionRequiredException::class, $failure->failure);
        }

        self::assertSame([
            'level1:update_resource',
            'begin',
            'build:update_resource',
            'load:1',
            'level2:update_resource',
            'rollback',
        ], $log->getArrayCopy());
    }

    public function testATargetTheLoaderDoesNotReturnFailsTheRequestAtItsPosition(): void
    {
        $log = self::log();
        $executor = self::executor($log, [OperationKind::DeleteResource->value => static function (): Result {
            return self::noContent();
        }]);

        try {
            $executor->execute(new AtomicOperations([self::delete($log, '1')]), self::context());
            self::fail('the executor wrote to a resource the loader does not return');
        } catch (OperationFailedException $failure) {
            self::assertSame(0, $failure->position);
            self::assertInstanceOf(NotFoundException::class, $failure->failure);
        }

        self::assertSame(
            ['level1:delete_resource', 'begin', 'build:delete_resource', 'load:1', 'rollback'],
            $log->getArrayCopy(),
        );
    }

    public function testARecordLevelRefusalRollsBack(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::DeleteResource->value => static function (): Result {
                return self::noContent();
            }],
            ['1' => new Pet('1', 'a name')],
            permits: static function (OperationAttempt $attempt): bool {
                return $attempt->record === null;
            },
        );

        try {
            $executor->execute(new AtomicOperations([self::delete($log, '1')]), self::context());
            self::fail('the executor wrote to a record the permission refuses');
        } catch (OperationFailedException $failure) {
            self::assertSame(0, $failure->position);
            self::assertInstanceOf(ForbiddenException::class, $failure->failure);
        }

        self::assertSame([
            'level1:delete_resource',
            'begin',
            'build:delete_resource',
            'load:1',
            'level2:delete_resource',
            'rollback',
        ], $log->getArrayCopy());
    }

    public function testAFailedOperationRollsBackAndCarriesItsPosition(): void
    {
        $log = self::log();
        $refusal = new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
        $executor = self::executor(
            $log,
            [
                OperationKind::CreateResource->value => static function (): Result {
                    return self::resource('10');
                },
                OperationKind::DeleteResource->value => static function () use ($refusal): Result {
                    throw $refusal;
                },
            ],
            ['1' => new Pet('1', 'a name')],
        );

        try {
            $executor->execute(
                new AtomicOperations([self::create($log), self::delete($log, '1'), self::delete($log, '1')]),
                self::context(),
            );
            self::fail('the executor responded to a request an operation of which failed');
        } catch (OperationFailedException $failure) {
            self::assertSame(1, $failure->position);
            self::assertSame($refusal, $failure->failure);
        }

        self::assertSame([
            'level1:create_resource',
            'level1:delete_resource',
            'level1:delete_resource',
            'begin',
            'build:create_resource',
            'process:create_resource',
            'build:delete_resource',
            'load:1',
            'level2:delete_resource',
            'process:delete_resource',
            'rollback',
        ], $log->getArrayCopy());
    }

    public function testTheIdOfACreatedResourceIsGivenToTheOperationThatNamesItsLid(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [
                OperationKind::CreateResource->value => static function (): Result {
                    return self::resource('42');
                },
                OperationKind::DeleteResource->value => static function (): Result {
                    return self::noContent();
                },
            ],
            ['42' => new Pet('42', 'a name')],
        );

        $executor->execute(
            new AtomicOperations([self::create($log, 'temp'), self::deleteOfLid($log, 'temp')]),
            self::context(),
        );

        self::assertSame([
            'level1:create_resource',
            'level1:delete_resource',
            'begin',
            'build:create_resource',
            'process:create_resource',
            'build:delete_resource',
            'load:42',
            'level2:delete_resource',
            'process:delete_resource',
            'commit',
        ], $log->getArrayCopy());
    }

    #[DataProvider('resultsThatCarryNoResource')]
    public function testALidNeedsTheResourceItsCreateReturned(Result $result): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function () use ($result): Result {
                return $result;
            }],
        );

        try {
            $executor->execute(new AtomicOperations([self::create($log, 'temp')]), self::context());
            self::fail('the executor recorded a lid for a result that carries no resource');
        } catch (OperationFailedException $failure) {
            self::assertSame(0, $failure->position);
            self::assertInstanceOf(LogicException::class, $failure->failure);
        }

        self::assertContains('rollback', $log->getArrayCopy());
    }

    /**
     * @return iterable<string, array{Result}>
     */
    public static function resultsThatCarryNoResource(): iterable
    {
        yield 'the empty result object' => [self::noContent()];
        yield 'a resource document with no primary data' => [
            new ResourceResult(self::TYPE, null, new RecordGraph(false), new SparseFieldsets([]), 200, null),
        ];
    }

    public function testAWriteTheStoreOnlyAcceptedIsRefusedAndRolledBack(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function (): Result {
                return self::accepted('job-1');
            }],
        );

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor responded to an atomic request with an accepted write');
        } catch (OperationFailedException $failure) {
            self::assertSame(0, $failure->position);
            self::assertInstanceOf(InternalServerErrorException::class, $failure->failure);
            self::assertSame(500, $failure->failure->status());
        }

        self::assertSame(
            ['level1:create_resource', 'begin', 'build:create_resource', 'process:create_resource', 'rollback'],
            $log->getArrayCopy(),
        );
    }

    public function testAResultThatIsNeitherAResourceNorTheEmptyResultObjectIsRefused(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function (): Result {
                return new NotModifiedResult(null);
            }],
        );

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor responded to an atomic request with a result of another shape');
        } catch (OperationFailedException $failure) {
            self::assertInstanceOf(LogicException::class, $failure->failure);
        }

        self::assertContains('rollback', $log->getArrayCopy());
    }

    public function testAThrowableOfBeginLeavesUnwrapped(): void
    {
        $log = self::log();
        $broken = new RuntimeException('the transaction did not begin');
        $executor = self::executor($log, [], failing: ['begin' => $broken]);

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor ran the operations without a transaction');
        } catch (Throwable $thrown) {
            self::assertSame($broken, $thrown);
        }

        self::assertSame(['level1:create_resource', 'begin'], $log->getArrayCopy());
    }

    public function testAThrowableOfCommitLeavesUnwrapped(): void
    {
        $log = self::log();
        $broken = new RuntimeException('the transaction did not commit');
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function (): Result {
                return self::resource('10');
            }],
            failing: ['commit' => $broken],
        );

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor responded to a request whose transaction did not commit');
        } catch (Throwable $thrown) {
            self::assertSame($broken, $thrown);
        }

        self::assertNotContains('rollback', $log->getArrayCopy());
    }

    public function testAThrowableOfRollBackReplacesTheFailureItWasHandling(): void
    {
        $log = self::log();
        $broken = new RuntimeException('the transaction did not roll back');
        $executor = self::executor(
            $log,
            [OperationKind::CreateResource->value => static function (): Result {
                throw new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
            }],
            failing: ['rollback' => $broken],
        );

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor responded to a request whose transaction did not roll back');
        } catch (Throwable $thrown) {
            self::assertSame($broken, $thrown);
        }
    }

    public function testTheResultsArePositionalAndOfTheLengthOfTheOperations(): void
    {
        $log = self::log();
        $executor = self::executor(
            $log,
            [
                OperationKind::CreateResource->value => static function (): Result {
                    return self::resource('10');
                },
                OperationKind::DeleteResource->value => static function (): Result {
                    return self::noContent();
                },
                OperationKind::UpdateResource->value => static function (): Result {
                    return self::resource('1');
                },
            ],
            ['1' => new Pet('1', 'a name')],
        );

        $result = $executor->execute(
            new AtomicOperations([self::create($log), self::delete($log, '1'), self::update($log, '1')]),
            self::context(),
        );

        self::assertCount(3, $result->results);

        $created = $result->results[0];
        $updated = $result->results[2];

        self::assertInstanceOf(ResourceResult::class, $created);
        self::assertSame('10', $created->resource?->id);
        self::assertInstanceOf(NoContentResult::class, $result->results[1]);
        self::assertInstanceOf(ResourceResult::class, $updated);
        self::assertSame('1', $updated->resource?->id);
    }

    /**
     * @param list<OperationKind> $kinds
     */
    #[DataProvider('requestsAndTheirStatus')]
    public function testTheStatusIs204WhenNoOperationReturnsData(array $kinds, int $status): void
    {
        $log = self::log();
        $executor = self::executor($log, self::results(), ['1' => new Pet('1', 'a name')]);
        $operations = [];

        foreach ($kinds as $kind) {
            $operations[] = self::operationOf($log, $kind);
        }

        self::assertSame($status, $executor->execute(new AtomicOperations($operations), self::context())->status());
    }

    /**
     * @return iterable<string, array{list<OperationKind>, int}>
     */
    public static function requestsAndTheirStatus(): iterable
    {
        yield 'a delete and a relationship write' => [
            [OperationKind::DeleteResource, OperationKind::ReplaceRelationship],
            204,
        ];
        yield 'two deletes' => [[OperationKind::DeleteResource, OperationKind::DeleteResource], 204];
        yield 'a create beside a delete' => [
            [OperationKind::CreateResource, OperationKind::DeleteResource],
            200,
        ];
        yield 'a relationship write beside an update' => [
            [OperationKind::ReplaceRelationship, OperationKind::UpdateResource],
            200,
        ];
    }

    public function testEachProcessorRunsAtThePositionOfItsOperationWithItsOwnRecord(): void
    {
        $log = self::log();
        $record = new Pet('1', 'a name');
        $scopes = self::scopes();
        $created = static function (Operation $operation, ExecutionContext $context) use ($scopes): Result {
            $scopes[] = $context;

            return self::resource('10');
        };
        $deleted = static function (Operation $operation, ExecutionContext $context) use ($scopes): Result {
            $scopes[] = $context;

            return self::noContent();
        };
        $executor = self::executor(
            $log,
            [
                OperationKind::CreateResource->value => $created,
                OperationKind::DeleteResource->value => $deleted,
            ],
            ['1' => $record],
        );

        $executor->execute(
            new AtomicOperations([self::create($log), self::delete($log, '1')]),
            self::context(),
        );

        $seen = $scopes->getArrayCopy();

        self::assertCount(2, $seen);
        self::assertSame(0, $seen[0]->operationPosition);
        self::assertNull($seen[0]->record);
        self::assertSame(1, $seen[1]->operationPosition);
        self::assertSame($record, $seen[1]->record);
        self::assertTrue($seen[1]->isAtomic());
    }

    public function testAnApiWithoutATransactionBoundaryIsRefused(): void
    {
        $log = self::log();
        $executor = self::executor($log, [], boundaryDeclared: false);

        try {
            $executor->execute(new AtomicOperations([self::create($log)]), self::context());
            self::fail('the executor ran the operations of an API that declares no transaction');
        } catch (LogicException $refusal) {
            self::assertStringContainsString('transaction', $refusal->getMessage());
        }

        self::assertSame([], $log->getArrayCopy());
    }

    public function testTheExecutorIsInternalAndImmutable(): void
    {
        $executor = new ReflectionClass(AtomicExecutor::class);

        self::assertStringContainsString('@internal', (string) $executor->getDocComment());
        self::assertTrue($executor->isFinal());
        self::assertTrue($executor->isReadOnly());
    }

    /**
     * @return ArrayObject<int, string>
     */
    private static function log(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @return ArrayObject<int, ExecutionContext>
     */
    private static function scopes(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * `$results` holds one closure per kind; the processor of a kind left out throws when it runs.
     * `$records` are keyed by id, and a `null` `$permits` grants both levels.
     * `$failing` holds what the boundary call of that name throws: `begin`, `commit` or `rollback`.
     * `$authenticated` false makes the client anonymous, so a denial asks `$challenges`
     *
     * @param ArrayObject<int, string>                                                     $log
     * @param array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result> $results
     * @param array<array-key, Pet>                                                        $records
     * @param ?Closure(OperationAttempt): bool                                             $permits
     * @param array<string, Throwable>                                                     $failing
     */
    private static function executor(
        ArrayObject $log,
        array $results,
        array $records = [],
        ?Closure $permits = null,
        array $failing = [],
        ?VersionPolicy $versions = null,
        bool $boundaryDeclared = true,
        bool $authenticated = true,
        ?ChallengeProvider $challenges = null,
    ): AtomicExecutor {
        $registry = self::registry($versions);
        $resolver = BoundImplementations::of([
            TransactionBoundary::class => self::transactions($log, $failing),
            OperationPermission::class => self::permission($log, $permits),
            ResourceLoader::class => self::loader($log, $records),
        ]);
        $gate = new Gate($registry, $resolver, new DenialPolicy(
            new FixedIdentity($authenticated),
            $challenges ?? new NoChallenges(),
        ));

        return new AtomicExecutor(
            self::api($registry, $boundaryDeclared),
            $resolver,
            $gate,
            new Preconditions($registry),
            new Executor($registry, new StoreGateway($registry, $resolver), $gate, self::processors($log, $results)),
        );
    }

    private static function api(ResourceRegistry $registry, bool $boundaryDeclared): ApiMetadata
    {
        $boundary = $boundaryDeclared ? Samples::implementation() : null;

        return new ApiMetadata(
            'the test api',
            '1.0',
            'acme/v1',
            $registry,
            [],
            Samples::atomicRoutes($registry, $boundary),
            Samples::operators(),
            [],
            $boundary,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    /**
     * the type enables the fetch kinds as well,
     * and `ResourceMetadata` wants a permission for exactly the kinds a type enables
     */
    private static function registry(?VersionPolicy $versions): ResourceRegistry
    {
        $capabilities = [
            Capability::ResourceLoader,
            Capability::CollectionReader,
            Capability::RelatedLoader,
            Capability::ResourceCreator,
            Capability::ResourceUpdater,
            Capability::ResourceDeleter,
            Capability::RelationshipReplacer,
        ];
        $kinds = [
            OperationKind::FetchCollection,
            OperationKind::FetchResource,
            OperationKind::FetchRelated,
            OperationKind::FetchRelationship,
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
            OperationKind::ReplaceRelationship,
        ];

        $bound = [];

        foreach ($capabilities as $capability) {
            $bound[$capability->value] = Samples::implementation();
        }

        $permissions = [];

        foreach ($kinds as $kind) {
            $permissions[$kind->value] = Samples::implementation();
        }

        $related = Samples::toMany(self::RELATIONSHIP, self::TYPE, updates: [RelationshipUpdate::Replace]);

        return new ResourceRegistry([new ResourceMetadata(
            self::TYPE,
            Samples::id(),
            'id',
            [],
            [self::RELATIONSHIP => $related],
            $bound,
            $permissions,
            [],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            $versions,
            null,
            PaginationPolicy::libraryDefault(),
            1,
            null,
        )]);
    }

    /**
     * @param ArrayObject<int, string> $log
     * @param array<string, Throwable> $failing
     */
    private static function transactions(ArrayObject $log, array $failing): TransactionBoundary
    {
        return new class ($log, $failing) implements TransactionBoundary {
            /**
             * @param ArrayObject<int, string> $log
             * @param array<string, Throwable> $failing
             */
            public function __construct(
                private readonly ArrayObject $log,
                private readonly array $failing,
            ) {}

            /**
             * @throws Throwable
             */
            #[Override]
            public function begin(): void
            {
                $this->call('begin');
            }

            /**
             * @throws Throwable
             */
            #[Override]
            public function commit(): void
            {
                $this->call('commit');
            }

            /**
             * @throws Throwable
             */
            #[Override]
            public function rollBack(): void
            {
                $this->call('rollback');
            }

            /**
             * @throws Throwable
             */
            private function call(string $name): void
            {
                $this->log[] = $name;
                $failure = $this->failing[$name] ?? null;

                if ($failure !== null) {
                    throw $failure;
                }
            }
        };
    }

    /**
     * @param ArrayObject<int, string> $log
     * @param array<array-key, Pet>    $records
     *
     * @return ResourceLoader<object>
     */
    private static function loader(ArrayObject $log, array $records): ResourceLoader
    {
        return new class ($log, $records) implements ResourceLoader {
            /**
             * @param ArrayObject<int, string> $log
             * @param array<array-key, Pet>    $records
             */
            public function __construct(
                private readonly ArrayObject $log,
                private readonly array $records,
            ) {}

            /**
             * @return list<?Pet>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $this->log[] = 'load:' . implode(',', $request->ids);

                return array_map(function (string $id): ?Pet {
                    return $this->records[$id] ?? null;
                }, $request->ids);
            }
        };
    }

    /**
     * @param ArrayObject<int, string>         $log
     * @param ?Closure(OperationAttempt): bool $permits
     */
    private static function permission(ArrayObject $log, ?Closure $permits): OperationPermission
    {
        return new class ($log, $permits ?? static function (OperationAttempt $attempt): bool {
            return true;
        }) implements OperationPermission {
            /**
             * @param ArrayObject<int, string>        $log
             * @param Closure(OperationAttempt): bool $permits
             */
            public function __construct(
                private readonly ArrayObject $log,
                private readonly Closure $permits,
            ) {}

            #[Override]
            public function allows(OperationAttempt $attempt): bool
            {
                $this->log[] = ($attempt->record === null ? 'level1:' : 'level2:') . $attempt->kind->value;

                return ($this->permits)($attempt);
            }
        };
    }

    /**
     * one processor per kind, as `Executor` requires
     *
     * @param ArrayObject<int, string>                                                     $log
     * @param array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result> $results
     *
     * @return array<value-of<OperationKind>, OperationProcessor>
     */
    private static function processors(ArrayObject $log, array $results): array
    {
        $processors = [];

        foreach (OperationKind::cases() as $kind) {
            $processors[$kind->value] = new class (
                $log,
                $kind,
                $results[$kind->value] ?? null,
            ) implements OperationProcessor {
                /**
                 * @param ArrayObject<int, string>                      $log
                 * @param ?Closure(Operation, ExecutionContext): Result $result
                 */
                public function __construct(
                    private readonly ArrayObject $log,
                    private readonly OperationKind $kind,
                    private readonly ?Closure $result,
                ) {}

                /**
                 * @throws LogicException
                 */
                #[Override]
                public function process(Operation $operation, ExecutionContext $context): Result
                {
                    $this->log[] = 'process:' . $this->kind->value;

                    if ($this->result === null) {
                        throw new LogicException(sprintf('no %s operation was expected to run', $this->kind->value));
                    }

                    return ($this->result)($operation, $context);
                }
            };
        }

        return $processors;
    }

    /**
     * @return array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result>
     */
    private static function results(): array
    {
        return [
            OperationKind::CreateResource->value => static function (): Result {
                return self::resource('10');
            },
            OperationKind::UpdateResource->value => static function (): Result {
                return self::resource('1');
            },
            OperationKind::DeleteResource->value => static function (): Result {
                return self::noContent();
            },
            OperationKind::ReplaceRelationship->value => static function (): Result {
                return self::noContent();
            },
        ];
    }

    /**
     * @param ArrayObject<int, string> $log
     *
     * @throws LogicException
     */
    private static function operationOf(ArrayObject $log, OperationKind $kind): AtomicOperation
    {
        return match ($kind) {
            OperationKind::CreateResource => self::create($log),
            OperationKind::UpdateResource => self::update($log, '1'),
            OperationKind::DeleteResource => self::delete($log, '1'),
            OperationKind::ReplaceRelationship => self::replace($log, '1'),
            default => throw new LogicException(sprintf('the tests build no %s operation', $kind->value)),
        };
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function create(ArrayObject $log, ?string $lid = null): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::CreateResource,
            self::TYPE,
            null,
            $lid,
            static function () use ($log): Operation {
                $log[] = 'build:create_resource';

                return new CreateResource(
                    self::TYPE,
                    null,
                    new Values([]),
                    new Values([]),
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function update(ArrayObject $log, string $id): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::UpdateResource,
            self::TYPE,
            null,
            null,
            static function () use ($log, $id): Operation {
                $log[] = 'build:update_resource';

                return new UpdateResource(
                    new Identifier(self::TYPE, $id),
                    new Values([]),
                    new Values([]),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function delete(ArrayObject $log, string $id): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::DeleteResource,
            self::TYPE,
            null,
            null,
            static function () use ($log, $id): Operation {
                $log[] = 'build:delete_resource';

                return new DeleteResource(
                    new Identifier(self::TYPE, $id),
                    null,
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function deleteOfLid(ArrayObject $log, string $lid): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::DeleteResource,
            self::TYPE,
            null,
            null,
            /**
             * @param Closure(string, string): string $idOfLid
             */
            static function (Closure $idOfLid) use ($log, $lid): Operation {
                $log[] = 'build:delete_resource';

                return new DeleteResource(
                    new Identifier(self::TYPE, $idOfLid(self::TYPE, $lid)),
                    null,
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param ArrayObject<int, string> $log
     */
    private static function replace(ArrayObject $log, string $id): AtomicOperation
    {
        return new AtomicOperation(
            OperationKind::ReplaceRelationship,
            self::TYPE,
            self::RELATIONSHIP,
            null,
            static function () use ($log, $id): Operation {
                $log[] = 'build:replace_relationship';

                return new ReplaceRelationship(
                    new Identifier(self::TYPE, $id),
                    self::RELATIONSHIP,
                    Linkage::toMany([]),
                    null,
                    null,
                    new SparseFieldsets([]),
                    new LanguagePreferences([]),
                    new Values([]),
                );
            },
        );
    }

    private static function resource(string $id): ResourceResult
    {
        return new ResourceResult(
            self::TYPE,
            new ResourceRecord(self::TYPE, $id, new Pet($id, 'a name')),
            new RecordGraph(false),
            new SparseFieldsets([]),
            200,
            null,
        );
    }

    private static function noContent(): NoContentResult
    {
        return new NoContentResult();
    }

    private static function accepted(string $id): AcceptedResult
    {
        return new AcceptedResult(
            new ResourceRecord('jobs', $id, new Pet($id, 'a name')),
            new RecordGraph(false),
            new SparseFieldsets([]),
        );
    }

    /**
     * @param list<SecurityScheme> $schemes
     */
    private static function context(array $schemes = []): ExecutionContext
    {
        return new ExecutionContext(
            new ServerRequest('POST', new Headers(), [], [], '{}'),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]),
            null,
            false,
            false,
            $schemes,
        );
    }
}
