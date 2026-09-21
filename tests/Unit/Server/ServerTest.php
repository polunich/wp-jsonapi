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
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Error\ExceptionMappers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\OpenApi\OpenApiBuilder;
use Polunich\WpJsonApi\Rendering\ErrorResponseBuilder;
use Polunich\WpJsonApi\Server\AtomicPipeline;
use Polunich\WpJsonApi\Server\EndpointPipeline;
use Polunich\WpJsonApi\Server\OperationPipeline;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use Polunich\WpJsonApi\Tests\Fixtures\Server\JournaledServerTestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * the shape of the server: its class, its signature, its routes and its contract
 *
 * @internal
 */
#[CoversClass(Server::class)]
final class ServerTest extends JournaledServerTestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(Server::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * the private `failure()` is pinned too:
     * its non-nullable route is what gives a 405 of the flow its Allow field
     */
    public function testTheConstructorAndTheMethodsKeepTheirParametersAndTypes(): void
    {
        $constructor = (new ReflectionMethod(Server::class, '__construct'))->getParameters();
        $authorize = new ReflectionMethod(Server::class, 'authorize');
        $handle = new ReflectionMethod(Server::class, 'handle');
        $foreign = new ReflectionMethod(Server::class, 'convertForeignError');
        $failure = new ReflectionMethod(Server::class, 'failure');

        self::assertSame([
            'api',
            'gate',
            'operations',
            'atomic',
            'endpoints',
            'errorResponses',
            'mappers',
            'denials',
            'openApi',
            'logger',
        ], ParameterNames::of($constructor));
        self::assertSame([
            ApiMetadata::class,
            Gate::class,
            OperationPipeline::class,
            AtomicPipeline::class,
            EndpointPipeline::class,
            ErrorResponseBuilder::class,
            ExceptionMappers::class,
            DenialPolicy::class,
            OpenApiBuilder::class,
            LoggerInterface::class,
        ], self::types($constructor));

        self::assertSame(['route', 'request'], ParameterNames::of($authorize->getParameters()));
        self::assertSame([Route::class, ServerRequest::class], self::types($authorize->getParameters()));
        self::assertSame('?' . Response::class, (string) $authorize->getReturnType());

        self::assertSame(['route', 'request'], ParameterNames::of($handle->getParameters()));
        self::assertSame([Route::class, ServerRequest::class], self::types($handle->getParameters()));
        self::assertSame(Response::class, (string) $handle->getReturnType());

        self::assertSame(
            ['status', 'code', 'message', 'request', 'route'],
            ParameterNames::of($foreign->getParameters()),
        );
        self::assertSame(
            ['int', 'string', 'string', ServerRequest::class, '?' . Route::class],
            self::types($foreign->getParameters()),
        );
        self::assertNull($foreign->getParameters()[4]->getDefaultValue());
        self::assertSame(Response::class, (string) $foreign->getReturnType());

        self::assertSame(['failure', 'request', 'contentType', 'route'], ParameterNames::of($failure->getParameters()));
        self::assertSame(
            [Throwable::class, ServerRequest::class, '?' . MediaType::class, Route::class],
            self::types($failure->getParameters()),
        );
    }

    public function testTheRoutesAreTheOnesOfTheRouteTable(): void
    {
        $api = self::api(self::registry(), true);

        self::assertEquals($api->routes->all(), self::server(self::journal())->routes());
    }

    public function testTheOpenApiJsonIsTheOneTheBuilderWrites(): void
    {
        $api = self::api(self::registry(), true);

        self::assertSame((new OpenApiBuilder($api))->json(), self::server(self::journal())->openApiJson());
    }
}
