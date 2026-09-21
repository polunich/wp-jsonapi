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

namespace Polunich\WpJsonApi\Tests\Unit\Endpoint;

use ArrayIterator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Endpoint\ResponseKind;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Store\Slice;
use ReflectionClass;
use ReflectionMethod;
use stdClass;

/**
 * @internal
 */
#[CoversClass(Response::class)]
final class ResponseTest extends TestCase
{
    public function testTheClassIsAPublicFinalReadonlyValueBuiltByItsKinds(): void
    {
        $class = new ReflectionClass(Response::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
        self::assertSame(
            [
                'resource',
                'created',
                'collection',
                'page',
                'linkage',
                'meta',
                'accepted',
                'noContent',
                'content',
                'json',
                'mediaType',
                'attachmentName',
                'kind',
                'payload',
            ],
            array_map(static function (ReflectionMethod $method): string {
                return $method->getName();
            }, $class->getMethods(ReflectionMethod::IS_PUBLIC)),
        );

        foreach (['mediaType', 'attachmentName', 'kind', 'payload'] as $internal) {
            self::assertMatchesRegularExpression(
                '/@internal\b/',
                (string) $class->getMethod($internal)->getDocComment(),
                $internal,
            );
        }
    }

    #[DataProvider('responses')]
    public function testEachKindCarriesItsStatusAndPayload(
        Response $response,
        ResponseKind $kind,
        int $status,
        mixed $payload,
    ): void {
        self::assertSame($kind, $response->kind());
        self::assertSame($status, $response->kind()->status());
        self::assertEquals($payload, $response->payload());
    }

    /**
     * @return iterable<string, array{Response, ResponseKind, int, mixed}>
     */
    public static function responses(): iterable
    {
        $pet = new stdClass();

        yield 'a resource' => [Response::resource($pet), ResponseKind::Resource, 200, $pet];
        yield 'no resource' => [Response::resource(null), ResponseKind::Resource, 200, null];
        yield 'a created resource' => [Response::created($pet), ResponseKind::Created, 201, $pet];
        yield 'a collection' => [Response::collection([$pet]), ResponseKind::Collection, 200, [$pet]];
        yield 'a page' => [Response::page(new Slice([$pet], 7)), ResponseKind::Page, 200, new Slice([$pet], 7)];
        yield 'a to-one linkage' => [Response::linkage($pet), ResponseKind::Linkage, 200, $pet];
        yield 'an empty to-one linkage' => [Response::linkage(null), ResponseKind::Linkage, 200, null];
        yield 'a to-many linkage' => [Response::linkage([$pet]), ResponseKind::Linkage, 200, [$pet]];
        yield 'meta' => [Response::meta(['count' => 3]), ResponseKind::Meta, 200, ['count' => 3]];
        yield 'a monitor' => [Response::accepted($pet), ResponseKind::Accepted, 202, $pet];
        yield 'no content' => [Response::noContent(), ResponseKind::NoContent, 204, null];
        yield 'a JSON value' => [
            Response::json(['received' => true], 'application/json'),
            ResponseKind::Json,
            200,
            ['received' => true],
        ];
    }

    public function testAContentCarriesItsMediaTypeAndTheNameToSaveItUnder(): void
    {
        $content = Content::bytes("id,name\n1,Rex\n");
        $response = Response::content($content, 'text/csv', 'pets-2026.csv');

        self::assertSame(ResponseKind::Content, $response->kind());
        self::assertSame(200, $response->kind()->status());
        self::assertSame($content, $response->payload());
        self::assertSame('text/csv', $response->mediaType());
        self::assertSame('pets-2026.csv', $response->attachmentName());
        self::assertNull(Response::content($content, 'text/csv')->attachmentName());
        self::assertSame('application/json', Response::json(1, 'application/json')->mediaType());
        self::assertNull(Response::noContent()->mediaType());
    }

    #[DataProvider('attachmentNames')]
    public function testAnAttachmentNameIsAFileNameInUtf8(string $name, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'an attachment name is a file name in UTF-8, neither empty nor holding a control character, got "%s"',
            $shown,
        ));

        Response::content(Content::bytes(''), 'text/csv', $name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function attachmentNames(): iterable
    {
        yield 'an empty name' => ['', ''];
        yield 'a line feed' => ["pets\n.csv", 'pets\\n.csv'];
        yield 'no UTF-8' => ["caf\xe9.csv", "caf\xe9.csv"];
        yield 'a delete character' => ["pets\x7F.csv", 'pets\\177.csv'];
    }

    public function testAnyIterableIsReadIntoAListOnce(): void
    {
        $first = new stdClass();
        $second = new stdClass();

        self::assertSame(
            [$first, $second],
            Response::collection(new ArrayIterator(['a' => $first, 'b' => $second]))->payload(),
        );
        self::assertSame([$first], Response::linkage(new ArrayIterator([7 => $first]))->payload());
    }

    public function testAListHoldsRecordsAlone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a response lists records, the objects of the project a type reads, and item 1'
            . ' is string');

        Response::collection([new stdClass(), 'pet']);
    }
}
