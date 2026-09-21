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
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ContentTooLargeException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotAcceptableException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Error\PreconditionRequiredException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Error\UnprocessableContentException;
use Polunich\WpJsonApi\Error\UnsupportedMediaTypeException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(JsonApiException::class)]
final class JsonApiExceptionTest extends TestCase
{
    public function testTheBaseIsAConcreteRuntimeExceptionThatIsNotFinal(): void
    {
        $reflection = new ReflectionClass(JsonApiException::class);

        self::assertFalse($reflection->isAbstract());
        self::assertFalse($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(RuntimeException::class));
        self::assertStringNotContainsString('@internal', (string) $reflection->getDocComment());

        $description = new ErrorDescription('gone');
        $exception = new JsonApiException(410, [$description]);

        self::assertSame(JsonApiException::class, $exception::class);
        self::assertSame(410, $exception->status());
        self::assertSame([$description], $exception->descriptions());
    }

    public function testAProjectExtendsTheBase(): void
    {
        $exception = new class (418, [new ErrorDescription('teapot')]) extends JsonApiException {};

        self::assertSame(418, $exception->status());
    }

    /**
     * a project may pass them as named arguments;
     * `previous` matches the parameter of `\Exception::__construct()`
     */
    public function testTheConstructorParameterNamesArePublicApi(): void
    {
        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            (new ReflectionMethod(JsonApiException::class, '__construct'))->getParameters(),
        );

