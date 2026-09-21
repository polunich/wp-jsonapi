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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Parsing\JsonDocument;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(JsonDocument::class)]
final class JsonDocumentTest extends TestCase
{
    #[DataProvider('membersOfADecodedObject')]
    public function testFindsEveryMemberTheObjectCarries(string $name, bool $carried, mixed $value): void
    {
        $object = self::object('{"0": "zero", "12": null, "a": 1, "": "empty"}');

        self::assertSame($carried, JsonDocument::has($object, $name));
        self::assertSame($value, JsonDocument::member($object, $name));
    }

    /**
     * @return iterable<string, array{string, bool, mixed}>
     */
    public static function membersOfADecodedObject(): iterable
    {
        yield 'a name of one digit' => ['0', true, 'zero'];

        yield 'a name of two digits' => ['12', true, null];

        yield 'a name of letters' => ['a', true, 1];

        yield 'the empty name' => ['', true, 'empty'];

        yield 'a name the object does not carry' => ['3', false, null];
    }

    public function testAMemberWithTheValueNullIsOneTheObjectCarries(): void
    {
        $object = self::object('{"data": null}');

        self::assertTrue(JsonDocument::has($object, 'data'));
        self::assertNull(JsonDocument::member($object, 'data'));
        self::assertFalse(JsonDocument::has($object, 'meta'));
    }

    public function testAMalformedDocumentGetsTheGenericCodeOfItsStatus(): void
    {
        $errors = new ErrorList(new StatusSelector());

        JsonDocument::malformed($errors, Pointer::root()->descend('data')->descend('relationships'));

        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(400, $exception->status());
        self::assertSame(ErrorCode::BadRequest, $exception->descriptions()[0]->code);
        self::assertSame([], $exception->descriptions()[0]->parameters);
        self::assertSame('/data/relationships', $exception->descriptions()[0]->pointer);
    }

    /**
     * each error keeps its own status;
     * `StatusSelector` merges 422 and 409 into the 400 of the response
     */
    public function testEveryRefusalIsItsOwnProblemWithItsOwnStatus(): void
    {
        $errors = new ErrorList(new StatusSelector());

        JsonDocument::refuse(
            $errors,
            JsonDocument::UNPROCESSABLE_CONTENT,
            ErrorCode::UnknownField,
            ['type' => 'pets', 'field' => 'colour'],
            Pointer::root()->descend('data')->descend('attributes')->descend('colour'),
        );
        JsonDocument::refuse(
            $errors,
            JsonDocument::CONFLICT,
            ErrorCode::TypeMismatch,
            ['type' => 'customers'],
            Pointer::root()->descend('data')->descend('type'),
        );

        $exception = $errors->exception();

        self::assertNotNull($exception);
        self::assertSame(400, $exception->status());
        self::assertSame(
            [ErrorCode::UnknownField, ErrorCode::TypeMismatch],
            array_map(
                static function (object $description): mixed {
                    return $description->code;
                },
                $exception->descriptions(),
            ),
        );
        self::assertSame([422, 409], array_map(
            static function (object $description): mixed {
                return $description->status;
            },
            $exception->descriptions(),
        ));
    }

    public function testTheStatusesAreTheOnesADocumentIsRefusedWith(): void
    {
        self::assertSame(400, JsonDocument::BAD_REQUEST);
        self::assertSame(403, JsonDocument::FORBIDDEN);
        self::assertSame(404, JsonDocument::NOT_FOUND);
        self::assertSame(409, JsonDocument::CONFLICT);
        self::assertSame(422, JsonDocument::UNPROCESSABLE_CONTENT);
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(JsonDocument::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    private static function object(string $json): stdClass
    {
        $object = json_decode($json, false, flags: JSON_THROW_ON_ERROR);

        self::assertInstanceOf(stdClass::class, $object);

        return $object;
    }
}
