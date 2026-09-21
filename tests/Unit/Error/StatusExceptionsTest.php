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

/**
 * the exceptions that each fix one status share the contract of `JsonApiException`;
 * each runs the same cases here, and `UnauthorizedExceptionTest` covers what its challenges add
 *
 * @internal
 *
 * @phpstan-type StatusException (
 *     BadRequestException
 *     |ConflictException
 *     |ContentTooLargeException
 *     |ForbiddenException
 *     |InternalServerErrorException
 *     |NotAcceptableException
 *     |NotFoundException
 *     |PreconditionFailedException
 *     |PreconditionRequiredException
 *     |UnauthorizedException
 *     |UnprocessableContentException
 *     |UnsupportedMediaTypeException
 * )
 */
#[CoversClass(BadRequestException::class)]
#[CoversClass(ConflictException::class)]
#[CoversClass(ContentTooLargeException::class)]
#[CoversClass(ForbiddenException::class)]
#[CoversClass(InternalServerErrorException::class)]
#[CoversClass(NotAcceptableException::class)]
#[CoversClass(NotFoundException::class)]
#[CoversClass(PreconditionFailedException::class)]
#[CoversClass(PreconditionRequiredException::class)]
#[CoversClass(UnauthorizedException::class)]
#[CoversClass(UnprocessableContentException::class)]
#[CoversClass(UnsupportedMediaTypeException::class)]
final class StatusExceptionsTest extends TestCase
{
    /**
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testTheClassIsAFinalJsonApiExceptionOfThePublicApi(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        $reflection = new ReflectionClass($class);

        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isSubclassOf(JsonApiException::class));
        self::assertStringNotContainsString('@internal', (string) $reflection->getDocComment());
    }

    /**
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testTheConstructorTakesTheDescriptionsAndThePreviousExceptionByTheirPublicNames(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            (new ReflectionMethod($class, '__construct'))->getParameters(),
        );

        self::assertSame('descriptions', $names[0]);
        self::assertSame('previous', $names[count($names) - 1]);
    }

    /**
     * a status a description carries never becomes the status of the exception
     *
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testTheStatusIsTheOneOfTheClass(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        $exception = new $class([new ErrorDescription(ErrorCode::BadRequest, status: $status === 400 ? 422 : 400)]);

        self::assertSame($status, $exception->status());
    }

    /**
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testTheDescriptionsAndThePreviousExceptionAreKept(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        $second = new ErrorDescription(ErrorCode::ofStatus($status));
        $previous = new RuntimeException('the cause');

        $exception = new $class(descriptions: [$description, $second], previous: $previous);

        self::assertSame([$description, $second], $exception->descriptions());
        self::assertSame($previous, $exception->getPrevious());
        self::assertNull((new $class([$description]))->getPrevious());
    }

    /**
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testTheMessageNamesTheStatusAndTheProblems(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        self::assertSame($message, (new $class([$description]))->getMessage());
    }

    /**
     * @param class-string<StatusException> $class
     */
    #[DataProvider('exceptions')]
    public function testAnExceptionWithoutADescriptionIsRefused(
        string $class,
        int $status,
        ErrorDescription $description,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a JSON:API exception carries at least one error description');

        new $class([]);
    }

    /**
     * each class with its status, a description, and the message that description gives
     *
     * @return iterable<string, array{class-string<StatusException>, int, ErrorDescription, string}>
     */
    public static function exceptions(): iterable
    {
        yield '400' => [
            BadRequestException::class,
            400,
            new ErrorDescription('invalid_query_parameter', parameter: 'filter[_]'),
            'the request failed with status 400: invalid_query_parameter at parameter "filter[_]"',
        ];
        yield '401' => [
            UnauthorizedException::class,
            401,
            new ErrorDescription(ErrorCode::Unauthorized),
            'the request failed with status 401: unauthorized',
        ];
        yield '403' => [
            ForbiddenException::class,
            403,
            new ErrorDescription('authentication_required'),
            'the request failed with status 403: authentication_required',
        ];
        yield '404' => [
            NotFoundException::class,
            404,
            new ErrorDescription(ErrorCode::NotFound, pointer: '/data/relationships/store/data'),
            'the request failed with status 404: not_found at pointer "/data/relationships/store/data"',
        ];
        yield '406' => [
            NotAcceptableException::class,
            406,
            new ErrorDescription(ErrorCode::NotAcceptable, header: 'Accept'),
            'the request failed with status 406: not_acceptable at header "Accept"',
        ];
        yield '409' => [
            ConflictException::class,
            409,
            new ErrorDescription(ErrorCode::Conflict, pointer: '/data/type'),
            'the request failed with status 409: conflict at pointer "/data/type"',
        ];
        yield '412' => [
            PreconditionFailedException::class,
            412,
            new ErrorDescription(ErrorCode::PreconditionFailed, header: 'If-Match'),
            'the request failed with status 412: precondition_failed at header "If-Match"',
        ];
        yield '413' => [
            ContentTooLargeException::class,
            413,
            new ErrorDescription(ErrorCode::ContentTooLarge, header: 'Content-Length'),
            'the request failed with status 413: content_too_large at header "Content-Length"',
        ];
        yield '415' => [
            UnsupportedMediaTypeException::class,
            415,
            new ErrorDescription(ErrorCode::UnsupportedMediaType, header: 'Content-Type'),
            'the request failed with status 415: unsupported_media_type at header "Content-Type"',
        ];
        yield '422' => [
            UnprocessableContentException::class,
            422,
            new ErrorDescription('invalid_attribute', pointer: '/data/attributes/title'),
            'the request failed with status 422: invalid_attribute at pointer "/data/attributes/title"',
        ];
        yield '428' => [
            PreconditionRequiredException::class,
            428,
            new ErrorDescription(ErrorCode::PreconditionRequired, header: 'If-Match'),
            'the request failed with status 428: precondition_required at header "If-Match"',
        ];
        yield '500' => [
            InternalServerErrorException::class,
            500,
            new ErrorDescription(ErrorCode::InternalError),
            'the request failed with status 500: internal_error',
        ];
    }
}
