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
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;

/**
 * the route of an endpoint: its permission, its steps, its body,
 * its responses and its preconditions
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerEndpointTest extends JournaledServerTestCase
{
    public function testTheRouteOfAnEndpointAsksItsPermissionWithoutRecords(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint();

        $response = self::server($journal, endpoints: [$endpoint])->authorize(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );
        $attempt = self::payloads($journal, 'endpointLevel1')[0] ?? null;

        self::assertNull($response);
        self::assertSame(['endpointLevel1'], self::steps($journal));
        self::assertInstanceOf(EndpointAttempt::class, $attempt);
        self::assertSame('adoptPet', $attempt->endpointName);
    }

    /**
     * every denial and every 401 on the route of the endpoint asks for the challenges
     * of the schemes the endpoint declares;
     * a foreign error without a route asks for those of the API, none here
     */
    public function testTheChallengesOfARouteAreAskedForTheSchemesOfThatRoute(): void
    {
        $nonce = [SecurityScheme::restNonce()];
        $endpoint = Samples::endpoint(securitySchemes: $nonce);
        $route = new EndpointRoute($endpoint);
        $request = self::request('POST', [self::ID_PARAMETER => self::ID]);
        $unauthorized = static function (): never {
            throw JsonApiException::ofStatus(401, [new ErrorDescription(ErrorCode::Unauthorized)]);
        };
        $challenges = new RecordingChallenges();
        $server = static function (
            ?Closure $endpointPermits,
            ?Closure $handles = null,
        ) use ($endpoint, $challenges): Server {
            return self::server(
                self::journal(),
                authenticated: false,
                endpoints: [$endpoint],
                endpointPermits: $endpointPermits,
                handles: $handles,
                challengeProvider: $challenges,
            );
        };

        $server(static function (): bool {
            return false;
        })->authorize($route, $request);
        $server(static function (EndpointAttempt $attempt): bool {
            return $attempt->records === null;
        })->handle($route, $request);
        $server(null, $unauthorized)->handle($route, $request);
        $server(null)->convertForeignError(401, 'rest_not_logged_in', '', $request, $route);
        $server(null)->convertForeignError(401, 'rest_not_logged_in', '', $request, null);

        self::assertSame([$nonce, $nonce, $nonce, $nonce, []], $challenges->asked);
    }

    /**
     * an endpoint applies no extension, so its refusal is written in the bare media type
     */
    public function testARefusedEndpointIsForbiddenInTheBareMediaType(): void
    {
        $endpoint = Samples::endpoint();

        $response = self::server(self::journal(), endpoints: [$endpoint], endpointPermits: static function (): bool {
            return false;
        })->authorize(new EndpointRoute($endpoint), self::request('POST', [self::ID_PARAMETER => self::ID]));

        self::assertNotNull($response);
        self::assertSame(403, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
    }

    /**
     * the negotiation, the path and the query write no journal entry;
     * the precedence tests below pin their place in the order
     */
    public function testTheStepsOfAnEndpointRunInOrder(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint();

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(200, $response->status);
        self::assertSame(['language', 'load', 'endpointLevel2', 'handle'], self::steps($journal));
    }

    public function testTheHandlerReceivesThePathTheRecordsAndTheQueryParametersOfTheRequest(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(queryParameters: [
            new QueryParameterMetadata('adoptionNote', new StringCodec(), false),
        ]);
        $request = self::request('POST', [self::ID_PARAMETER => self::ID], ['adoptionNote' => 'a good home']);

        self::server($journal, endpoints: [$endpoint])->handle(new EndpointRoute($endpoint), $request);
        $handled = self::payloads($journal, 'handle')[0] ?? null;

        self::assertInstanceOf(Request::class, $handled);

        $pet = $handled->record(self::ID_PARAMETER);

        self::assertInstanceOf(Pet::class, $pet);
        self::assertSame(self::ID, $pet->id);
        self::assertSame('adoptPet', $handled->endpointName);
        self::assertSame([self::ID_PARAMETER => self::ID], $handled->path);
        self::assertSame('a good home', $handled->queryParameters->get('adoptionNote'));
        self::assertNull($handled->body);
        self::assertNull($handled->precondition);
        self::assertSame($request, $handled->http);
    }

    public function testTheResponseOfAnEndpointIsRenderedInTheNegotiatedMediaTypeAndLanguage(): void
    {
        $endpoint = Samples::endpoint();

        $response = self::server(self::journal(), language: 'uk', endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertSame('uk', $response->headers->line('Content-Language'));
        self::assertIsArray($response->document);
        self::assertSame(['jsonapi', 'data'], array_keys($response->document));
    }

    public function testAPathValueTheCodecRefusesIsNotFoundBeforeTheQueryIsRead(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint();

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => 'not-digits'], ['nowhere' => '1']),
        );

        self::assertSame(404, $response->status);
        self::assertSame([ErrorCode::NotFound->value], self::codes($response));
        self::assertNotContains('load', self::steps($journal));
    }

    public function testTheLibraryRespondsToAQueryErrorOfAnEndpointBeforeItsRecordsAreLoaded(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(queryParameters: [
            new QueryParameterMetadata('adoptionNote', new StringCodec(), true),
        ]);

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(400, $response->status);
        self::assertSame([ErrorCode::MissingQueryParameter->value], self::codes($response));
        self::assertNotContains('load', self::steps($journal));
    }

    public function testARecordTheLoaderDoesNotReturnIsNotFoundBeforeTheSecondLevelOfTheEndpoint(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint();

        $response = self::server(
            $journal,
            records: [],
            endpoints: [$endpoint],
            endpointPermits: static function (): bool {
                return false;
            },
        )->handle(new EndpointRoute($endpoint), self::request('POST', [self::ID_PARAMETER => self::ID]));

        self::assertSame(404, $response->status);
        self::assertNotContains('endpointLevel2', self::steps($journal));
    }

    public function testTheLibraryRespondsToASecondLevelDenialOfAnEndpointBeforeItsPreconditions(): void
    {
        $endpoint = Samples::endpoint(preconditionParameter: self::ID_PARAMETER);

        $response = self::server(
            self::journal(),
            versions: self::versions(true),
            endpoints: [$endpoint],
            endpointPermits: static function (EndpointAttempt $attempt): bool {
                return $attempt->records === null;
            },
        )->handle(new EndpointRoute($endpoint), self::request('POST', [self::ID_PARAMETER => self::ID]));

        self::assertSame(403, $response->status);
    }

    public function testAMissingIfMatchOfAnEndpointThatWritesAVersionedRecordIsPreconditionRequired(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(preconditionParameter: self::ID_PARAMETER);

        $response = self::server($journal, versions: self::versions(true), endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(428, $response->status);
        self::assertNotContains('handle', self::steps($journal));
    }

    public function testTheLibraryRespondsToAFailingPreconditionOfAnEndpointBeforeItsBodyIsRead(): void
    {
        $endpoint = Samples::endpoint(
            body: new BodyMetadata(BodyShapeKind::ToOne, self::TYPE, null, null),
            preconditionParameter: self::ID_PARAMETER,
        );

        $response = self::server(self::journal(), versions: self::versions(false), endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID], body
                : '{', headers
                : ['If-Match' => '"no such version"']),
        );

        self::assertSame(412, $response->status);
    }

    public function testTheBodyOfAnEndpointIsReadBeforeItsHandlerRuns(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(body: new BodyMetadata(BodyShapeKind::ToOne, self::TYPE, null, null));

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID], body: '{'),
        );

        self::assertSame(400, $response->status);
        self::assertNotContains('handle', self::steps($journal));
    }

    /**
     * the precondition is handed over once the evaluation held,
     * and only for a write that changes the record
     */
    #[DataProvider('conditionalEndpoints')]
    public function testTheHandlerOfAConditionalWriteReceivesThePreconditionTheEvaluationHeld(
        bool $leavesUnchanged,
        bool $handed,
    ): void {
        $journal = self::journal();
        $endpoint = Samples::endpoint(preconditionParameter: self::ID_PARAMETER, leavesUnchanged: $leavesUnchanged);
        $server = self::server($journal, versions: self::versions(true), endpoints: [$endpoint]);
        $current = $server->handle(
            self::route(OperationKind::FetchResource),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        )
            ->headers->line('ETag');

        self::assertNotNull($current);

        $response = $server->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID], headers: ['If-Match' => $current]),
        );
        $handled = self::payloads($journal, 'handle')[0] ?? null;

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $handled);
        self::assertSame($handed, $handled->precondition !== null);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function conditionalEndpoints(): iterable
    {
        yield 'a write of the record' => [false, true];
        yield 'a request that leaves the record unchanged' => [true, false];
    }

    public function testAGetEndpointRespondsWithNotModifiedWithoutRunningItsHandler(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(
            'petCard',
            'GET',
            '/pets/{id}/card',
            preconditionParameter: self::ID_PARAMETER,
        );
        $server = self::server($journal, versions: self::versions(false), endpoints: [$endpoint]);
        $first = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        );
        $tag = $first->headers->line('ETag');

        self::assertNotNull($tag);

        $second = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['If-None-Match' => $tag]),
        );

        self::assertSame(200, $first->status);
        self::assertSame(304, $second->status);
        self::assertNull($second->document);
        self::assertSame(1, self::occurrences($journal, 'handle'));
    }

    public function testAnEndpointRespondsInTheRepresentationAcceptPicked(): void
    {
        $journal = self::journal();
        $endpoint = self::photoEndpoint();
        $server = self::server($journal, endpoints: [$endpoint], handles: static function (Request $request): Response {
            return $request->mediaType === 'image/png'
                ? Response::content(Content::bytes("\x89PNG"), 'image/png', 'rex.png')
                : Response::resource($request->record(self::ID_PARAMETER));
        });

        $png = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => 'image/*']),
        );
        $document = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => self::JSON_API]),
        );
        $handled = self::payloads($journal, 'handle')[0] ?? null;

        self::assertSame(200, $png->status);
        self::assertSame('image/png', $png->headers->line('Content-Type'));
        self::assertSame('attachment; filename="rex.png"', $png->headers->line('Content-Disposition'));
        self::assertNull($png->document);
        self::assertNotNull($png->content);
        self::assertSame(self::JSON_API, $document->headers->line('Content-Type'));
        self::assertInstanceOf(Request::class, $handled);
        self::assertSame('image/png', $handled->mediaType);
        self::assertNull($handled->bodyMediaType);
    }

    public function testNoAcceptableRepresentationOfAnEndpointIs406BeforeItsRecordsAreLoaded(): void
    {
        $journal = self::journal();
        $endpoint = self::photoEndpoint();

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => 'text/csv']),
        );

        self::assertSame(406, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertNotContains('load', self::steps($journal));
    }

    public function testAnErrorOfARequestThatPickedAMediaResponseIsAJsonApiDocument(): void
    {
        $endpoint = self::photoEndpoint();

        $response = self::server(self::journal(), endpoints: [$endpoint], handles: static function (): Response {
            return Response::content(Content::file('/nonexistent/rex.png'), 'image/png');
        })->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => 'image/png']),
        );

        self::assertSame(500, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertIsArray($response->document);
    }

    public function testAReadThatPickedAMediaResponseComparesNoTagOfTheRecord(): void
    {
        $journal = self::journal();
        $endpoint = self::photoEndpoint();
        $server = self::server(
            $journal,
            versions: self::versions(false),
            endpoints: [$endpoint],
            handles: static function (Request $request): Response {
                return $request->mediaType === 'image/png'
                    ? Response::content(Content::bytes("\x89PNG"), 'image/png')
                    : Response::resource($request->record(self::ID_PARAMETER));
            },
        );
        $tag = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['Accept' => self::JSON_API]),
        )->headers->line('ETag');

        self::assertNotNull($tag);

        $png = $server->handle(
            new EndpointRoute($endpoint),
            self::request(
                'GET',
                [self::ID_PARAMETER => self::ID],
                headers: ['Accept' => 'image/png', 'If-None-Match' => $tag],
            ),
        );

        self::assertSame(200, $png->status);
        self::assertNull($png->headers->line('ETag'));
    }

    /**
     * the tag of a write is compared whatever representation the response takes:
     * a write that picked a media response still changes the record the tag names
     */
    public function testAWriteThatPickedAMediaResponseIsStillConditional(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(
            mediaResponses: [new MediaResponseMetadata(new MediaType('text', 'csv'), null)],
            jsonApiRank: 1,
            preconditionParameter: self::ID_PARAMETER,
        );

        $response = self::server($journal, versions: self::versions(true), endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID], headers: ['Accept' => 'text/csv']),
        );

        self::assertSame(428, $response->status);
        self::assertNotContains('handle', self::steps($journal));
    }

    /**
     * a GET changes nothing, so its handler gets no precondition to make a condition of its write,
     * even where the request carries an If-Match that holds
     */
    public function testTheHandlerOfAGetEndpointReceivesNoPrecondition(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(
            'petCard',
            'GET',
            '/pets/{id}/card',
            preconditionParameter: self::ID_PARAMETER,
        );
        $server = self::server($journal, versions: self::versions(false), endpoints: [$endpoint]);
        $tag = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID]),
        )->headers->line('ETag');

        self::assertNotNull($tag);

        $response = $server->handle(
            new EndpointRoute($endpoint),
            self::request('GET', [self::ID_PARAMETER => self::ID], headers: ['If-Match' => $tag]),
        );
        $handled = self::payloads($journal, 'handle')[1] ?? null;

        self::assertSame(200, $response->status);
        self::assertInstanceOf(Request::class, $handled);
        self::assertNull($handled->precondition);
    }

    /**
     * the document of an endpoint carries `included` wherever the request supplies `include`,
     * even empty, as the document of a generated route does
     */
    public function testAnEndpointRespondsWithIncludedWhereTheRequestSuppliesInclude(): void
    {
        $endpoint = Samples::endpoint();
        $server = self::server(self::journal(), endpoints: [$endpoint]);

        $supplied = $server->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID], ['include' => '']),
        );
        $omitted = $server->handle(
            new EndpointRoute($endpoint),
            self::request('POST', [self::ID_PARAMETER => self::ID]),
        );

        self::assertSame(200, $supplied->status);
        self::assertIsArray($supplied->document);
        self::assertSame([], $supplied->document['included'] ?? null);
        self::assertIsArray($omitted->document);
        self::assertArrayNotHasKey('included', $omitted->document);
    }

    public function testContentOfAMediaTypeTheEndpointDoesNotReadIs415ListingTheOnesItReads(): void
    {
        $endpoint = Samples::endpoint(mediaBodies: [
            new MediaBodyMetadata(new MediaType('application', 'json'), MediaBodyShapeKind::Json, new StringCodec()),
            new MediaBodyMetadata(new MediaType('multipart', 'form-data'), MediaBodyShapeKind::Form),
        ]);

        $response = self::server(self::journal(), endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request(
                'POST',
                [self::ID_PARAMETER => self::ID],
                headers: ['Content-Type' => 'text/plain', 'Content-Length' => '3'],
            ),
        );

        self::assertSame(415, $response->status);
        self::assertSame('application/json, multipart/form-data', $response->headers->line('Accept'));
    }

    public function testTheBodyInAnotherMediaTypeReachesTheHandlerWithItsMediaType(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint(
            responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            mediaBodies: [new MediaBodyMetadata(
                new MediaType('application', 'json'),
                MediaBodyShapeKind::Json,
                new StringCodec(),
            )],
        );

        $response = self::server($journal, endpoints: [$endpoint], handles: static function (): Response {
            return Response::noContent();
        })->handle(
            new EndpointRoute($endpoint),
            new ServerRequest(
                'POST',
                new Headers(['Content-Type' => 'application/json']),
                [],
                [self::ID_PARAMETER => self::ID],
                '"a good home"',
            ),
        );
        $handled = self::payloads($journal, 'handle')[0] ?? null;

        self::assertSame(204, $response->status);
        self::assertInstanceOf(Request::class, $handled);
        self::assertInstanceOf(JsonBody::class, $handled->body);
        self::assertSame('a good home', $handled->body->value);
        self::assertSame('application/json', $handled->bodyMediaType);
    }

    public function testAMethodTheRouteOfAnEndpointDoesNotRespondToIsFiveHundredNamingTheEndpoint(): void
    {
        $journal = self::journal();
        $endpoint = Samples::endpoint();

        $response = self::server($journal, endpoints: [$endpoint])->handle(
            new EndpointRoute($endpoint),
            self::request('PATCH', [self::ID_PARAMETER => self::ID]),
        );

        $logged = self::loggedThrowables($journal)[0] ?? null;

        self::assertSame(500, $response->status);
        self::assertInstanceOf(LogicException::class, $logged);
        self::assertStringContainsString(
            'this route of the endpoint "adoptPet" responds to POST',
            $logged->getMessage(),
        );
        self::assertNotContains('handle', self::steps($journal));
    }
}
