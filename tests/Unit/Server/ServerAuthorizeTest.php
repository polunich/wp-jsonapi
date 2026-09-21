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

namespace Polunich\WpJsonApi\Tests\Unit\Server;

use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;
use RuntimeException;

/**
 * `authorize()` on a generated route: the first permission level, before the request is read
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerAuthorizeTest extends JournaledServerTestCase
{
    public function testTheAtomicRouteIsLetThroughWithoutAskingAnyPermission(): void
    {
        $journal = self::journal();

        self::assertNull(self::server($journal)->authorize(self::route(null), self::atomicRequest('{}')));
        self::assertSame([], self::steps($journal));
    }

    public function testLevelOneIsAskedWithTheKindTheTypeAndTheRelationshipOfTheRoute(): void
    {
        $journal = self::journal();
        $request = self::request('PATCH', [self::ID_PARAMETER => self::ID]);

        self::server($journal)->authorize(
            self::route(OperationKind::ReplaceRelationship, self::RELATED),
            $request,
        );

        $attempt = self::attempted($journal, 'level1');

        self::assertSame(OperationKind::ReplaceRelationship, $attempt->kind);
        self::assertSame(self::TYPE, $attempt->type);
        self::assertSame(self::RELATED, $attempt->relationship);
        self::assertSame($request, $attempt->request);
    }

    public function testTheAttemptOfLevelOneCarriesNoRecord(): void
    {
        $journal = self::journal();

        self::server($journal)->authorize(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertNull(self::attempted($journal, 'level1')->record);
    }

    public function testAnAttemptThatPassesGetsNoResponse(): void
    {
        $journal = self::journal();

        self::assertNull(self::server($journal)->authorize(
            self::route(OperationKind::FetchCollection),
            self::request('GET'),
        ));
        self::assertSame(['level1'], self::steps($journal));
    }

    public function testAKindTheDeclarationDoesNotEnableIsForbiddenWithOperationNotSupported(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal(), deletable: false),
            self::route(OperationKind::DeleteResource),
            self::request('DELETE', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::OperationNotSupported->value], self::codes($response));
    }

    /**
     * an excluded route stays registered,
     * so its request reaches the library and is refused before any permission is asked
     */
    public function testAnExcludedRouteIsForbiddenWithOperationNotSupported(): void
    {
        $response = self::refusedAuthorization(
            self::server(
                self::journal(),
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchCollection], 'type "pets"'),
            ),
            self::route(OperationKind::FetchCollection),
            self::request('GET'),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::OperationNotSupported->value], self::codes($response));
    }

    /**
     * the type enables `FetchRelated` through `related`,
     * so only the relationship-level check refuses the related route of `owner`
     */
    public function testARelationshipReadTheRelationshipExcludesIsForbiddenWithRelationshipFetchNotSupported(): void
    {
        $response = self::refusedAuthorization(
            self::server(
                self::journal(),
                ownerExcluded: RouteExclusion::except(
                    RouteOwner::Relationship,
                    [OperationKind::FetchRelated],
                    'relationship "owner"',
                ),
            ),
            self::route(OperationKind::FetchRelated, self::OWNER),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::RelationshipFetchNotSupported->value], self::codes($response));
    }

    /**
     * the type enables `ReplaceRelationship` through `related`,
     * so only the relationship-level check refuses the replace of `owner`
     */
    public function testARelationshipUpdateThatIsNotDeclaredIsForbiddenWithRelationshipUpdateNotSupported(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal()),
            self::route(OperationKind::ReplaceRelationship, self::OWNER),
            self::request('PATCH', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::RelationshipUpdateNotSupported->value], self::codes($response));
    }

    public function testADeniedAuthenticatedClientIsForbidden(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal(), permits: static function (): bool {
                return false;
            }),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::Forbidden->value], self::codes($response));
    }

    public function testADeniedClientWithAChallengeIsUnauthorizedWithIt(): void
    {
        $response = self::refusedAuthorization(
            self::server(
                self::journal(),
                permits: static function (): bool {
                    return false;
                },
                authenticated: false,
                challenges: [new Challenge('Basic', ['realm' => 'WordPress'])],
            ),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(401, $response->status);
        self::assertSame([ErrorCode::Unauthorized->value], self::codes($response));
        self::assertSame(['Basic realm="WordPress"'], self::challengesOf($response));
    }

    public function testADeniedClientWithoutAChallengeIsForbiddenWithAuthenticationRequired(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal(), permits: static function (): bool {
                return false;
            }, authenticated: false),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(403, $response->status);
        self::assertSame([ErrorCode::AuthenticationRequired->value], self::codes($response));
    }

    public function testAChallengeLessUnauthorizedOfAProjectPermissionLeavesWithChallengesOrAsForbidden(): void
    {
        $raise = static function (): bool {
            throw JsonApiException::ofStatus(401, [new ErrorDescription('wp_rest_unauthorized')]);
        };

        $challenged = self::refusedAuthorization(
            self::server(
                self::journal(),
                permits: $raise,
                challenges: [new Challenge('Bearer')],
            ),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );
        $bare = self::refusedAuthorization(
            self::server(self::journal(), permits: $raise),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(401, $challenged->status);
        self::assertSame(['Bearer'], self::challengesOf($challenged));
        self::assertSame(403, $bare->status);
        self::assertSame(['wp_rest_unauthorized'], self::codes($bare));
    }

    public function testAThrowableOfAProjectPermissionLeavesAsTheInternalServerErrorAndIsLogged(): void
    {
        $journal = self::journal();
        $failure = new RuntimeException('the permission broke');

        $response = self::refusedAuthorization(
            self::server($journal, permits: static function () use ($failure): bool {
                throw $failure;
            }),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(500, $response->status);
        self::assertSame([ErrorCode::InternalError->value], self::codes($response));
        self::assertSame([$failure], self::loggedThrowables($journal));
    }

    public function testARefusalIsWrittenInTheMediaTypeOfTheRouteWithNoProfile(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal(), permits: static function (): bool {
                return false;
            }),
            self::route(OperationKind::FetchCollection),
            self::request('GET', headers: ['Accept' => self::CURSOR_ACCEPT]),
        );

        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertSame([], self::applied($response, 'profile'));
    }

    public function testAMethodNotAllowedOfAProjectPermissionCarriesTheAllowFieldOfItsRoute(): void
    {
        $response = self::refusedAuthorization(
            self::server(self::journal(), permits: static function (): bool {
                throw JsonApiException::ofStatus(405, [new ErrorDescription('rest_no_route')]);
            }),
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD, PATCH, DELETE', $response->headers->line('Allow'));
    }

    /**
     * the query parameter is unknown and the body is no JSON,
     * so reading either would fail the request
     */
    public function testAuthorizeReadsNeitherTheQueryNorTheBody(): void
    {
        $journal = self::journal();

        self::server($journal)->authorize(
            self::route(OperationKind::UpdateResource),
            self::request('PATCH', [self::ID_PARAMETER => self::ID], ['nonsense' => '1'], '{'),
        );

        self::assertSame(['level1'], self::steps($journal));
    }

    public function testAuthorizeResolvesNoContentLanguage(): void
    {
        $journal = self::journal();

        self::server($journal)->authorize(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept-Language' => 'de']),
        );

        self::assertNotContains('language', self::steps($journal));
    }

    public function testAuthorizeAsksNoStore(): void
    {
        $journal = self::journal();

        self::server($journal)->authorize(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertNotContains('load', self::steps($journal));
    }
}
