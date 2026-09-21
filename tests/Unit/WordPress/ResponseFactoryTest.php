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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\OutputBuffers;
use Polunich\WpJsonApi\WordPress\ResponseFactory;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResponseFactory::class)]
final class ResponseFactoryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResponseFactory::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheStatusAndTheContentAreTheLibrarys(): void
    {
        $body = ['jsonapi' => ['version' => '1.1'], 'data' => []];

        $response = (new ResponseFactory())->toWordPress(new Response(200, new Headers(), $body));

        self::assertSame(200, $response->get_status());
        self::assertSame($body, $response->get_data());
    }

    public function testTheLinesOfAFieldAreJoinedIntoOne(): void
    {
        $headers = new Headers([
            'Content-Type' => 'application/vnd.api+json',
            'WWW-Authenticate' => ['Basic realm="WordPress"', 'Bearer'],
            'Vary' => 'Accept, Accept-Language',
        ]);

        $response = (new ResponseFactory())->toWordPress(new Response(401, $headers, null));

        self::assertSame([
            'content-type' => 'application/vnd.api+json',
            'www-authenticate' => 'Basic realm="WordPress", Bearer',
            'vary' => 'Accept, Accept-Language',
        ], $response->get_headers());
    }

    public function testAContentIsTheDataWithItsLengthWhereNothingBetweenChangesIt(): void
    {
        $content = Content::bytes("id,name\n1,Rex\n");

        $response = (new ResponseFactory(self::buffers(keep: true)))->toWordPress(new Response(
            200,
            new Headers(['Content-Type' => 'text/csv']),
            null,
            $content,
        ));

        self::assertSame($content, $response->get_data());
        self::assertSame(['content-type' => 'text/csv', 'Content-Length' => '14'], $response->get_headers());
    }

    public function testAContentGetsNoLengthWhereSomethingBetweenMayChangeIt(): void
    {
        $response = (new ResponseFactory(self::buffers(keep: false)))->toWordPress(new Response(
            200,
            new Headers(),
            null,
            Content::bytes('Rex'),
        ));

        self::assertArrayNotHasKey('Content-Length', $response->get_headers());
    }

    public function testAContentOfUnknownSizeGetsNoLength(): void
    {
        $response = (new ResponseFactory(self::buffers(keep: true)))->toWordPress(new Response(
            200,
            new Headers(),
            null,
            Content::chunks(['Rex']),
        ));

        self::assertArrayNotHasKey('Content-Length', $response->get_headers());
    }

    public function testAResponseWithoutContentStaysOne(): void
    {
        $response = (new ResponseFactory())->toWordPress(new Response(204, new Headers(), null));

        self::assertSame(204, $response->get_status());
        self::assertNull($response->get_data());
    }

    public function testTheResponseCarriesNoMatchedRoute(): void
    {
        $response = (new ResponseFactory())->toWordPress(new Response(200, new Headers(), null));

        self::assertSame('', $response->get_matched_route());
    }

    private static function buffers(bool $keep): OutputBuffers
    {
        return new OutputBuffers(
            static function (): array {
                return [];
            },
            static function (): bool {
                return true;
            },
            static function (): void {},
            static function () use ($keep): bool {
                return !$keep;
            },
        );
    }
}
