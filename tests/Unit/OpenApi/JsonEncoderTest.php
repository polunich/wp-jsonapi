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

namespace Polunich\WpJsonApi\Tests\Unit\OpenApi;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\OpenApi\JsonEncoder;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(JsonEncoder::class)]
final class JsonEncoderTest extends TestCase
{
    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(JsonEncoder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    /**
     * the path pins the unescaped slash, `\u{00e9}` the `\uXXXX` escape
     */
    public function testWritesTheExactBytesOfADocument(): void
    {
        $document = (object) [
            'openapi' => '3.1.2',
            'paths' => (object) [
                '/pets/{id}' => (object) [
                    'get' => (object) [
                        'responses' => (object) [
                            200 => (object) ['description' => "a resource of type \"p\u{00e9}ts\""],
                        ],
                    ],
                ],
            ],
            'tags' => [(object) ['name' => 'pets']],
        ];

        self::assertSame(
            '{
    "openapi": "3.1.2",
    "paths": {
        "/pets/{id}": {
            "get": {
                "responses": {
                    "200": {
                        "description": "a resource of type \"p\u00e9ts\""
                    }
                }
            }
        }
    },
    "tags": [
        {
            "name": "pets"
        }
    ]
}
',
            (new JsonEncoder())->encode($document),
        );
    }

    public function testKeepsTheOrderTheBuildersWrote(): void
    {
        $encoder = new JsonEncoder();

        self::assertSame("{\n    \"a\": 1,\n    \"b\": 2\n}\n", $encoder->encode((object) ['a' => 1, 'b' => 2]));
        self::assertSame("{\n    \"b\": 2,\n    \"a\": 1\n}\n", $encoder->encode((object) ['b' => 2, 'a' => 1]));
    }

    /**
     * int keys that are no list encode as an object, which quotes the statuses as OpenAPI requires
     */
    public function testWritesAMapOfStatusesAsAnObject(): void
    {
        $document = (object) ['responses' => [
            200 => (object) ['description' => 'a'],
            404 => (object) ['description' => 'b'],
        ]];

        self::assertSame(
            '{
    "responses": {
        "200": {
            "description": "a"
        },
        "404": {
            "description": "b"
        }
    }
}
',
            (new JsonEncoder())->encode($document),
        );
    }

    public function testWritesAnEmptyListAndAnEmptyObjectApart(): void
    {
        $document = (object) ['security' => [new stdClass()], 'servers' => []];

        self::assertSame(
            "{\n    \"security\": [\n        {}\n    ],\n    \"servers\": []\n}\n",
            (new JsonEncoder())->encode($document),
        );
    }

    public function testWritesTheSameFloatsWhateverTheSiteSetsSerializePrecisionTo(): void
    {
        $document = (object) ['minimum' => -PHP_FLOAT_MAX, 'maximum' => PHP_FLOAT_MAX, 'multipleOf' => 0.1];
        $previous = ini_set('serialize_precision', '15');

        self::assertIsString($previous);

        try {
            self::assertSame(
                "{\n    \"minimum\": -1.7976931348623157e+308,"
                . "\n    \"maximum\": 1.7976931348623157e+308,"
                . "\n    \"multipleOf\": 0.1\n}\n",
                (new JsonEncoder())->encode($document),
            );
            self::assertSame('15', ini_get('serialize_precision'));
        } finally {
            ini_set('serialize_precision', $previous);
        }
    }

    public function testRefusesAStringThatIsNoUtf8(): void
    {
        $this->expectException(JsonException::class);

        (new JsonEncoder())->encode((object) ['description' => "\xB1\x31"]);
    }
}
