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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Parsing\JsonBodyDecoder;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(JsonBodyDecoder::class)]
final class JsonBodyDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesThatAreNoJsonText(): iterable
    {
        yield 'no body at all' => [''];
        yield 'whitespace' => [' '];
        yield 'a syntax error' => ['{"data":}'];
        yield 'an unterminated object' => ['{"data":{'];
        yield 'two values' => ['{} {}'];
        yield 'a trailing comma' => ['{"data":1,}'];
        yield 'bytes that are no UTF-8' => ["{\"data\":\"\xff\"}"];
        yield 'nested deeper than json_decode() reads' => [str_repeat('[', 512) . str_repeat(']', 512)];
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(JsonBodyDecoder::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testAJsonObjectBecomesAnObjectAndAJsonArrayAList(): void
    {
        $body = new JsonBodyDecoder();

        self::assertInstanceOf(stdClass::class, $body->decode('{}'));
        self::assertSame([], $body->decode('[]'));
        self::assertSame(['a', 'b'], $body->decode('["a","b"]'));
    }

    /**
     * `get_object_vars()` reports the member `"0"` under the int key `0`,
     * hence the bare `'x'` in the expected array
     */
    public function testAMemberNamedWithDigitsStaysAMember(): void
    {
        $decoded = (new JsonBodyDecoder())->decode('{"0":"x","a":1}');

        self::assertInstanceOf(stdClass::class, $decoded);
        self::assertSame(['x', 'a' => 1], get_object_vars($decoded));
    }

    /**
     * any serialized value is a JSON text (RFC 8259, section 2), so the decoder refuses no scalar
     */
    public function testAScalarBodyIsReadAsTheValueItWrites(): void
    {
        $body = new JsonBodyDecoder();

        self::assertNull($body->decode('null'));
        self::assertTrue($body->decode('true'));
        self::assertSame(1, $body->decode('1'));
        self::assertSame(1.5, $body->decode('1.5'));
        self::assertSame('text', $body->decode('"text"'));
    }

    #[DataProvider('bodiesThatAreNoJsonText')]
    public function testABodyThatIsNoJsonTextIsRefusedWith400(string $body): void
    {
        try {
            (new JsonBodyDecoder())->decode($body);
        } catch (JsonApiException $exception) {
            self::assertSame(400, $exception->status());
            self::assertCount(1, $exception->descriptions());

            $description = $exception->descriptions()[0];

            self::assertSame(ErrorCode::InvalidJson, $description->code);
            self::assertSame([], $description->parameters);
            self::assertNull($description->pointer);
            self::assertNull($description->parameter);
            self::assertNull($description->header);

            return;
        }

        self::fail('a body that is no JSON text gets 400');
    }

    public function testTheRefusalOfTheDecoderIsKeptForTheLog(): void
    {
        try {
            (new JsonBodyDecoder())->decode('{');
        } catch (JsonApiException $exception) {
            self::assertInstanceOf(JsonException::class, $exception->getPrevious());

            return;
        }

        self::fail('a body that is no JSON text gets 400');
    }

    /**
     * 511 nested arrays are the deepest `json_decode()` reads at its default depth of 512
     */
    public function testABodyNestedToTheDepthTheDecoderReadsIsRead(): void
    {
        $decoded = (new JsonBodyDecoder())->decode(str_repeat('[', 511) . str_repeat(']', 511));

        self::assertIsArray($decoded);
    }
}
