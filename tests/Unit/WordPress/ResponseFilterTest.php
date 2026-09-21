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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use Generator;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\RecordingChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\RecordingRestServer;
use Polunich\WpJsonApi\WordPress\OutputBuffers;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use Polunich\WpJsonApi\WordPress\RegisteredCallbacks;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use Polunich\WpJsonApi\WordPress\ResponseFilter;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * @internal
 */
#[CoversClass(ResponseFilter::class)]
final class ResponseFilterTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    /** @var list<int> the statuses the filter sent itself */
    private array $sent = [];

    /** @var list<string> the pieces of content the filter wrote */
    private array $written = [];

    /** @var list<string> the calls the output buffers were given */
    private array $bufferCalls = [];

    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalMutableAndInternal(): void
    {
        $class = new ReflectionClass(ResponseFilter::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheResponseTheFiltersKeptIsServedWithTheirChanges(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $body = ['jsonapi' => ['version' => '1.1'], 'data' => []];
        $restResponse = self::toWordPress(new Response(200, new Headers(['Content-Type' => self::JSON_API]), $body));
        $recorded->record($request, $restResponse);
        // a site callback changes the response,
        // and WordPress sets the matched route after `rest_request_after_callbacks`
        $restResponse->header('X-Site', 'kept');
        $restResponse->set_matched_route('/acme/v1/pets');

        $served = $this->filter(recorded: $recorded)->filterPostDispatch($restResponse, new WP_REST_Server(), $request);

        self::assertSame($restResponse, $served);
        self::assertSame(200, $restResponse->get_status());
        self::assertSame($body, $restResponse->get_data());
        self::assertSame(['content-type' => self::JSON_API, 'X-Site' => 'kept'], $restResponse->get_headers());
        self::assertSame('', $restResponse->get_matched_route());
    }

    public function testTheStandInOfARefusalIsReplacedByTheResponseOfTheRefusal(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/1');
        $body = ['jsonapi' => ['version' => '1.1'], 'errors' => [['status' => '403', 'code' => 'forbidden']]];
        $refusal = self::toWordPress(new Response(403, new Headers(['Content-Type' => self::JSON_API]), $body));
        $standIn = new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
        $recorded->record($request, $refusal, $standIn);
        $filter = $this->filter(recorded: $recorded);

        $afterCallbacks = $filter->filterRequestAfterCallbacks($standIn, [], $request);
        $served = $filter->filterPostDispatch($afterCallbacks, new WP_REST_Server(), $request);

        self::assertSame($refusal, $afterCallbacks);
        self::assertSame($refusal, $served);
        self::assertSame(403, $refusal->get_status());
        self::assertSame($body, $refusal->get_data());
    }

    public function testEveryValueButTheStandInOfItsRequestPassesTheCallbacksUntouched(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/1');
        $standIn = new WP_Error('forbidden', 'Forbidden', ['status' => 403]);
        $recorded->record($request, new WP_REST_Response(null, 403), $standIn);
        $filter = $this->filter(recorded: $recorded);
        $replaced = new WP_Error('site_says_no', 'the site says no', ['status' => 403]);
        $restResponse = new WP_REST_Response(null, 204);
        $other = new WP_REST_Request('DELETE', '/acme/v1/pets/2');
        $recorded->record($other, $restResponse);

        self::assertSame($replaced, $filter->filterRequestAfterCallbacks($replaced, [], $request));
        self::assertSame(
            $standIn,
            $filter->filterRequestAfterCallbacks($standIn, [], new WP_REST_Request('DELETE', '/acme/v1/pets/1')),
        );
        self::assertSame($restResponse, $filter->filterRequestAfterCallbacks($restResponse, [], $other));
        self::assertNull($filter->filterRequestAfterCallbacks(null, [], $other));
    }

    public function testAValueReturnedInPlaceOfTheResponseIsNotServedAsTheResponse(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $recorded->record(
            $request,
            self::toWordPress(new Response(200, new Headers(['Content-Type' => self::JSON_API]), ['data' => []])),
        );
        $filter = $this->filter(recorded: $recorded);
        $cached = new WP_REST_Response(['cached' => true], 200);
        $cached->set_matched_route('/acme/v1/pets');

        $error = $filter->filterPostDispatch(
            self::wordPressError(409, 'site_conflict', 'the site says no'),
            new WP_REST_Server(),
            $request,
        );
        $success = $filter->filterPostDispatch($cached, new WP_REST_Server(), $request);

        self::assertInstanceOf(WP_REST_Response::class, $error);
        self::assertSame(409, $error->get_status());
        self::assertSame(self::JSON_API, $error->get_headers()['content-type'] ?? null);
        self::assertSame(
            ['code' => 'site_conflict', 'detail' => 'the site says no'],
            array_intersect_key(self::firstError($error), ['code' => true, 'detail' => true]),
        );
        self::assertSame($cached, $success);
        self::assertSame(['cached' => true], $cached->get_data());
        self::assertSame('/acme/v1/pets', $cached->get_matched_route());
    }

    public function testAWordPressErrorOnTheNamespaceBecomesTheLibrarysDocument(): void
    {
        $served = $this->filter()->filterPostDispatch(
            self::wordPressError(404, 'rest_no_route', 'No route was found matching the URL and request method.'),
            new WP_REST_Server(),
            new WP_REST_Request('PUT', '/acme/v1/pets/1'),
        );

        self::assertInstanceOf(WP_REST_Response::class, $served);
        self::assertSame(404, $served->get_status());
        self::assertSame(self::JSON_API, $served->get_headers()['content-type'] ?? null);
        self::assertSame(
            ['code' => 'rest_no_route', 'detail' => 'No route was found matching the URL and request method.'],
            array_intersect_key(self::firstError($served), ['code' => true, 'detail' => true]),
        );
    }

    public function testAnAuthenticationErrorGetsTheChallengesOrBecomesForbidden(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $challenged = $this->filter(challenges: [new Challenge('Basic', ['realm' => 'WordPress'])])
            ->filterPostDispatch(
                self::wordPressError(401, 'incorrect_password', 'wrong'),
                new WP_REST_Server(),
                $request,
            );
        $bare = $this->filter()
            ->filterPostDispatch(
                self::wordPressError(401, 'incorrect_password', 'wrong'),
                new WP_REST_Server(),
                $request,
            );

        self::assertInstanceOf(WP_REST_Response::class, $challenged);
        self::assertSame(401, $challenged->get_status());
        self::assertSame('Basic realm="WordPress"', $challenged->get_headers()['www-authenticate'] ?? null);
        self::assertInstanceOf(WP_REST_Response::class, $bare);
        self::assertSame(403, $bare->get_status());
        self::assertSame('incorrect_password', self::firstError($bare)['code'] ?? null);
    }

    /**
     * WordPress authenticates before it matches a handler, so the error meets no callback,
     * and the fetch of the pet, which inherits the API's schemes, is the first route of the path
     */
    public function testAnAuthenticationErrorIsChallengedForTheSchemesOfTheRouteOfItsMethod(): void
    {
        $passwords = [SecurityScheme::applicationPasswords()];
        $challenges = new RecordingChallenges();
        $filter = $this->filter(provider: $challenges, deleteSchemes: $passwords);

        foreach (['DELETE', 'GET'] as $method) {
            $filter->filterPostDispatch(
                self::wordPressError(401, 'incorrect_password', 'wrong'),
                new WP_REST_Server(),
                new WP_REST_Request($method, '/acme/v1/pets/1'),
            );
        }

        self::assertSame([$passwords, []], $challenges->asked);
    }

    public function testTheRouteOfTheFailingPathNamesTheAllowOfAMethodNotAllowed(): void
    {
        $served = $this->filter()->filterPostDispatch(
            self::wordPressError(405, 'rest_method', 'no'),
            new WP_REST_Server(),
            new WP_REST_Request('PUT', '/acme/v1/pets/1'),
        );

        self::assertInstanceOf(WP_REST_Response::class, $served);
        self::assertSame('GET, HEAD, DELETE', $served->get_headers()['allow'] ?? null);
    }

    /**
     * WordPress matches a route ignoring case,
     * and an error raised before dispatch meets the route as sent, which no filter normalized
     */
    #[DataProvider('pathsOfTheRouteOfTheLibrary')]
    public function testAnErrorWithNoCallbackOnAPathOfARouteOfTheLibraryIsConverted(string $path): void
    {
        $wordPress = self::wordPressError(401, 'incorrect_password', 'wrong');

        $served = $this->filter()->filterPostDispatch(
            $wordPress,
            new WP_REST_Server(),
            new WP_REST_Request('GET', $path),
        );

        self::assertInstanceOf(WP_REST_Response::class, $served);
        self::assertNotSame($wordPress, $served);
        self::assertSame(self::JSON_API, $served->get_headers()['content-type'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOfTheRouteOfTheLibrary(): iterable
    {
        yield 'as the library writes it' => ['/acme/v1/pets/1'];
        yield 'in another case' => ['/ACME/v1/PETS/1'];
        yield 'with a literal percent-encoded' => ['/acme/v1/p%65ts/1'];
        yield 'with an id its codec refuses' => ['/acme/v1/pets/abc'];
    }

    public function testAnErrorWithNoCallbackOnAPathOfNoRouteOfTheLibraryPassesUntouched(): void
    {
        $wordPress = self::wordPressError(404, 'rest_no_route', 'no');

        $served = $this->filter()->filterPostDispatch(
            $wordPress,
            new WP_REST_Server(),
            new WP_REST_Request('GET', '/acme/v1/nothing'),
        );

        self::assertSame($wordPress, $served);
    }

    /**
     * another plugin may register a route on the namespace of the library, and its error is its own
     */
    public function testAnErrorOfACallbackTheLibraryDidNotRegisterPassesUntouched(): void
    {
        $request = new WP_REST_Request('GET', '/acme/v1/pets/1');
        $request->set_attributes(['callback' => static function (): WP_Error {
            return new WP_Error('site_conflict', 'the site says no', ['status' => 409]);
        }]);
        $foreign = self::wordPressError(409, 'site_conflict', 'the site says no');

        self::assertSame($foreign, $this->filter()->filterPostDispatch($foreign, new WP_REST_Server(), $request));
    }

    public function testAnErrorOfACallbackTheLibraryRegisteredIsConvertedWithItsRoute(): void
    {
        $callbacks = new RegisteredCallbacks();
        $filter = $this->filter(callbacks: $callbacks);
        $callback = static function (): WP_Error {
            return new WP_Error('internal_error', '', ['status' => 500]);
        };
        $delete = null;

        foreach ($this->server()->routes() as $route) {
            if ($route instanceof OperationRoute && $route->operationKind === OperationKind::DeleteResource) {
                $delete = $route;
            }
        }

        self::assertNotNull($delete);
        $callbacks->record($callback, $delete);
        $request = new WP_REST_Request('DELETE', '/somewhere/else');
        $request->set_attributes(['callback' => $callback]);

        $served = $filter->filterPostDispatch(
            self::wordPressError(405, 'rest_method', 'no'),
            new WP_REST_Server(),
            $request,
        );

        self::assertInstanceOf(WP_REST_Response::class, $served);
        self::assertSame(405, $served->get_status());
        self::assertSame('GET, HEAD, DELETE', $served->get_headers()['allow'] ?? null);
    }

    public function testTheBrokenJsonErrorOfWordPressIsDroppedBeforeACallbackOfTheLibrary(): void
    {
        $callbacks = new RegisteredCallbacks();
        $filter = $this->filter(callbacks: $callbacks);
        $callback = static function (): null {
            return null;
        };
        $callbacks->record($callback, $this->server()->routes()[0]);
        $invalid = new WP_Error('rest_invalid_json', 'Invalid JSON body passed.', ['status' => 400]);

        self::assertNull($filter->filterRequestBeforeCallbacks(
            $invalid,
            ['callback' => $callback],
            new WP_REST_Request('POST', '/acme/v1/pets'),
        ));
    }

    public function testEveryOtherValueBeforeTheCallbacksPassesUntouched(): void
    {
        $callbacks = new RegisteredCallbacks();
        $filter = $this->filter(callbacks: $callbacks);
        $callback = static function (): null {
            return null;
        };
        $callbacks->record($callback, $this->server()->routes()[0]);
        $request = new WP_REST_Request('POST', '/acme/v1/pets');
        $invalid = new WP_Error('rest_invalid_json', 'Invalid JSON body passed.', ['status' => 400]);
        $other = new WP_Error('rest_missing_callback_param', 'Missing parameter(s): name', ['status' => 400]);
        $foreign = static function (): null {
            return null;
        };

        self::assertSame($invalid, $filter->filterRequestBeforeCallbacks($invalid, ['callback' => $foreign], $request));
        self::assertSame($invalid, $filter->filterRequestBeforeCallbacks($invalid, [], $request));
        self::assertSame($other, $filter->filterRequestBeforeCallbacks($other, ['callback' => $callback], $request));
        self::assertNull($filter->filterRequestBeforeCallbacks(null, ['callback' => $callback], $request));
    }

    public function testEverythingElsePassesUntouched(): void
    {
        $filter = $this->filter();
        $foreign = self::wordPressError(404, 'rest_no_route', 'no');
        $options = new WP_REST_Response(['namespace' => 'acme/v1'], 200);

        self::assertSame(
            $foreign,
            $filter->filterPostDispatch($foreign, new WP_REST_Server(), new WP_REST_Request('GET', '/wp/v2/posts')),
        );
        self::assertSame(
            $options,
            $filter->filterPostDispatch(
                $options,
                new WP_REST_Server(),
                new WP_REST_Request('OPTIONS', '/acme/v1/pets'),
            ),
        );
        self::assertNull($filter->filterPostDispatch(
            null,
            new WP_REST_Server(),
            new WP_REST_Request('GET', '/acme/v1/pets'),
        ));
    }

    /**
     * converting a 401 asks the challenge provider, so a provider that throws breaks the conversion
     */
    public function testAConversionThatFailsLeavesTheWordPressResponseAndIsLogged(): void
    {
        $logger = new RecordingLogger();
        $wordPress = self::wordPressError(401, 'incorrect_password', 'wrong');
        $failing = new readonly class implements ChallengeProvider {
            /**
             * @return never
             */
            #[Override]
            public function challenges(ServerRequest $request, array $schemes): array
            {
                throw new RuntimeException('the challenge provider broke');
            }
        };

        $served = $this->filter($logger, provider: $failing)
            ->filterPostDispatch($wordPress, new WP_REST_Server(), new WP_REST_Request('GET', '/acme/v1/pets'));

        self::assertSame($wordPress, $served);
        self::assertSame(['the challenge provider broke'], array_map(
            static function (Throwable $failure): string {
                return $failure->getMessage();
            },
            $logger->throwables(),
        ));
    }

    public function testTheContentTypeWordPressSentIsRemovedFromAServedResponseWithoutOne(): void
    {
        $bare = $this->served(new Response(304, new Headers(['ETag' => '"1"']), null));
        $typed = $this->served(new Response(200, new Headers(['Content-Type' => self::JSON_API]), ['data' => []]));

        self::assertSame(['Content-Type'], $bare);
        self::assertSame([], $typed);
    }

    public function testAResponseTheFilterDidNotServeIsLeftAlone(): void
    {
        $server = new RecordingRestServer();

        $served = $this->filter()->filterPreServeRequest(
            false,
            new WP_REST_Response(null, 499),
            new WP_REST_Request('GET', '/acme/v1/pets'),
            $server,
        );

        self::assertFalse($served);
        self::assertSame([], $server->removed);
        self::assertSame([], $this->sent);
    }

    public function testAStatusTheTableOfWordPressLacksIsSentByTheFilter(): void
    {
        $this->served(new Response(499, new Headers(['Content-Type' => self::JSON_API]), ['errors' => []]));
        $this->served(new Response(404, new Headers(['Content-Type' => self::JSON_API]), ['errors' => []]));

        self::assertSame([499], $this->sent);
    }

    public function testWhetherTheRequestIsServedIsLeftAsItCame(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $restResponse = self::toWordPress(new Response(204, new Headers(), null));
        $recorded->record($request, $restResponse);
        $filter = $this->filter(recorded: $recorded);
        $served = $filter->filterPostDispatch($restResponse, new WP_REST_Server(), $request);

        self::assertTrue($filter->filterPreServeRequest(true, $served, $request, new RecordingRestServer()));
        self::assertSame('yes', $filter->filterPreServeRequest('yes', $served, $request, new RecordingRestServer()));
    }

    public function testAContentIsWrittenLastPieceByPieceAfterTheBuffersEnded(): void
    {
        [$filter, $request, $served] = $this->servedContent(Content::chunks(["id,name\n", "1,Rex\n"]));

        self::assertTrue($filter->writeContent(false, $served, $request, new RecordingRestServer()));
        self::assertSame(["id,name\n", "1,Rex\n"], $this->written);
        self::assertSame(['endFlush', 'flush', 'flush'], $this->bufferCalls);
    }

    public function testAHeadGetsNoContentAndItsFileIsClosed(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, 'Rex');
        rewind($stream);
        [$filter, $request, $served] = $this->servedContent(Content::stream($stream), 'HEAD');

        self::assertFalse($filter->writeContent(false, $served, $request, new RecordingRestServer()));
        self::assertSame([], $this->written);
        self::assertFalse(is_resource($stream));
    }

    public function testAResponseAnEarlierCallbackServedIsLeftToItAndItsFileIsClosed(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        [$filter, $request, $served] = $this->servedContent(Content::stream($stream));

        self::assertTrue($filter->writeContent(true, $served, $request, new RecordingRestServer()));
        self::assertSame([], $this->written);
        self::assertSame([], $this->bufferCalls);
        self::assertFalse(is_resource($stream));
    }

    public function testAFailureWhileWritingCutsTheContentAndIsLogged(): void
    {
        $logger = new RecordingLogger();
        $rows = static function (): string {
            throw new RuntimeException('the orders cannot be read');
        };
        $content = Content::chunks((static function () use ($rows): Generator {
            yield "id,name\n";
            yield $rows();
        })());
        [$filter, $request, $served] = $this->servedContent($content, 'GET', $logger);

        self::assertTrue($filter->writeContent(false, $served, $request, new RecordingRestServer()));
        self::assertSame(["id,name\n"], $this->written);
        self::assertSame(
            ['the content of the response was cut, a RuntimeException ended it: the orders cannot be read'],
            array_column($logger->records, 'message'),
        );
    }

    public function testAResponseWithoutContentOrNotServedIsLeftToWordPress(): void
    {
        $filter = $this->filter();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $foreign = new WP_REST_Response(Content::bytes('Rex'), 200);
        [$documentFilter, $documentRequest, $document] = $this->servedDocument();

        self::assertFalse($filter->writeContent(false, $foreign, $request, new RecordingRestServer()));
        self::assertFalse($documentFilter->writeContent(false, $document, $documentRequest, new RecordingRestServer()));
        self::assertSame([], $this->written);
    }

    /**
     * @return array{ResponseFilter, WP_REST_Request, mixed}
     */
    private function servedContent(Content $content, string $method = 'GET', ?LoggerInterface $logger = null): array
    {
        $content->open();
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request($method, '/acme/v1/pets/1/photo');
        $restResponse = self::toWordPress(
            new Response(200, new Headers(['Content-Type' => 'text/csv']), null, $content),
        );
        $recorded->record($request, $restResponse);
        $filter = $this->filter(logger: $logger, recorded: $recorded);

        return [$filter, $request, $filter->filterPostDispatch($restResponse, new WP_REST_Server(), $request)];
    }

    /**
     * @return array{ResponseFilter, WP_REST_Request, mixed}
     */
    private function servedDocument(): array
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $restResponse = self::toWordPress(
            new Response(200, new Headers(['Content-Type' => self::JSON_API]), ['data' => []]),
        );
        $recorded->record($request, $restResponse);
        $filter = $this->filter(recorded: $recorded);

        return [$filter, $request, $filter->filterPostDispatch($restResponse, new WP_REST_Server(), $request)];
    }

    private function buffers(): OutputBuffers
    {
        $open = [['name' => 'default output handler', 'flags' => PHP_OUTPUT_HANDLER_STDFLAGS, 'buffer_used' => 0]];

        return new OutputBuffers(
            static function () use (&$open): array {
                return $open;
            },
            function () use (&$open): bool {
                $this->bufferCalls[] = 'endFlush';
                array_pop($open);

                return true;
            },
            function (): void {
                $this->bufferCalls[] = 'flush';
            },
            static function (): bool {
                return false;
            },
        );
    }

    /**
     * the header fields the filter removed
     *
     * @return list<string>
     */
    private function served(Response $response): array
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $restResponse = self::toWordPress($response);
        $recorded->record($request, $restResponse);
        $filter = $this->filter(recorded: $recorded);
        $server = new RecordingRestServer();

        $served = $filter->filterPostDispatch($restResponse, $server, $request);

        self::assertFalse($filter->filterPreServeRequest(false, $served, $request, $server));

        return $server->removed;
    }

    /**
     * @param list<Challenge>       $challenges
     * @param ?list<SecurityScheme> $deleteSchemes
     */
    private function filter(
        ?LoggerInterface $logger = null,
        ?RecordedResponses $recorded = null,
        array $challenges = [],
        ?ChallengeProvider $provider = null,
        ?RegisteredCallbacks $callbacks = null,
        ?OutputBuffers $buffers = null,
        ?array $deleteSchemes = null,
    ): ResponseFilter {
        $logger ??= new RecordingLogger();
        $server = $this->server($logger, $challenges, $provider, $deleteSchemes);

        return new ResponseFilter(
            $server,
            new RoutePatterns(PetsApi::REST_NAMESPACE, $server->routes()),
            $callbacks ?? new RegisteredCallbacks(),
            new RequestFactory(),
            new ResponseFactory(),
            $recorded ?? new RecordedResponses(),
            $logger,
            function (int $status): void {
                $this->sent[] = $status;
            },
            function (string $piece): void {
                $this->written[] = $piece;
            },
            $buffers ?? self::buffers(),
        );
    }

    /**
     * @param list<Challenge>       $challenges
     * @param ?list<SecurityScheme> $deleteSchemes
     */
    private function server(
        ?LoggerInterface $logger = null,
        array $challenges = [],
        ?ChallengeProvider $provider = null,
        ?array $deleteSchemes = null,
    ): Server {
        return Server::compose(
            PetsApi::metadata(PetsApi::store(), PetsApi::permission(true), $deleteSchemes),
            new ContainerImplementationResolver(),
            new RestUrlGenerator(PetsApi::REST_NAMESPACE, static function (string $path): string {
                return PetsApi::REST_ROOT . $path;
            }),
            PetsApi::identity(false),
            $provider ?? PetsApi::challengeProvider($challenges),
            null,
            null,
            [],
            false,
            $logger ?? new RecordingLogger(),
        );
    }

    private static function toWordPress(Response $response): WP_REST_Response
    {
        return (new ResponseFactory())->toWordPress($response);
    }

    /**
     * the shape WordPress writes a `WP_Error` in
     */
    private static function wordPressError(int $status, string $code, string $message): WP_REST_Response
    {
        return new WP_REST_Response(['code' => $code, 'message' => $message, 'data' => ['status' => $status]], $status);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function firstError(WP_REST_Response $response): array
    {
        $data = $response->get_data();

        self::assertIsArray($data);
        self::assertIsArray($data['errors'] ?? null);
        self::assertIsArray($data['errors'][0] ?? null);

        return $data['errors'][0];
    }
}
