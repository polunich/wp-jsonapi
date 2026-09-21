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

use Closure;
use DomainException;
use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;
use RuntimeException;

/**
 * a throwable of the flow: the mappers, the log, the denial policy and the media type of the error
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerFailureTest extends JournaledServerTestCase
{
    public function testEveryThrowableOfTheFlowBecomesAResponse(): void
    {
        $response = self::server(self::journal(), results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw new RuntimeException('the processor broke');
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(500, $response->status);
    }

    public function testAJsonApiExceptionPassesTheMappersUnchanged(): void
    {
        $response = self::server(self::journal(), results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(409, $response->status);
        self::assertSame([ErrorCode::Conflict->value], self::codes($response));
    }

    public function testTheLibraryRespondsToAThrowableAProjectMapperClaimsAsTheMapperSaid(): void
    {
        $response = self::server(self::journal(), mappers: [self::mapper()], results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw new DomainException('the record is locked');
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(409, $response->status);
        self::assertSame([ErrorCode::Conflict->value], self::codes($response));
    }

    public function testAnUnclaimedThrowableIsTheInternalServerError(): void
    {
        $response = self::server(self::journal(), results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw new RuntimeException('a secret of the deployment');
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(500, $response->status);
        self::assertSame([ErrorCode::InternalError->value], self::codes($response));
    }

    public function testAFiveHundredIsLoggedOnceWithTheOriginalThrowableUnderTheExceptionKey(): void
    {
        $journal = self::journal();
        $failure = new RuntimeException('the processor broke');

        self::server($journal, results: [
            OperationKind::FetchResource->value => static function () use ($failure): Result {
                throw $failure;
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(['error'], self::loggedLevels($journal));
        self::assertSame([$failure], self::loggedThrowables($journal));
        self::assertStringContainsString(ErrorCode::InternalError->value, self::loggedMessages($journal)[0] ?? '');
        self::assertStringNotContainsString('the processor broke', self::loggedMessages($journal)[0] ?? '');
    }

    public function testAFourHundredIsNotLogged(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID], ['nonsense' => '1']),
        );

        self::assertNotContains('log', self::steps($journal));
    }

    public function testEveryErrorPassesTheRewriteOfTheDenialPolicy(): void
    {
        $raise = static function (): Result {
            throw JsonApiException::ofStatus(401, [new ErrorDescription('wp_rest_unauthorized')]);
        };
        $results = [OperationKind::FetchResource->value => $raise];
        $route = self::route(OperationKind::FetchResource);
        $request = self::request('GET', [self::ID_PARAMETER => self::ID]);

        $challenged = self::server(
            self::journal(),
            results: $results,
            challenges: [new Challenge('Bearer')],
        )->handle($route, $request);
        $bare = self::server(self::journal(), results: $results)->handle($route, $request);

        self::assertSame(401, $challenged->status);
        self::assertSame('Bearer', $challenged->headers->line('WWW-Authenticate'));
        self::assertSame(403, $bare->status);
        self::assertSame(['wp_rest_unauthorized'], self::codes($bare));
    }

    /**
     * the collection route declares no schemes of its own, so it takes the API's, which are none
     */
    public function testTheChallengesOfAResourceRouteAreAskedForTheSchemesOfThatRoute(): void
    {
        $nonce = [SecurityScheme::restNonce()];
        $route = self::route(OperationKind::FetchResource);
        $request = self::request('GET', [self::ID_PARAMETER => self::ID]);
        $unauthorized = static function (): never {
            throw JsonApiException::ofStatus(401, [new ErrorDescription(ErrorCode::Unauthorized)]);
        };
        $challenges = new RecordingChallenges();
        $server = static function (?Closure $permits, ?Closure $fetches = null) use ($nonce, $challenges): Server {
            return self::server(
                self::journal(),
                results: $fetches === null ? [] : [OperationKind::FetchResource->value => $fetches],
                permits: $permits,
                authenticated: false,
                challengeProvider: $challenges,
                securitySchemes: [OperationKind::FetchResource->value => $nonce],
            );
        };

        $server(static function (): bool {
            return false;
        })->authorize($route, $request);
        $server(static function (OperationAttempt $attempt): bool {
            return $attempt->record === null;
        })->handle($route, $request);
        $server(null, $unauthorized)->handle($route, $request);
        $server(null)->convertForeignError(401, 'rest_not_logged_in', '', $request, $route);
        $server(null)->convertForeignError(
            401,
            'rest_not_logged_in',
            '',
            $request,
            self::route(OperationKind::FetchCollection),
        );

        self::assertSame([$nonce, $nonce, $nonce, $nonce, []], $challenges->asked);
    }

    /**
     * on the atomic route, where a negotiated response would name the atomic extension
     */
    public function testAFailureBeforeTheNegotiationIsWrittenInTheBareMediaType(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), headers: ['Accept' => 'application/xml']),
        );

        self::assertSame(406, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
    }

    public function testAFailureAfterTheNegotiationIsWrittenInItsMediaType(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), headers: ['If-Match' => '"1"']),
        );

        self::assertSame(412, $response->status);
        self::assertSame(
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION])->toHeaderValue(),
            $response->headers->line('Content-Type'),
        );
    }

    public function testAMethodNotAllowedOfTheFlowCarriesTheAllowFieldOfItsRoute(): void
    {
        $response = self::server(self::journal(), results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw JsonApiException::ofStatus(405, [new ErrorDescription('rest_no_route')]);
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD, PATCH, DELETE', $response->headers->line('Allow'));
    }

    public function testNoOtherStatusOfTheFlowCarriesAnAllowField(): void
    {
        $response = self::server(self::journal(), results: [
            OperationKind::FetchResource->value => static function (): Result {
                throw new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
            },
        ])->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(409, $response->status);
        self::assertFalse($response->headers->has('Allow'));
    }
}
