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

namespace Polunich\WpJsonApi\Tests\Unit\Error;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ErrorDescription::class)]
final class ErrorDescriptionTest extends TestCase
{
    /**
     * an exception and an error list share one description, which must not change under them
     */
    public function testTheDescriptionIsAFinalReadonlyValue(): void
    {
        $reflection = new ReflectionClass(ErrorDescription::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
    }

    /**
     * a project passes them as named arguments, so the names are public API
     */
    public function testTheConstructorKeepsTheNamesOfItsTenParameters(): void
    {
        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            (new ReflectionMethod(ErrorDescription::class, '__construct'))->getParameters(),
        );

        self::assertSame(
            ['code', 'parameters', 'pointer', 'parameter', 'header', 'meta', 'typeLink', 'title', 'detail', 'status'],
            $names,
        );
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(ErrorDescription::class))->getDocComment(),
        );
    }

    public function testACodeAloneDescribesAProblem(): void
    {
        $description = new ErrorDescription(ErrorCode::Forbidden);

        self::assertSame(ErrorCode::Forbidden, $description->code);
        self::assertSame([], $description->parameters);
        self::assertNull($description->pointer);
        self::assertNull($description->parameter);
        self::assertNull($description->header);
        self::assertSame([], $description->meta);
        self::assertNull($description->typeLink);
        self::assertNull($description->title);
        self::assertNull($description->detail);
        self::assertNull($description->status);
    }

    public function testACodeOfTheProjectIsKeptAsItIsSpelled(): void
    {
        self::assertSame('rest_no_route', (new ErrorDescription('rest_no_route'))->code);
    }

    /**
     * the values are those of the max-size-exceeded error of the cursor pagination profile
     */
    public function testEveryMemberIsHeldAsGiven(): void
    {
        $typeLink = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/max-size-exceeded';

        $description = new ErrorDescription(
            code: ErrorCode::BadRequest,
            parameters: ['maxSize' => 100, 'requested' => 200],
            parameter: 'page[size]',
            meta: ['page' => ['maxSize' => 100]],
            typeLink: $typeLink,
            title: 'the page size is too large',
            detail: 'the page size is at most 100',
            status: 400,
        );

        self::assertSame(ErrorCode::BadRequest, $description->code);
        self::assertSame(['maxSize' => 100, 'requested' => 200], $description->parameters);
        self::assertSame('page[size]', $description->parameter);
        self::assertSame(['page' => ['maxSize' => 100]], $description->meta);
        self::assertSame($typeLink, $description->typeLink);
        self::assertSame('the page size is too large', $description->title);
        self::assertSame('the page size is at most 100', $description->detail);
        self::assertSame(400, $description->status);
    }

    /**
     * PHP stores a key of digits as an int
     */
    public function testMetaMayBeKeyedByAMemberNameOfDigits(): void
    {
        $description = new ErrorDescription('invalid_value', meta: ['0' => 'first']);

        self::assertSame([0 => 'first'], $description->meta);
    }

    #[DataProvider('singleSources')]
    public function testEachSourceIsAcceptedAlone(?string $pointer, ?string $parameter, ?string $header): void
    {
        $description = new ErrorDescription('invalid_value', pointer: $pointer, parameter: $parameter, header: $header);

        self::assertSame($pointer, $description->pointer);
        self::assertSame($parameter, $description->parameter);
        self::assertSame($header, $description->header);
    }

    /**
     * @return array<string, array{string|null, string|null, string|null}>
     */
    public static function singleSources(): array
    {
        return [
            'a pointer' => ['/data/attributes/title', null, null],
            'a parameter' => [null, 'sort', null],
            'a header' => [null, null, 'If-Match'],
            'no source' => [null, null, null],
        ];
    }

    #[DataProvider('severalSources')]
    public function testMoreThanOneSourceIsRefused(
        ?string $pointer,
        ?string $parameter,
        ?string $header,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ErrorDescription('invalid_value', pointer: $pointer, parameter: $parameter, header: $header);
    }

    /**
     * @return array<string, array{string|null, string|null, string|null, string}>
     */
    public static function severalSources(): array
    {
        return [
            'a pointer and a parameter' => [
                '/data',
                'sort',
                null,
                'an error description names at most one source, got pointer "/data" and parameter "sort"',
            ],
            'a pointer and a header' => [
                '/data',
                null,
                'If-Match',
                'an error description names at most one source, got pointer "/data" and header "If-Match"',
            ],
            'a parameter and a header' => [
                null,
                'sort',
                'If-Match',
                'an error description names at most one source, got parameter "sort" and header "If-Match"',
            ],
            'all three' => [
                '',
                'sort',
                'If-Match',
                'an error description names at most one source, got pointer "" and parameter "sort" and header'
                . ' "If-Match"',
            ],
            'a parameter a client named with a line break' => [
                '/data',
                "so\r\nrt",
                null,
                'an error description names at most one source, got pointer "/data" and parameter "so\\r\\nrt"',
            ],
            'a parameter a client named with a DEL' => [
                '/data',
                "so\x7frt",
                null,
                'an error description names at most one source, got pointer "/data" and parameter "so\\177rt"',
            ],
        ];
    }

    #[DataProvider('pointers')]
    public function testAJsonPointerIsAccepted(string $pointer): void
    {
        self::assertSame($pointer, (new ErrorDescription('invalid_value', pointer: $pointer))->pointer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pointers(): array
    {
        return [
            'the whole document' => [''],
            'the empty member of the document' => ['/'],
            'primary data' => ['/data'],
            'an attribute' => ['/data/attributes/title'],
            'an atomic operation' => ['/atomic:operations/0'],
            'escaped tilde and solidus' => ['/data/attributes/a~0b~1c'],
            'two empty reference tokens' => ['//'],
            'a token holding an LF' => ["/data/attributes/a\nb"],
            'a token holding a space and a quote' => ['/data/attributes/a "b"'],
            'escapes one after another' => ['/~0~1~1~0'],
            'an escaped tilde before a digit' => ['/a~01'],
            'a token holding a DEL and a byte of no UTF-8 sequence' => ["/data/attributes/a\x7f\xff"],
        ];
    }

    #[DataProvider('malformedPointers')]
    public function testAMalformedPointerIsRefused(string $pointer, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ErrorDescription('invalid_value', pointer: $pointer);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedPointers(): array
    {
        $prefix = 'the pointer of an error description must be a JSON pointer (RFC 6901), got ';

        return [
            'no leading solidus' => ['data/attributes', $prefix . '"data/attributes"'],
            'a lone tilde' => ['/data/~', $prefix . '"/data/~"'],
            'a tilde before another digit' => ['/data/~2', $prefix . '"/data/~2"'],
            'a tilde before a letter' => ['/data/~a', $prefix . '"/data/~a"'],
            'a lone LF' => ["\n", $prefix . '"\\n"'],
            'a leading space' => [' /data', $prefix . '" /data"'],
            'a tilde at the end' => ['/data~', $prefix . '"/data~"'],
            'a tilde before an escape' => ['/~~01', $prefix . '"/~~01"'],
            'an escape without a leading solidus' => ['~0', $prefix . '"~0"'],
            'a DEL before a lone tilde' => ["/\x7f~", $prefix . '"/\\177~"'],
        ];
    }

    /**
     * guards against a regular expression check, which hits the backtrack limit on this pointer
     */
    public function testAPointerOfSeveralMegabytesIsAccepted(): void
    {
        $pointer = '/data/attributes/' . str_repeat('a~0', 1000000);

        self::assertSame($pointer, (new ErrorDescription('invalid_value', pointer: $pointer))->pointer);
    }

    /**
     * refused for its trailing lone `~`
     */
    public function testAMalformedPointerOfSeveralMegabytesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ErrorDescription('invalid_value', pointer: '/data/attributes/' . str_repeat('a~0', 1000000) . '~');
    }

    #[DataProvider('headers')]
    public function testAFieldNameIsAcceptedAsTheHeader(string $header): void
    {
        self::assertSame($header, (new ErrorDescription(ErrorCode::PreconditionFailed, header: $header))->header);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function headers(): array
    {
        return [
            'If-Match' => ['If-Match'],
            'Content-Type' => ['Content-Type'],
            'a lower case name' => ['accept'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function testAHeaderThatIsNoFieldNameIsRefused(string $header, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ErrorDescription(ErrorCode::PreconditionFailed, header: $header);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedHeaders(): array
    {
        $prefix = 'the header of an error description must be a field name, which is a token (RFC 9110 5.1), got ';

        return [
            'the empty name' => ['', $prefix . '""'],
            'a name holding a space' => ['If Match', $prefix . '"If Match"'],
            'a name with a colon' => ['If-Match:', $prefix . '"If-Match:"'],
            'a name with a line break' => ["If-Match\r\n", $prefix . '"If-Match\\r\\n"'],
            'a name with a DEL' => ["If\x7fMatch", $prefix . '"If\\177Match"'],
        ];
    }

    #[DataProvider('errorStatuses')]
    public function testAnErrorStatusIsAccepted(int $status): void
    {
        self::assertSame($status, (new ErrorDescription('conflict', status: $status))->status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorStatuses(): array
    {
        return [
            'the first client error' => [400],
            'unprocessable content' => [422],
            'the last client error' => [499],
            'the first server error' => [500],
            'the last server error' => [599],
        ];
    }

    #[DataProvider('statusesOfNoError')]
    public function testAStatusOfNoErrorIsRefused(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the status of an error description must be a 4xx or 5xx status code, got ' . $status,
        );

        new ErrorDescription('conflict', status: $status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesOfNoError(): array
    {
        return [
            'success' => [200],
            'a redirection' => [302],
            'just below the client errors' => [399],
            'just above the server errors' => [600],
            'zero' => [0],
            'a negative number' => [-422],
        ];
    }

    /**
     * each source is tried, so that no two members can be swapped unnoticed
     */
    #[DataProvider('singleSources')]
    public function testWithStatusCopiesEveryOtherMember(?string $pointer, ?string $parameter, ?string $header): void
    {
        $description = new ErrorDescription(
            code: 'invalid_attribute',
            parameters: ['member' => 'title'],
            pointer: $pointer,
            parameter: $parameter,
            header: $header,
            meta: ['constraint' => 'minLength'],
            typeLink: 'https://example.com/errors/invalid-attribute',
            title: 'the attribute is invalid',
            detail: 'the title is too short',
        );

        $stamped = $description->withStatus(422);

        self::assertNotSame($description, $stamped);
        self::assertSame(422, $stamped->status);
        self::assertNull($description->status);
        self::assertSame($description->code, $stamped->code);
        self::assertSame($description->parameters, $stamped->parameters);
        self::assertSame($description->pointer, $stamped->pointer);
        self::assertSame($description->parameter, $stamped->parameter);
        self::assertSame($description->header, $stamped->header);
        self::assertSame($description->meta, $stamped->meta);
        self::assertSame($description->typeLink, $stamped->typeLink);
        self::assertSame($description->title, $stamped->title);
        self::assertSame($description->detail, $stamped->detail);
    }

    public function testWithStatusRefusesAStatusOfNoError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the status of an error description must be a 4xx or 5xx status code, got 204');

        (new ErrorDescription(ErrorCode::Conflict))->withStatus(204);
    }
}
