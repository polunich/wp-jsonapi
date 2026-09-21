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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;

/**
 * `convertForeignError()`: an error the platform wrote, as a JSON:API error document
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerForeignErrorTest extends JournaledServerTestCase
{
    public function testTheForeignCodeAndTheForeignMessageBecomeTheCodeAndTheDetail(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            403,
            'rest_cannot_edit',
            'Sorry, you are not allowed to edit this post.',
            self::request('GET'),
        );

        self::assertSame(403, $response->status);
        self::assertSame(['rest_cannot_edit'], self::codes($response));
        self::assertSame(['Sorry, you are not allowed to edit this post.'], self::details($response));
    }

    public function testAnEmptyMessageWritesNoDetail(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            404,
            'rest_no_route',
            '',
            self::request('GET'),
        );

        self::assertSame(['rest_no_route'], self::codes($response));
        self::assertSame([null], self::details($response));
    }

    public function testAnEmptyCodeTakesTheGenericCodeOfItsStatus(): void
    {
        $response = self::server(self::journal())->convertForeignError(404, '', 'gone missing', self::request('GET'));

        self::assertSame([ErrorCode::NotFound->value], self::codes($response));
    }

    #[DataProvider('foreignStatuses')]
    public function testTheStatusPicksTheLibraryExceptionOfThatStatus(int $status, int $converted): void
    {
        $response = self::server(self::journal())->convertForeignError(
            $status,
            'rest_forbidden',
            'no',
            self::request('GET'),
        );

        self::assertSame($converted, $response->status);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function foreignStatuses(): iterable
    {
        yield 'a bad request' => [400, 400];
        yield 'a forbidden request' => [403, 403];
        yield 'a status without a library class' => [410, 410];
        yield 'an internal error' => [500, 500];
        yield 'the highest status a client understands' => [599, 599];
        yield 'the first status outside the range' => [600, 500];
    }

    public function testAStatusAboveFiveHundredNinetyNineBecomesFiveHundred(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            900,
            'rest_broken',
            'no',
            self::request('GET'),
        );

        self::assertSame(500, $response->status);
    }

    public function testAStatusBelowFourHundredIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // the message, not the class alone:
        // `Error\JsonApiException` throws the same class for a 204
        $this->expectExceptionMessage(
            'a foreign response is converted where it is an error, which is the status 400 and above,'
            . ' got 204',
        );

        self::server(self::journal())->convertForeignError(204, 'rest_ok', '', self::request('GET'));
    }

    public function testAForeignUnauthorizedGetsTheChallengesOfTheProvider(): void
    {
        $response = self::server(
            self::journal(),
            challenges: [new Challenge('Basic', ['realm' => 'WordPress'])],
        )->convertForeignError(401, 'rest_not_logged_in', 'You are not currently logged in.', self::request('GET'));

        self::assertSame(401, $response->status);
        self::assertSame('Basic realm="WordPress"', $response->headers->line('WWW-Authenticate'));
    }

    /**
     * the route stands for its path:
     * a request of a method it does not respond to asked for none of its schemes
     */
    public function testAForeignUnauthorizedIsChallengedForTheSchemesOfTheRouteOnlyWhereItRespondsToTheMethod(): void
    {
        $nonce = [SecurityScheme::restNonce()];
        $challenges = new RecordingChallenges();
        $server = self::server(
            self::journal(),
            authenticated: false,
            challengeProvider: $challenges,
            securitySchemes: [OperationKind::FetchResource->value => $nonce],
        );
        $route = self::route(OperationKind::FetchResource);
        $path = [self::ID_PARAMETER => self::ID];

        $server->convertForeignError(401, 'incorrect_password', '', self::request('GET', $path), $route);
        $server->convertForeignError(401, 'incorrect_password', '', self::request('DELETE', $path), $route);

        self::assertSame([$nonce, []], $challenges->asked);
    }

    public function testAForeignUnauthorizedWithoutAChallengeBecomesForbiddenAndKeepsItsCode(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            401,
            'rest_not_logged_in',
            'You are not currently logged in.',
            self::request('GET'),
        );

        self::assertSame(403, $response->status);
        self::assertSame(['rest_not_logged_in'], self::codes($response));
    }

    public function testAForeignMethodNotAllowedCarriesTheAllowFieldOfTheRouteItMatched(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            405,
            'rest_no_route',
            'No route was found matching the URL and request method.',
            self::request('PUT'),
            self::route(OperationKind::FetchCollection),
        );

        self::assertSame(405, $response->status);
        self::assertSame('GET, HEAD, POST', $response->headers->line('Allow'));
    }

    public function testAForeignMethodNotAllowedWithoutARouteCarriesTheEmptyAllowField(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            405,
            'rest_no_route',
            'No route was found matching the URL and request method.',
            self::request('PUT'),
        );

        self::assertSame(405, $response->status);
        self::assertSame('', $response->headers->line('Allow'));
    }

    public function testNoOtherStatusCarriesAnAllowField(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            404,
            'rest_no_route',
            'No route was found matching the URL and request method.',
            self::request('GET'),
            self::route(OperationKind::FetchCollection),
        );

        self::assertFalse($response->headers->has('Allow'));
    }

    public function testAForeignErrorIsNeitherMappedNorLogged(): void
    {
        $journal = self::journal();

        $response = self::server($journal, mappers: [self::mapper()])->convertForeignError(
            500,
            'internal_server_error',
            'There has been a critical error on this website.',
            self::request('GET'),
        );

        self::assertSame(500, $response->status);
        self::assertSame(['internal_server_error'], self::codes($response));
        self::assertSame([], self::steps($journal));
    }

    public function testTheDocumentOfAForeignErrorNamesNoProfile(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            404,
            'rest_no_route',
            'no',
            self::request('GET'),
            self::route(OperationKind::FetchCollection),
        );

        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertSame([], self::applied($response, 'profile'));
    }

    public function testAForeignErrorOfTheAtomicRouteIsWrittenUnderTheAtomicExtension(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            403,
            'rest_forbidden',
            'no',
            self::request('POST'),
            self::route(null),
        );

        self::assertSame(self::ATOMIC, $response->headers->line('Content-Type'));
        self::assertSame([MediaType::ATOMIC_EXTENSION], self::applied($response, 'ext'));
    }

    public function testAForeignErrorWithoutARouteIsWrittenInTheBareMediaType(): void
    {
        $response = self::server(self::journal())->convertForeignError(
            404,
            'rest_no_route',
            'no',
            self::request('POST'),
        );

        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertSame([], self::applied($response, 'ext'));
    }
}
