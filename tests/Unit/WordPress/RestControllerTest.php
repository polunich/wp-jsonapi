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

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\ContainerImplementationResolver;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\ErrorLog;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use Polunich\WpJsonApi\WordPress\RestController;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @internal
 */
#[CoversClass(RestController::class)]
final class RestControllerTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RestController::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testARequestLevelOneLetsThroughIsAuthorizedWithTrue(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $server = PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger());

        self::assertTrue(self::controller(
            $server,
            $recorded,
        )->authorize(self::route(OperationKind::FetchCollection), $request));
        self::assertNull($recorded->responseTo($request));
    }

    public function testARefusalIsTheLibrarysWpErrorAndStandsInForItsResponse(): void
    {
        $recorded = new RecordedResponses();
        $request = self::delete('1');
        $server = PetsApi::server(PetsApi::store(), PetsApi::permission(false), new RecordingLogger());

        $error = self::controller($server, $recorded)->authorize(self::route(OperationKind::DeleteResource), $request);
        $refusal = $recorded->responseTo($request);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame($error, $recorded->standInOf($request));
        self::assertInstanceOf(WP_REST_Response::class, $refusal);
        self::assertSame(403, $refusal->get_status());
        self::assertSame(self::JSON_API, $refusal->get_headers()['content-type'] ?? null);

        $data = $refusal->get_data();

        self::assertIsArray($data);

        $first = self::firstError($data);

        self::assertSame('forbidden', $first['code'] ?? null);
        self::assertSame('forbidden', $error->get_error_code());
        self::assertSame($first['title'] ?? null, $error->get_error_message());
        self::assertSame(['status' => 403], $error->get_error_data());
    }

    public function testTheCallbackReturnsTheResponseOfTheLibraryAndRecordsIt(): void
    {
        $store = PetsApi::store();
        $recorded = new RecordedResponses();
        $request = self::delete('1');
        $controller = self::controller(
            PetsApi::server($store, PetsApi::permission(true), new RecordingLogger()),
            $recorded,
        );

        $response = $controller->handle(self::route(OperationKind::DeleteResource), $request);

        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(204, $response->get_status());
        self::assertSame($response, $recorded->responseTo($request));
        self::assertNull($recorded->standInOf($request));
        self::assertNull($store->find('1'));
    }

    public function testAThrowableThatLeavesTheLibraryIsAFiveHundredAndIsLogged(): void
    {
        $recorded = new RecordedResponses();
        $logger = new RecordingLogger();
        $request = self::delete('1');

        $error = self::controller(
            self::brokenServer($logger),
            $recorded,
            $logger,
        )->authorize(self::route(OperationKind::DeleteResource), $request);

        self::assertInstanceOf(WP_Error::class, $error);
        self::assertSame('internal_error', $error->get_error_code());
        self::assertSame(['status' => 500], $error->get_error_data());
        self::assertNull($recorded->responseTo($request));
        self::assertCount(1, $logger->throwables());
        self::assertSame('the challenge provider broke', $logger->throwables()[0]->getMessage());
    }

    /**
     * the logger fails while `Server::handle()` logs the 5xx,
     * so its own throwable leaves the library, and it fails again in the controller
     */
    public function testALoggerThatThrowsLeavesBothThrowablesToErrorLog(): void
    {
        $logger = new RecordingLogger(fails: true);
        $failure = new RuntimeException('the permission broke');
        $controller = self::controller(
            PetsApi::server(PetsApi::store(), self::throwing($failure), $logger),
            new RecordedResponses(),
            $logger,
        );
        $response = null;

        $logged = ErrorLog::capturedWhile(static function () use ($controller, &$response): void {
            $response = $controller->handle(self::route(OperationKind::DeleteResource), self::delete('1'));
        });

        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(['status' => 500], $response->get_error_data());
        self::assertStringContainsString(
            'the library returned no response, a RuntimeException left it: the logger broke',
            $logged,
        );
        self::assertStringContainsString(
            'the logger of the library failed too, a RuntimeException: the logger broke',
            $logged,
        );
    }

    private static function controller(
        Server $server,
        RecordedResponses $recorded,
        ?LoggerInterface $logger = null,
    ): RestController {
        return new RestController(
            $server,
            new RequestFactory(),
            new ResponseFactory(),
            $recorded,
            $logger ?? new RecordingLogger(),
        );
    }

    private static function route(OperationKind $kind): Route
    {
        return new OperationRoute(
            $kind,
            PetStore::TYPE,
            null,
            $kind->route()->addressesId() ? new PathParameter('id', new DigitsCodec()) : null,
            [PetStore::TYPE],
        );
    }

    private static function delete(string $id): WP_REST_Request
    {
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/' . $id);
        $request->set_url_params(['id' => $id]);

        return $request;
    }

    /**
     * a permission that raises a 401 and a challenge provider that throws,
     * whose throwable leaves `Server::authorize()`
     */
    private static function brokenServer(LoggerInterface $logger): Server
    {
        return Server::compose(
            PetsApi::metadata(
                PetsApi::store(),
                self::throwing(JsonApiException::ofStatus(401, [new ErrorDescription('wp_rest_unauthorized')])),
            ),
            new ContainerImplementationResolver(),
            new RestUrlGenerator(PetsApi::REST_NAMESPACE, static function (string $path): string {
                return PetsApi::REST_ROOT . $path;
            }),
            PetsApi::identity(false),
            new readonly class implements ChallengeProvider {
                /**
                 * @return never
                 */
                #[Override]
                public function challenges(ServerRequest $request, array $schemes): array
                {
                    throw new RuntimeException('the challenge provider broke');
                }
            },
            null,
            null,
            [],
            false,
            $logger,
        );
    }

    private static function throwing(Throwable $failure): OperationPermission
    {
        return new readonly class ($failure) implements OperationPermission {
            public function __construct(private Throwable $failure) {}

            #[Override]
            public function allows(OperationAttempt $attempt): bool
            {
                throw $this->failure;
            }
        };
    }

    /**
     * @param array<array-key, mixed> $body
     *
     * @return array<array-key, mixed>
     */
    private static function firstError(array $body): array
    {
        $errors = $body['errors'] ?? null;

        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);

        return $errors[0];
    }
}
