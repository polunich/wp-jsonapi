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

namespace Polunich\WpJsonApi\Tests\Unit\Access;

use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Tests\Fixtures\Access\ClosurePermission;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedIdentity;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\NoChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;
use UnexpectedValueException;

/**
 * the registry of the tests:
 *
 * - `pets` enables `fetch_resource`, `fetch_related`, `fetch_relationship`
 *   and the three relationship writes, each accepted on one relationship:
 *   the to-many `orders` accepts replace, `tags` add, `caretakers` remove;
 *   the to-one `owner`, `vet` and `groomer` accept none,
 *   `vet` excludes its related route and `groomer` its relationship route;
 * - `orders`, `customers` and `tags` enable the two fetches and declare no relationship.
 *
 * every permission is bound to the entry id of its kind, which tells the tests which one resolved
 *
 * @internal
 */
#[CoversClass(Gate::class)]
final class GateTest extends TestCase
{
    public function testThePermissionOfTheKindIsResolvedAsTheAccessPort(): void
    {
        $resolver = self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        }));

        self::gate($resolver)->check(self::attempt(OperationKind::FetchRelated, 'pets', 'orders'), []);

        self::assertCount(1, $resolver->asked);
        self::assertSame('fetch_related', $resolver->asked[0][0]->entryId);
        self::assertSame(OperationPermission::class, $resolver->asked[0][1]);
    }

    /**
     * the permission receives the very attempt the gate was given, not a rebuilt one
     */
    public function testAPermissionThatGrantsLetsTheAttemptThrough(): void
    {
        $seen = [];
        $attempt = self::attempt(OperationKind::FetchResource, 'pets');

        self::gate(self::resolver(new ClosurePermission(static function (OperationAttempt $given) use (&$seen): bool {
            $seen[] = $given;

            return true;
        })))->check($attempt, []);

        self::assertSame([$attempt], $seen);
    }

    public function testTheAttemptOfLevel2ReachesThePermissionWithItsRecord(): void
    {
        $record = new stdClass();
        $seen = [];
        $attempt = OperationAttempt::forRecord(OperationKind::FetchResource, 'pets', null, self::request(), $record);

        self::gate(self::resolver(new ClosurePermission(static function (OperationAttempt $given) use (&$seen): bool {
            $seen[] = $given->record;

            return true;
        })))->check($attempt, []);

        self::assertSame([$record], $seen);
    }

    /**
     * `orders` does not enable `fetch_relationship`, so a check against the related type is refused
     */
    public function testARelationshipKindIsCheckedOnTheDeclarationOfTheParent(): void
    {
        $resolver = self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        }));

        self::gate($resolver)->check(self::attempt(OperationKind::FetchRelationship, 'pets', 'orders'), []);

        self::assertSame('fetch_relationship', $resolver->asked[0][0]->entryId);
    }

    /**
     * @param list<Challenge> $challenges
     */
    #[DataProvider('denials')]
    public function testAPermissionThatDeniesThrowsWhatTheDenialPolicyBuilds(
        bool $authenticated,
        array $challenges,
        int $status,
        ErrorCode $code,
    ): void {
        $gate = new Gate(
            self::registry(),
            self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
                return false;
            })),
            new DenialPolicy(new FixedIdentity($authenticated), new FixedChallenges($challenges)),
        );

        try {
            $gate->check(self::attempt(OperationKind::FetchResource, 'pets'), []);
            self::fail('the gate let a denied attempt through');
        } catch (JsonApiException $denial) {
            self::assertSame($status, $denial->status());
            self::assertSame($code, $denial->descriptions()[0]->code);
            self::assertSame($challenges, $denial instanceof UnauthorizedException ? $denial->challenges() : []);
        }
    }

    /**
     * @return iterable<string, array{bool, list<Challenge>, int, ErrorCode}>
     */
    public static function denials(): iterable
    {
        yield 'an authenticated client' => [true, [], 403, ErrorCode::Forbidden];
        yield 'a client without identity for which a challenge applies' => [
            false,
            [new Challenge('Basic', ['realm' => 'WordPress'])],
            401,
            ErrorCode::Unauthorized,
        ];
        yield 'a client without identity for which none applies' => [false, [], 403, ErrorCode::AuthenticationRequired];
    }

    public function testADenialAsksForTheChallengesOfTheSchemesTheGateIsGiven(): void
    {
        $challenges = new RecordingChallenges();
        $denies = new DenialPolicy(new FixedIdentity(false), $challenges);
        $operations = new Gate(self::registry(), self::resolver(new ClosurePermission(static function (): bool {
            return false;
        })), $denies);
        $endpoints = new Gate(self::registry(), self::endpointResolver(new class implements EndpointPermission {
            #[Override]
            public function allows(EndpointAttempt $attempt): bool
            {
                return false;
            }
        }), $denies);
        $nonce = [SecurityScheme::restNonce()];
        $passwords = [SecurityScheme::applicationPasswords()];

        try {
            $operations->check(self::attempt(OperationKind::FetchResource, 'pets'), $nonce);
        } catch (JsonApiException) {
        }

        try {
            $endpoints->checkEndpoint(
                Samples::endpoint(),
                EndpointAttempt::forEndpoint('adoptPet', self::request()),
                $passwords,
            );
        } catch (JsonApiException) {
        }

        self::assertSame([$nonce, $passwords], $challenges->asked);
    }

    public function testAnEndpointAsksItsPermissionAtBothLevels(): void
    {
        $endpoint = Samples::endpoint();
        $permission = new class implements EndpointPermission {
            /**
             * @var list<?array<string, object>>
             */
            public array $seen = [];

            #[Override]
            public function allows(EndpointAttempt $attempt): bool
            {
                $this->seen[] = $attempt->records;

                return true;
            }
        };
        $resolver = self::endpointResolver($permission);
        $gate = new Gate(self::registry(), $resolver, new DenialPolicy(new FixedIdentity(false), new NoChallenges()));
        $pet = new stdClass();

        $gate->checkEndpoint($endpoint, EndpointAttempt::forEndpoint('adoptPet', self::request()), []);
        $gate->checkEndpoint($endpoint, EndpointAttempt::forRecords('adoptPet', self::request(), ['id' => $pet]), []);

        self::assertSame([null, ['id' => $pet]], $permission->seen);
        self::assertSame(
            [[$endpoint->permission, EndpointPermission::class], [$endpoint->permission, EndpointPermission::class]],
            $resolver->asked,
        );
    }

    /**
     * @param list<Challenge> $challenges
     */
    #[DataProvider('denials')]
    public function testAnEndpointPermissionThatDeniesThrowsWhatTheDenialPolicyBuilds(
        bool $authenticated,
        array $challenges,
        int $status,
        ErrorCode $code,
    ): void {
        $gate = new Gate(
            self::registry(),
            self::endpointResolver(new class implements EndpointPermission {
                #[Override]
                public function allows(EndpointAttempt $attempt): bool
                {
                    return false;
                }
            }),
            new DenialPolicy(new FixedIdentity($authenticated), new FixedChallenges($challenges)),
        );

        try {
            $gate->checkEndpoint(Samples::endpoint(), EndpointAttempt::forEndpoint('adoptPet', self::request()), []);
            self::fail('the gate let a denied endpoint through');
        } catch (JsonApiException $denial) {
            self::assertSame($status, $denial->status());
            self::assertSame($code, $denial->descriptions()[0]->code);
        }
    }

    #[DataProvider('kindsTheTypesDoNotEnable')]
    public function testAKindTheResourceDoesNotEnableGets403(
        string $type,
        OperationKind $kind,
        ?string $relationship,
    ): void {
        $description = self::refusal(self::attempt($kind, $type, $relationship));

        self::assertSame(ErrorCode::OperationNotSupported, $description->code);
        self::assertSame(['type' => $type, 'operation' => $kind->value], $description->parameters);
    }

    /**
     * @return iterable<string, array{string, OperationKind, ?string}>
     */
    public static function kindsTheTypesDoNotEnable(): iterable
    {
        yield 'a collection that is not read' => ['pets', OperationKind::FetchCollection, null];
        yield 'a create the type does not enable' => ['pets', OperationKind::CreateResource, null];
        yield 'an update the type does not enable' => ['pets', OperationKind::UpdateResource, null];
        yield 'a delete the type does not enable' => ['pets', OperationKind::DeleteResource, null];
        yield 'a related reading of a type that declares no relationship' => [
            'orders',
            OperationKind::FetchRelated,
            'customer',
        ];
        yield 'a relationship write of a type that declares no update' => [
            'customers',
            OperationKind::ReplaceRelationship,
            'pets',
        ];
    }

    #[DataProvider('updatesTheRelationshipsDoNotDeclare')]
    public function testARelationshipThatDoesNotDeclareTheUpdateGets403(
        OperationKind $kind,
        string $relationship,
    ): void {
        $description = self::refusal(self::attempt($kind, 'pets', $relationship));

        self::assertSame(ErrorCode::RelationshipUpdateNotSupported, $description->code);
        self::assertSame(
            ['relationship' => $relationship, 'type' => 'pets', 'operation' => $kind->value],
            $description->parameters,
        );
    }

    /**
     * @return iterable<string, array{OperationKind, string}>
     */
    public static function updatesTheRelationshipsDoNotDeclare(): iterable
    {
        yield 'a replace on a relationship that declares none' => [OperationKind::ReplaceRelationship, 'owner'];
        yield 'a replace on a relationship that declares add' => [OperationKind::ReplaceRelationship, 'tags'];
        yield 'an add on a relationship that declares replace' => [OperationKind::AddToRelationship, 'orders'];
        yield 'an add on a relationship that declares remove' => [OperationKind::AddToRelationship, 'caretakers'];
        yield 'a remove on a relationship that declares replace' => [
            OperationKind::RemoveFromRelationship,
            'orders',
        ];
        yield 'a remove on a relationship that declares add' => [OperationKind::RemoveFromRelationship, 'tags'];
    }

    #[DataProvider('updatesTheRelationshipsDeclare')]
    public function testARelationshipThatDeclaresTheUpdateReachesItsPermission(
        OperationKind $kind,
        string $relationship,
    ): void {
        $resolver = self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        }));

        self::gate($resolver)->check(self::attempt($kind, 'pets', $relationship), []);

        self::assertSame($kind->value, $resolver->asked[0][0]->entryId);
    }

    /**
     * @return iterable<string, array{OperationKind, string}>
     */
    public static function updatesTheRelationshipsDeclare(): iterable
    {
        yield 'a replace on the relationship that declares it' => [OperationKind::ReplaceRelationship, 'orders'];
        yield 'an add on the relationship that declares it' => [OperationKind::AddToRelationship, 'tags'];
        yield 'a remove on the relationship that declares it' => [
            OperationKind::RemoveFromRelationship,
            'caretakers',
        ];
    }

    #[DataProvider('readsTheRelationshipsExclude')]
    public function testARelationshipThatExcludesTheReadGets403(OperationKind $kind, string $relationship): void
    {
        $description = self::refusal(self::attempt($kind, 'pets', $relationship));

        self::assertSame(ErrorCode::RelationshipFetchNotSupported, $description->code);
        self::assertSame(
            ['relationship' => $relationship, 'type' => 'pets', 'operation' => $kind->value],
            $description->parameters,
        );
    }

    /**
     * @return iterable<string, array{OperationKind, string}>
     */
    public static function readsTheRelationshipsExclude(): iterable
    {
        yield 'the related route a relationship excludes' => [OperationKind::FetchRelated, 'vet'];
        yield 'the relationship route a relationship excludes' => [OperationKind::FetchRelationship, 'groomer'];
        yield 'a read of an undeclared relationship' => [OperationKind::FetchRelated, 'breeder'];
    }

    /**
     * the permission of a kind decides for every relationship,
     * so one that keeps the route reaches it while another relationship of the type excludes it
     */
    #[DataProvider('readsTheRelationshipsKeep')]
    public function testARelationshipThatKeepsTheReadReachesItsPermission(
        OperationKind $kind,
        string $relationship,
    ): void {
        $resolver = self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        }));

        self::gate($resolver)->check(self::attempt($kind, 'pets', $relationship), []);

        self::assertSame($kind->value, $resolver->asked[0][0]->entryId);
    }

    /**
     * @return iterable<string, array{OperationKind, string}>
     */
    public static function readsTheRelationshipsKeep(): iterable
    {
        yield 'the related route beside an excluded relationship route' => [OperationKind::FetchRelated, 'groomer'];
        yield 'the relationship route beside an excluded related route' => [OperationKind::FetchRelationship, 'vet'];
    }

    public function testTheRefusalOfAReadIsWordedWithTheRelationshipThatExcludesIt(): void
    {
        $description = self::refusal(self::attempt(OperationKind::FetchRelated, 'pets', 'vet'));
        $catalog = new LibraryErrorCatalog();

        self::assertSame(
            'the relationship vet of the resource type pets does not offer the operation fetch_related',
            $catalog->detail(ErrorCode::RelationshipFetchNotSupported->value, $description->parameters),
        );
    }

    public function testARelationshipWriteOnAnUndeclaredRelationshipGets403(): void
    {
        $description = self::refusal(self::attempt(OperationKind::ReplaceRelationship, 'pets', 'breeder'));

        self::assertSame(ErrorCode::RelationshipUpdateNotSupported, $description->code);
        self::assertSame(
            ['relationship' => 'breeder', 'type' => 'pets', 'operation' => 'replace_relationship'],
            $description->parameters,
        );
    }

    public function testNoPermissionIsResolvedForAKindTheResourceDoesNotEnable(): void
    {
        $resolver = self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        }));

        try {
            self::gate($resolver)->check(self::attempt(OperationKind::CreateResource, 'pets'), []);
            self::fail('the gate let a kind through that the resource does not enable');
        } catch (ForbiddenException) {
            self::assertSame([], $resolver->asked);
        }
    }

    /**
     * the catalog returns no detail when a placeholder lacks its parameter,
     * so a detail proves that the description carries every parameter its text names
     */
    public function testTheRefusalOfAKindIsWorded(): void
    {
        $description = self::refusal(self::attempt(OperationKind::CreateResource, 'pets'));
        $catalog = new LibraryErrorCatalog();

        self::assertNotNull($catalog->title(ErrorCode::OperationNotSupported->value));
        self::assertNotNull(
            $catalog->detail(ErrorCode::OperationNotSupported->value, $description->parameters),
        );
    }

    public function testTheRefusalOfAnUpdateIsWordedWithTheRelationshipThatRefusedIt(): void
    {
        $description = self::refusal(self::attempt(OperationKind::AddToRelationship, 'pets', 'orders'));
        $catalog = new LibraryErrorCatalog();

        self::assertSame(
            'relationship update not supported',
            $catalog->title(ErrorCode::RelationshipUpdateNotSupported->value),
        );
        self::assertSame(
            'the relationship orders of the resource type pets does not accept the operation'
            . ' add_to_relationship',
            $catalog->detail(ErrorCode::RelationshipUpdateNotSupported->value, $description->parameters),
        );
    }

    #[DataProvider('refusedAttempts')]
    public function testEveryRefusalIs403AndNamesNoSource(OperationKind $kind, ?string $relationship): void
    {
        $attempt = self::attempt($kind, 'pets', $relationship);

        try {
            self::gate(self::resolver(new ClosurePermission(static function (OperationAttempt $given): bool {
                return true;
            })))->check($attempt, []);
            self::fail('the gate let an attempt through that the declaration refuses');
        } catch (ForbiddenException $refusal) {
            $description = $refusal->descriptions()[0];

            self::assertSame(403, $refusal->status());
            self::assertCount(1, $refusal->descriptions());
            self::assertNull($description->pointer);
            self::assertNull($description->parameter);
            self::assertNull($description->header);
        }
    }

    /**
     * @return iterable<string, array{OperationKind, ?string}>
     */
    public static function refusedAttempts(): iterable
    {
        yield 'a kind the type does not enable' => [OperationKind::DeleteResource, null];
        yield 'an update the relationship does not declare' => [OperationKind::RemoveFromRelationship, 'orders'];
        yield 'a read the relationship excludes' => [OperationKind::FetchRelationship, 'groomer'];
    }

    public function testAnUndeclaredTypeIsNoRefusal(): void
    {
        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('type "jobs" is not declared');

        self::gate(self::resolver(new ClosurePermission(static function (OperationAttempt $attempt): bool {
            return true;
        })))
            ->check(self::attempt(OperationKind::FetchResource, 'jobs'), []);
    }

    /**
     * level 1 runs before the content of a write is parsed,
     * so the gate takes the attempt and the schemes a 401 challenges, and nothing parsed
     */
    public function testTheGateReadsTheAttemptAndTheSchemesAlone(): void
    {
        $method = new ReflectionMethod(Gate::class, 'check');
        [$attempt, $schemes] = $method->getParameters() + [null, null];

        self::assertInstanceOf(ReflectionParameter::class, $attempt);
        self::assertSame('attempt', $attempt->getName());
        self::assertSame(OperationAttempt::class, (string) $attempt->getType());
        self::assertInstanceOf(ReflectionParameter::class, $schemes);
        self::assertSame('schemes', $schemes->getName());
        self::assertSame('array', (string) $schemes->getType());
        self::assertCount(2, $method->getParameters());
        self::assertSame('void', (string) $method->getReturnType());
    }

    public function testTheGateIsInternalAndImmutable(): void
    {
        $gate = new ReflectionClass(Gate::class);

        self::assertStringContainsString('@internal', (string) $gate->getDocComment());
        self::assertTrue($gate->isFinal());
        self::assertTrue($gate->isReadOnly());
    }

    private static function refusal(OperationAttempt $attempt): ErrorDescription
    {
        try {
            self::gate(self::resolver(new ClosurePermission(static function (OperationAttempt $given): bool {
                return true;
            })))->check($attempt, []);
        } catch (ForbiddenException $refusal) {
            self::assertCount(1, $refusal->descriptions());

            return $refusal->descriptions()[0];
        }

        self::fail('the gate let the attempt through');
    }

    private static function gate(ImplementationResolver $resolver): Gate
    {
        return new Gate(self::registry(), $resolver, new DenialPolicy(new FixedIdentity(false), new NoChallenges()));
    }

    private static function registry(): ResourceRegistry
    {
        $orders = self::resource(
            'orders',
            [OperationKind::FetchResource, OperationKind::FetchCollection],
            [Capability::CollectionReader],
        );
        $customers = self::resource(
            'customers',
            [OperationKind::FetchResource, OperationKind::FetchCollection],
            [Capability::CollectionReader],
        );
        $tags = self::resource(
            'tags',
            [OperationKind::FetchResource, OperationKind::FetchCollection],
            [Capability::CollectionReader],
        );
        $pets = self::resource(
            'pets',
            [
                OperationKind::FetchResource,
                OperationKind::FetchRelated,
                OperationKind::FetchRelationship,
                OperationKind::ReplaceRelationship,
                OperationKind::AddToRelationship,
                OperationKind::RemoveFromRelationship,
            ],
            [
                Capability::RelatedLoader,
                Capability::RelationshipReplacer,
                Capability::RelationshipAdder,
                Capability::RelationshipRemover,
            ],
            [
                Samples::toMany('orders', 'orders', updates: [RelationshipUpdate::Replace]),
                Samples::toMany('tags', 'tags', updates: [RelationshipUpdate::Add]),
                Samples::toMany('caretakers', 'customers', updates: [RelationshipUpdate::Remove]),
                Samples::toOne('owner', 'customers'),
                Samples::toOne(
                    'vet',
                    'customers',
                    excluded: RouteExclusion::except(
                        RouteOwner::Relationship,
                        [OperationKind::FetchRelated],
                        'relationship "vet"',
                    ),
                ),
                Samples::toOne(
                    'groomer',
                    'customers',
                    excluded: RouteExclusion::except(
                        RouteOwner::Relationship,
                        [OperationKind::FetchRelationship],
                        'relationship "groomer"',
                    ),
                ),
            ],
        );

        return new ResourceRegistry([$pets, $orders, $customers, $tags]);
    }

    /**
     * the kinds are listed, not derived from `enables()`, so `ResourceMetadata` refuses a fixture
     * whose kinds do not match its capabilities
     *
     * @param list<OperationKind>        $kinds
     * @param list<Capability>           $capabilities bound beside the resource loader
     * @param list<RelationshipMetadata> $relationships
     */
    private static function resource(
        string $type,
        array $kinds,
        array $capabilities = [],
        array $relationships = [],
    ): ResourceMetadata {
        $bound = [Capability::ResourceLoader->value => Samples::implementation()];

        foreach ($capabilities as $capability) {
            $bound[$capability->value] = Samples::implementation();
        }

        $byName = [];

        foreach ($relationships as $relationship) {
            $byName[$relationship->name] = $relationship;
        }

        $permissions = [];

        foreach ($kinds as $kind) {
            $permissions[$kind->value] = ImplementationReference::service($kind->value);
        }

        return new ResourceMetadata(
            $type,
            Samples::id(),
            'id',
            [],
            $byName,
            $bound,
            $permissions,
            [],
            RouteExclusion::none(RouteOwner::Resource),
            ClientIdPolicy::Forbidden,
            null,
            null,
            PaginationPolicy::libraryDefault(),
            1,
            null,
        );
    }

    /**
     * @return ImplementationResolver&object{asked: list<array{ImplementationReference, string}>}
     */
    private static function resolver(OperationPermission $permission): ImplementationResolver
    {
        return new class ($permission) implements ImplementationResolver {
            /**
             * @var list<array{ImplementationReference, string}>
             */
            public array $asked = [];

            public function __construct(private readonly OperationPermission $permission) {}

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
                $this->asked[] = [$reference, $port];

                if (!$this->permission instanceof $port) {
                    throw new UnexpectedValueException('the implementation is no instance of the port');
                }

                return $this->permission;
            }
        };
    }

    /**
     * @return ImplementationResolver&object{asked: list<array{ImplementationReference, string}>}
     */
    private static function endpointResolver(EndpointPermission $permission): ImplementationResolver
    {
        return new class ($permission) implements ImplementationResolver {
            /**
             * @var list<array{ImplementationReference, string}>
             */
            public array $asked = [];

            public function __construct(private readonly EndpointPermission $permission) {}

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
                $this->asked[] = [$reference, $port];

                if (!$this->permission instanceof $port) {
                    throw new UnexpectedValueException('the implementation is no instance of the port');
                }

                return $this->permission;
            }
        };
    }

    private static function attempt(OperationKind $kind, string $type, ?string $relationship = null): OperationAttempt
    {
        return OperationAttempt::forKind($kind, $type, $relationship, self::request());
    }

    private static function request(): ServerRequest
    {
        return new ServerRequest('GET', new Headers(), [], [], '');
    }
}
