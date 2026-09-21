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

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use RuntimeException;

/**
 * `Server::compose()`: the whole graph the library builds, over the ports it was given
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerComposeTest extends JournaledServerTestCase
{
    public function testComposeRespondsWithACollectionThroughTheWholeGraph(): void
    {
        $server = PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger());
        $route = new OperationRoute(OperationKind::FetchCollection, PetStore::TYPE, null, null, [PetStore::TYPE]);
        $request = new ServerRequest('GET', new Headers(), [], [], '');

        self::assertNull($server->authorize($route, $request));

        $response = $server->handle($route, $request);

        self::assertSame(200, $response->status);
        self::assertSame(self::JSON_API, $response->headers->line('Content-Type'));
        self::assertNotNull($response->document);

        $data = $response->document['data'] ?? null;

        self::assertIsArray($data);
        self::assertSame(['1', '2'], array_map(
            static function (mixed $resource): mixed {
                self::assertIsArray($resource);

                return $resource['id'] ?? null;
            },
            $data,
        ));
        self::assertSame(PetsApi::REST_ROOT . '/' . PetsApi::REST_NAMESPACE . '/pets', self::selfLink($response));
    }

    public function testComposeRespondsToADeleteWithNoContent(): void
    {
        $store = PetsApi::store();
        $server = PetsApi::server($store, PetsApi::permission(true), new RecordingLogger());
        $route = new OperationRoute(
            OperationKind::DeleteResource,
            PetStore::TYPE,
            null,
            new PathParameter(self::ID_PARAMETER, new DigitsCodec()),
            [PetStore::TYPE],
        );
        $request = new ServerRequest('DELETE', new Headers(), [], [self::ID_PARAMETER => '1'], '');

        self::assertNull($server->authorize($route, $request));

        $response = $server->handle($route, $request);

        self::assertSame(204, $response->status);
        self::assertNull($response->document);
        self::assertNull($store->find('1'));
    }

    public function testComposeAsksTheIdentityAndTheChallengeProviderItWasGiven(): void
    {
        $server = PetsApi::server(
            PetsApi::store(),
            PetsApi::permission(false),
            new RecordingLogger(),
            authenticated: false,
            challenges: [new Challenge('Basic', ['realm' => 'WordPress'])],
        );

        $response = $server->authorize(
            new OperationRoute(
                OperationKind::DeleteResource,
                PetStore::TYPE,
                null,
                new PathParameter(self::ID_PARAMETER, new DigitsCodec()),
                [PetStore::TYPE],
            ),
            new ServerRequest('DELETE', new Headers(), [], [self::ID_PARAMETER => '1'], ''),
        );

        self::assertNotNull($response);
        self::assertSame(401, $response->status);
        self::assertSame(['Basic realm="WordPress"'], self::challengesOf($response));
    }

    public function testComposeWritesTheThrowableOfAFiveHundredToTheLoggerItWasGiven(): void
    {
        $logger = new RecordingLogger();
        $failure = new RuntimeException('the permission broke');
        $server = PetsApi::server(
            PetsApi::store(),
            new readonly class ($failure) implements OperationPermission {
                public function __construct(private RuntimeException $failure) {}

                #[Override]
                public function allows(OperationAttempt $attempt): bool
                {
                    throw $this->failure;
                }
            },
            $logger,
        );

        $response = $server->authorize(
            new OperationRoute(
                OperationKind::DeleteResource,
                PetStore::TYPE,
                null,
                new PathParameter(self::ID_PARAMETER, new DigitsCodec()),
                [PetStore::TYPE],
            ),
            new ServerRequest('DELETE', new Headers(), [], [self::ID_PARAMETER => '1'], ''),
        );

        self::assertNotNull($response);
        self::assertSame(500, $response->status);
        self::assertSame([$failure], $logger->throwables());
    }
}
