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
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\RecordedResponses;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @internal
 */
#[CoversClass(RecordedResponses::class)]
final class RecordedResponsesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreFiles::load();
    }

    public function testTheClassIsFinalMutableAndInternal(): void
    {
        $class = new ReflectionClass(RecordedResponses::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * keyed by identity: an equal request object is another request
     */
    public function testAResponseIsReadBackForItsRequestAlone(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $response = self::response(200);

        $recorded->record($request, $response);

        self::assertSame($response, $recorded->responseTo($request));
        self::assertNull($recorded->standInOf($request));
        self::assertNull($recorded->responseTo(new WP_REST_Request('GET', '/acme/v1/pets')));
    }

    public function testTheStandInOfARefusalIsReadBackWithItsResponse(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('DELETE', '/acme/v1/pets/1');
        $refusal = self::response(403);
        $standIn = new WP_Error('forbidden', 'Forbidden', ['status' => 403]);

        $recorded->record($request, $refusal, $standIn);

        self::assertSame($refusal, $recorded->responseTo($request));
        self::assertSame($standIn, $recorded->standInOf($request));
        self::assertNull($recorded->standInOf(new WP_REST_Request('DELETE', '/acme/v1/pets/1')));
    }

    public function testALaterResponseReplacesAnEarlierOne(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');
        $later = self::response(204);

        $recorded->record($request, self::response(403), new WP_Error('forbidden', 'Forbidden', ['status' => 403]));
        $recorded->record($request, $later);

        self::assertSame($later, $recorded->responseTo($request));
        self::assertNull($recorded->standInOf($request));
    }

    public function testAResponseLeavesWithItsRequest(): void
    {
        $recorded = new RecordedResponses();
        $request = new WP_REST_Request('GET', '/acme/v1/pets');

        $recorded->record($request, self::response(200));
        unset($request);

        $map = (new ReflectionProperty(RecordedResponses::class, 'responses'))->getValue($recorded);

        self::assertInstanceOf(WeakMap::class, $map);
        self::assertCount(0, $map);
    }

    private static function response(int $status): WP_REST_Response
    {
        return new WP_REST_Response(null, $status);
    }
}
