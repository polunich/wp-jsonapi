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

namespace Polunich\WpJsonApi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\Response;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(Response::class))->getDocComment(),
        );
    }

    public function testTheClassAndEveryMemberAreReadonly(): void
    {
        $class = new ReflectionClass(Response::class);

        self::assertTrue($class->isReadOnly());

        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is not readonly');
        }
    }

    /**
     * an empty array would be a document without members; a 204 has no document at all
     */
    public function testNoBodyIsNullAndNotAnEmptyArray(): void
    {
        $response = new Response(204, new Headers(), null);

        self::assertNull($response->document);
        self::assertNotSame([], $response->document);
    }

    public function testTheBodyKeepsItsMemberOrder(): void
    {
        $body = [
            'jsonapi' => ['version' => '1.1'],
            'links' => ['self' => 'https://a.example/wp-json/ns/pets'],
            'data' => [],
        ];

        self::assertSame($body, (new Response(200, new Headers(), $body))->document);
        self::assertSame(
            ['jsonapi', 'links', 'data'],
            array_keys((new Response(200, new Headers(), $body))->document ?? []),
        );
    }

    public function testTheBodyIsWrittenAsAJsonObject(): void
    {
        $response = new Response(200, new Headers(), [
            'jsonapi' => ['version' => '1.1'],
            'data' => null,
        ]);

        self::assertSame(
            '{"jsonapi":{"version":"1.1"},"data":null}',
            json_encode($response->document, JSON_THROW_ON_ERROR),
        );
    }

    public function testAnEmptyObjectInsideTheBodyIsWrittenAsAnObject(): void
    {
        $response = new Response(200, new Headers(), [
            'data' => ['type' => 'pets', 'id' => '1', 'attributes' => (object) ['tags' => new stdClass()]],
            'meta' => new stdClass(),
        ]);

        self::assertSame(
            '{"data":{"type":"pets","id":"1","attributes":{"tags":{}}},"meta":{}}',
            json_encode($response->document, JSON_THROW_ON_ERROR),
        );
    }

    public function testAMemberNameOfDecimalDigitsSurvivesInsideTheBody(): void
    {
        $meta = (object) ['0' => 'x', '1' => 'y'];
        $response = new Response(200, new Headers(), ['meta' => $meta]);

        self::assertSame('{"meta":{"0":"x","1":"y"}}', json_encode($response->document, JSON_THROW_ON_ERROR));
        self::assertSame('{"meta":["x","y"]}', json_encode(['meta' => ['0' => 'x', '1' => 'y']], JSON_THROW_ON_ERROR));
    }

    public function testTheStatusAndTheHeadersAreTheOnesGiven(): void
    {
        $headers = (new Headers())->with('content-type', 'application/vnd.api+json')->withAdded('vary', 'Accept');
        $response = new Response(201, $headers, ['data' => []]);

        self::assertSame(201, $response->status);
        self::assertSame($headers, $response->headers);
    }
}