        self::assertSame(['status', 'descriptions', 'previous'], $names);
    }

    /**
     * a subclass must not report a status or problems other than the ones it was built with,
     * nor change which class a status maps to
     */
    public function testTheAccessorsAndTheTableCannotBeOverridden(): void
    {
        self::assertTrue((new ReflectionMethod(JsonApiException::class, 'status'))->isFinal());
        self::assertTrue((new ReflectionMethod(JsonApiException::class, 'descriptions'))->isFinal());
        self::assertTrue((new ReflectionMethod(JsonApiException::class, 'ofStatus'))->isFinal());
    }

    public function testOfStatusTakesTheStatusTheDescriptionsAndThePrevious(): void
    {
        $method = new ReflectionMethod(JsonApiException::class, 'ofStatus');

        self::assertTrue($method->isPublic());
        self::assertTrue($method->isStatic());
        self::assertSame(
            ['status', 'descriptions', 'previous'],
            array_map(static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            }, $method->getParameters()),
        );
        // a `self` return type reads as `self` on PHP 8.3 and 8.4, and as the class from PHP 8.5
        self::assertContains((string) $method->getReturnType(), ['self', JsonApiException::class]);
        self::assertNull($method->getParameters()[2]->getDefaultValue());
    }

    /**
     * @param class-string<JsonApiException> $class
     */
    #[DataProvider('statusesOfALibraryClass')]
    public function testOfStatusBuildsTheLibraryClassOfTheStatus(int $status, string $class): void
    {
        $descriptions = [new ErrorDescription('first'), new ErrorDescription('second')];
        $previous = new RuntimeException('the cause');

        $exception = JsonApiException::ofStatus($status, $descriptions, $previous);

        self::assertSame($class, $exception::class);
        self::assertSame($status, $exception->status());
        self::assertSame($descriptions, $exception->descriptions());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull(JsonApiException::ofStatus($status, $descriptions)->getPrevious());
    }

    /**
     * @return array<string, array{int, class-string<JsonApiException>}>
     */
    public static function statusesOfALibraryClass(): array
    {
        return [
            'status 400' => [400, BadRequestException::class],
            'status 401' => [401, UnauthorizedException::class],
            'status 403' => [403, ForbiddenException::class],
            'status 404' => [404, NotFoundException::class],
            'status 406' => [406, NotAcceptableException::class],
            'status 409' => [409, ConflictException::class],
            'status 412' => [412, PreconditionFailedException::class],
            'status 413' => [413, ContentTooLargeException::class],
            'status 415' => [415, UnsupportedMediaTypeException::class],
            'status 422' => [422, UnprocessableContentException::class],
            'status 428' => [428, PreconditionRequiredException::class],
            'status 500' => [500, InternalServerErrorException::class],
        ];
    }

    public function testOfStatusBuildsA401WithoutChallenges(): void
    {
        $exception = JsonApiException::ofStatus(401, [new ErrorDescription('rest_not_logged_in')]);

        self::assertInstanceOf(UnauthorizedException::class, $exception);
        self::assertSame([], $exception->challenges());
    }

    #[DataProvider('statusesWithoutALibraryClass')]
    public function testOfStatusBuildsTheBaseForAStatusWithoutALibraryClass(int $status): void
    {
        $descriptions = [new ErrorDescription('foreign_error')];
        $previous = new RuntimeException('the cause');

        $exception = JsonApiException::ofStatus($status, $descriptions, $previous);

        self::assertSame(JsonApiException::class, $exception::class);
        self::assertSame($status, $exception->status());
        self::assertSame($descriptions, $exception->descriptions());
        self::assertSame($previous, $exception->getPrevious());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesWithoutALibraryClass(): array
    {
        return [
            'method not allowed, which WordPress writes' => [405],
            'gone, which WordPress writes' => [410],
            'a client error nobody names' => [418],
            'the last client error' => [499],
            'not implemented, which WordPress writes' => [501],
            'service unavailable' => [503],
            'the last server error' => [599],
        ];
    }

    #[DataProvider('statusesOutsideTheErrors')]
    public function testOfStatusRefusesAStatusOfNoError(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a JSON:API exception carries a 4xx or 5xx status code, got ' . $status,
        );

        JsonApiException::ofStatus($status, [new ErrorDescription(ErrorCode::BadRequest)]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesOutsideTheErrors(): array
    {
        return [
            'just below the client errors' => [399],
            'just above the server errors' => [600],
        ];
    }

    public function testTheStatusTheDescriptionsAndThePreviousExceptionAreKept(): void
    {
        $first = new ErrorDescription(ErrorCode::UnprocessableContent, pointer: '/data/attributes/name');
        $second = new ErrorDescription('read_only_field', pointer: '/data/attributes/id');
        $previous = new JsonException('syntax error');

        $exception = self::exception(422, [$first, $second], $previous);

        self::assertSame(422, $exception->status());
        self::assertSame([$first, $second], $exception->descriptions());
        self::assertSame($previous, $exception->getPrevious());
        self::assertSame(0, $exception->getCode());
    }

    public function testThePreviousExceptionIsOptional(): void
    {
        self::assertNull(self::exception(404, [new ErrorDescription(ErrorCode::NotFound)])->getPrevious());
    }

    public function testAnExceptionWithoutADescriptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a JSON:API exception carries at least one error description');

        self::exception(400, []);
    }

    #[DataProvider('errorStatuses')]
    public function testAnErrorStatusIsAccepted(int $status): void
    {
        self::assertSame($status, self::exception($status, [new ErrorDescription('teapot')])->status());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function errorStatuses(): array
    {
        return [
            'the first client error' => [400],
            'a client error without a class of the library' => [418],
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
            'a JSON:API exception carries a 4xx or 5xx status code, got ' . $status,
        );

        self::exception($status, [new ErrorDescription(ErrorCode::BadRequest)]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesOfNoError(): array
    {
        return [
            'success' => [200],
            'a redirection' => [304],
            'just below the client errors' => [399],
            'just above the server errors' => [600],
        ];
    }

    /**
     * title and detail are texts for the client and stay out of the log message
     *
     * @param list<ErrorDescription> $descriptions
     */
    #[DataProvider('messages')]
    public function testTheMessageIsDerivedFromTheDescriptions(int $status, array $descriptions, string $message): void
    {
        self::assertSame($message, self::exception($status, $descriptions)->getMessage());
    }

    /**
     * @return array<string, array{int, list<ErrorDescription>, string}>
     */
    public static function messages(): array
    {
        return [
            'a code of the library without a source' => [
                403,
                [new ErrorDescription(ErrorCode::Forbidden)],
                'the request failed with status 403: forbidden',
            ],
            'a code of the project at a pointer' => [
                422,
                [new ErrorDescription('invalid_attribute', pointer: '/data/attributes/name')],
                'the request failed with status 422: invalid_attribute at pointer "/data/attributes/name"',
            ],
            'a parameter' => [
                400,
                [new ErrorDescription('unsupported_sort', parameter: 'sort')],
                'the request failed with status 400: unsupported_sort at parameter "sort"',
            ],
            'a header' => [
                412,
                [new ErrorDescription(ErrorCode::PreconditionFailed, header: 'If-Match')],
                'the request failed with status 412: precondition_failed at header "If-Match"',
            ],
            'problems merged from several statuses' => [
                400,
                [
                    new ErrorDescription('invalid_attribute', pointer: '/data/attributes/name', status: 422),
                    new ErrorDescription(ErrorCode::NotFound, pointer: '/data/relationships/store', status: 404),
                ],
                'the request failed with status 400: invalid_attribute at pointer "/data/attributes/name" with'
                . ' status 422, not_found at pointer "/data/relationships/store" with status 404',
            ],
            'texts for the client stay out' => [
                409,
                [new ErrorDescription(ErrorCode::Conflict, title: 'The Title', detail: 'The detail')],
                'the request failed with status 409: conflict',
            ],
        ];
    }

    public function testControlCharactersReachTheMessageEscaped(): void
    {
        $exception = self::exception(400, [
            new ErrorDescription("forged\ncode", parameter: "page[size]\r\n[error] forged"),
            new ErrorDescription('invalid_value', pointer: "/data/attributes/a\x00b\x7f"),
            new ErrorDescription('invalid_value', header: 'If-Match'),
        ]);

        self::assertSame(
            'the request failed with status 400: forged\ncode at parameter "page[size]\r\n[error] forged",'
            . ' invalid_value at pointer "/data/attributes/a\000b\177", invalid_value at header "If-Match"',
            $exception->getMessage(),
        );
        self::assertStringNotContainsString("\n", $exception->getMessage());
    }

    /**
     * @param list<ErrorDescription> $descriptions
     */
    private static function exception(int $status, array $descriptions, ?Throwable $previous = null): JsonApiException
    {
        return new JsonApiException($status, $descriptions, $previous);
    }
}
