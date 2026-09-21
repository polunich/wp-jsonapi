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
use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;
use RuntimeException;

/**
 * the atomic route: its steps, its preconditions and a failed operation
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerAtomicTest extends JournaledServerTestCase
{
    public function testTheAtomicStepsRunInOrder(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(self::route(null), self::atomicRequest(self::removeDocument()));

        self::assertSame(204, $response->status);
        self::assertSame(
            ['language', 'level1', 'begin', 'load', 'level2', 'process', 'commit'],
            self::steps($journal),
        );
    }

    public function testTheQueryOfAnAtomicRequestIsCheckedBeforeIfMatch(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), ['include' => 'owner'], ['If-Match' => '"1"']),
        );

        self::assertSame(400, $response->status);
    }

    /**
     * the body is no JSON, so reading it would end in 400 instead of 412
     */
    public function testTheLibraryRespondsToAnIfMatchOnTheAtomicRouteBeforeTheDocumentIsRead(): void
    {
        $response = self::server(self::journal())->handle(
            self::route(null),
            self::atomicRequest('{', headers: ['If-Match' => '"1"']),
        );

        self::assertSame(412, $response->status);
    }

    public function testTheExecutorReceivesTheOperationsAndTheContextWithoutAPosition(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(null),
            self::atomicRequest('{"atomic:operations": [
                {"op": "remove", "ref": {"type": "pets", "id": "1"}},
                {"op": "remove", "ref": {"type": "pets", "id": "1"}}
            ]}'),
        );

        [, $first] = self::processed($journal, 0);
        [, $second] = self::processed($journal, 1);

        self::assertSame(0, $first->operationPosition);
        self::assertSame(1, $second->operationPosition);
        self::assertTrue($first->isAtomic());
    }

    public function testTheContextOfAnAtomicRequestSuppliesNoInclude(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(self::route(null), self::atomicRequest(self::removeDocument()));

        [, $context] = self::processed($journal);

        self::assertFalse($context->includeSupplied);
    }

    public function testTheCursorFlagOfAnAtomicRequestIsWhetherTheAcceptFieldAsksForTheProfile(): void
    {
        $asked = self::journal();
        $silent = self::journal();

        self::server($asked)->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), headers: ['Accept' => self::ATOMIC_CURSOR_ACCEPT]),
        );
        self::server($silent)->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument(), headers: ['Accept' => self::ATOMIC]),
        );

        [, $requested] = self::processed($asked);
        [, $plain] = self::processed($silent);

        self::assertTrue($requested->cursorPagination);
        self::assertFalse($plain->cursorPagination);
    }

    public function testTheServerRepeatsNoLevelOneCheckOfTheAtomicExecutor(): void
    {
        $journal = self::journal();

        self::server($journal)->handle(
            self::route(null),
            self::atomicRequest('{"atomic:operations": [
                {"op": "remove", "ref": {"type": "pets", "id": "1"}},
                {"op": "remove", "ref": {"type": "pets", "id": "1"}}
            ]}'),
        );

        self::assertSame(2, self::occurrences($journal, 'level1'));
    }

    public function testTheLibraryRespondsToAFailedOperationAtItsPositionWithItsOwnFailure(): void
    {
        $journal = self::journal();
        $response = self::server($journal, results: [
            OperationKind::DeleteResource->value => static function (
                Operation $operation,
                ExecutionContext $context,
            ): Result {
                if ($context->operationPosition === 1) {
                    throw new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
                }

                return new NoContentResult();
            },
        ])->handle(
            self::route(null),
            self::atomicRequest('{"atomic:operations": [
                {"op": "remove", "ref": {"type": "pets", "id": "1"}},
                {"op": "remove", "ref": {"type": "pets", "id": "1"}}
            ]}'),
        );

        self::assertSame(409, $response->status);
        self::assertSame([ErrorCode::Conflict->value], self::codes($response));
        self::assertSame([['pointer' => '/atomic:operations/1']], self::sources($response));
        self::assertContains('rollback', self::steps($journal));
    }

    /**
     * the delete declares schemes of its own, and inside an atomic request those of the route apply
     */
    public function testTheChallengesOfTheAtomicRouteAreAskedForItsSchemes(): void
    {
        $nonce = [SecurityScheme::restNonce()];
        $passwords = [SecurityScheme::applicationPasswords()];
        $route = new AtomicRoute(Samples::atomicRoute([self::TYPE], securitySchemes: $nonce));
        $request = self::atomicRequest(self::removeDocument());
        $unauthorized = static function (): never {
            throw JsonApiException::ofStatus(401, [new ErrorDescription(ErrorCode::Unauthorized)]);
        };
        $challenges = new RecordingChallenges();
        $server = static function (?Closure $permits, ?Closure $deletes = null) use ($challenges, $passwords): Server {
            return self::server(
                self::journal(),
                results: $deletes === null ? [] : [OperationKind::DeleteResource->value => $deletes],
                permits: $permits,
                authenticated: false,
                challengeProvider: $challenges,
                securitySchemes: [OperationKind::DeleteResource->value => $passwords],
            );
        };

        $server(static function (): bool {
            return false;
        })->handle($route, $request);
        $server(static function (OperationAttempt $attempt): bool {
            return $attempt->record === null;
        })->handle($route, $request);
        $server(null, $unauthorized)->handle($route, $request);
        $server(null)->convertForeignError(401, 'rest_not_logged_in', '', $request, $route);

        self::assertSame([$nonce, $nonce, $nonce, $nonce], $challenges->asked);
    }

    /**
     * the type is checked while the document is read, so no transaction begins
     */
    public function testARouteOfAnotherTypeRefusesTheOperationWithConflict(): void
    {
        $journal = self::journal();

        $response = self::server($journal)->handle(
            self::route(null, type: self::UUIDS),
            self::atomicRequest(self::removeDocument()),
        );

        self::assertSame(409, $response->status);
        self::assertSame([ErrorCode::TypeMismatch->value], self::codes($response));
        self::assertSame([['pointer' => '/atomic:operations/0/ref/type']], self::sources($response));
        self::assertNotContains('begin', self::steps($journal));
    }

    /**
     * a throwable of the transaction boundary belongs to no operation
     */
    public function testTheLibraryRespondsToAThrowableOfTheTransactionBoundaryWithoutAPosition(): void
    {
        $response = self::server(
            self::journal(),
            failing: ['begin' => new RuntimeException('the transaction did not start')],
        )->handle(self::route(null), self::atomicRequest(self::removeDocument()));

        self::assertSame(500, $response->status);
        self::assertSame([null], self::sources($response));
    }

    /**
     * a composition fault: the route is never registered without a transaction boundary
     */
    public function testAnAtomicRouteWithoutATransactionBoundaryIsFiveHundred(): void
    {
        $journal = self::journal();

        $response = self::server($journal, boundaryDeclared: false)->handle(
            self::route(null),
            self::atomicRequest(self::removeDocument()),
        );

        self::assertSame(500, $response->status);
        self::assertLogicExceptionWasLogged($journal);
    }
}
