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
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotAcceptableException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Error\PreconditionRequiredException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Error\UnprocessableContentException;
use Polunich\WpJsonApi\Error\UnsupportedMediaTypeException;

/**
 * @internal
 */
#[CoversClass(ErrorList::class)]
final class ErrorListTest extends TestCase
{
    public function testAnErrorListWithoutAProblemHasNoException(): void
    {
        self::assertNull(self::errorList()->exception());
    }

    public function testAnErrorListWithoutAProblemLetsTheStepGoOn(): void
    {
        $this->expectNotToPerformAssertions();

        self::errorList()->throwIfAny();
    }

    public function testAnErrorListWithAProblemEndsTheStepWithItsException(): void
    {
        $errors = self::errorList();
        $errors->add(new BadRequestException([new ErrorDescription(ErrorCode::BadRequest)]));

        $this->expectException(BadRequestException::class);

        $errors->throwIfAny();
    }

    public function testOneExceptionIsReturnedItself(): void
    {
        $previous = new JsonException('Syntax error');
        $exception = new BadRequestException([new ErrorDescription(ErrorCode::BadRequest)], $previous);
        $errors = self::errorList();

        $errors->add($exception);

        self::assertSame($exception, $errors->exception());
        self::assertNull($exception->descriptions()[0]->status);
    }

    public function testExceptionsOfOneStatusAreMergedIntoThatStatusWithoutStamps(): void
    {
        $name = new ErrorDescription('invalid_attribute', pointer: '/data/attributes/name');
        $listedAt = new ErrorDescription('invalid_attribute', pointer: '/data/attributes/listedAt');
        $id = new ErrorDescription('read_only_field', pointer: '/data/attributes/id');
        $errors = self::errorList();

        $errors->add(new UnprocessableContentException([$name, $listedAt]));
        $errors->add(new UnprocessableContentException([$id]));
        $merged = $errors->exception();

        self::assertInstanceOf(UnprocessableContentException::class, $merged);
        self::assertSame(422, $merged->status());
        self::assertSame([$name, $listedAt, $id], $merged->descriptions());
        self::assertSame([null, null, null], self::statuses($merged));
        self::assertNull($merged->getPrevious());
    }

    public function testExceptionsOfSeveralStatusesAreMergedInto400WithTheirOwnStatuses(): void
    {
        $errors = self::errorList();

        $errors->add(new UnprocessableContentException([new ErrorDescription(
            'invalid_attribute',
            pointer: '/data/attributes/name',
        )]));
        $errors->add(new NotFoundException([new ErrorDescription(
            ErrorCode::NotFound,
            pointer: '/data/relationships/store/data',
        )]));
        $merged = $errors->exception();

        self::assertInstanceOf(BadRequestException::class, $merged);
        self::assertSame(400, $merged->status());
        self::assertSame([422, 404], self::statuses($merged));
        self::assertSame(
            ['invalid_attribute', ErrorCode::NotFound],
            array_map(static function (ErrorDescription $description): string|ErrorCode {
                return $description->code;
            }, $merged->descriptions()),
        );
        self::assertSame(
            ['/data/attributes/name', '/data/relationships/store/data'],
            array_map(static function (ErrorDescription $description): ?string {
                return $description->pointer;
            }, $merged->descriptions()),
        );
        self::assertNull($merged->getPrevious());
    }

    public function testA400AmongOtherStatusesIsNotStamped(): void
    {
        $malformed = new ErrorDescription(ErrorCode::BadRequest, parameter: 'sort');
        $errors = self::errorList();

        $errors->add(new BadRequestException([$malformed]));
        $errors->add(new ConflictException([new ErrorDescription(ErrorCode::Conflict)]));
        $merged = $errors->exception();

        self::assertInstanceOf(BadRequestException::class, $merged);
        self::assertSame([null, 409], self::statuses($merged));
        self::assertSame($malformed, $merged->descriptions()[0]);
    }

    /**
     * @param list<JsonApiException>         $exceptions
     * @param class-string<JsonApiException> $class
     * @param list<int|null>                 $statuses
     */
    #[DataProvider('mergesOfMerges')]
    public function testAMergeOfAMergeStampsOnlyWhereTheStatusDiffers(
        array $exceptions,
        string $class,
        int $status,
        array $statuses,
    ): void {
        $errors = self::errorList();

        foreach ($exceptions as $exception) {
            $errors->add($exception);
        }

        $merged = $errors->exception();

        self::assertInstanceOf($class, $merged);
        self::assertSame($status, $merged->status());
        self::assertSame($statuses, self::statuses($merged));
    }

    /**
     * @return array<string, array{
     *     list<JsonApiException>,
     *     class-string<JsonApiException>,
     *     int,
     *     list<int|null>,
     * }>
     */
    public static function mergesOfMerges(): array
    {
        $first = self::errorList();
        $first->add(new UnprocessableContentException([new ErrorDescription('invalid_attribute')]));
        $first->add(new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]));
        $mergedOfTwoStatuses = $first->exception();

        $second = self::errorList();
        $second->add(new UnprocessableContentException([new ErrorDescription('invalid_attribute')]));
        $second->add(new UnprocessableContentException([new ErrorDescription('read_only_field')]));
        $mergedOfOneStatus = $second->exception();

        if ($mergedOfTwoStatuses === null || $mergedOfOneStatus === null) {
            throw new LogicException('an error list of two exceptions returns an exception');
        }

        return [
            'a merged 400 with 422 and 404, and a 422' => [
                [$mergedOfTwoStatuses, new UnprocessableContentException([new ErrorDescription('invalid_attribute')])],
                BadRequestException::class,
                400,
                [422, 404, 422],
            ],
            'a merged 422 without statuses, and a 404' => [
                [$mergedOfOneStatus, new NotFoundException([new ErrorDescription(ErrorCode::NotFound)])],
                BadRequestException::class,
                400,
                [422, 422, 404],
            ],
            'a merged 400 with 422 and 404, and a 409' => [
                [$mergedOfTwoStatuses, new ConflictException([new ErrorDescription(ErrorCode::Conflict)])],
                BadRequestException::class,
                400,
                [422, 404, 409],
            ],
            'a description with a status of its own in an exception of another status' => [
                [
                    new NotFoundException([new ErrorDescription('invalid_attribute', status: 422)]),
                    new ConflictException([new ErrorDescription(ErrorCode::Conflict)]),
                ],
                BadRequestException::class,
                400,
                [422, 409],
            ],
        ];
    }

    public function testTheStatusIsSelectedAmongTheStatusesOfTheExceptions(): void
    {
        $errors = self::errorList();

        $errors->add(new BadRequestException([new ErrorDescription('invalid_attribute', status: 422)]));
        $errors->add(new BadRequestException([new ErrorDescription('read_only_field', status: 422)]));
        $merged = $errors->exception();

        self::assertInstanceOf(BadRequestException::class, $merged);
        self::assertSame(400, $merged->status());
        self::assertSame([422, 422], self::statuses($merged));
    }

    /**
     * @param class-string<JsonApiException> $class
     */
    #[DataProvider('requestErrors')]
    public function testTheMergedExceptionIsTheClassOfTheSelectedStatus(
        JsonApiException $first,
        JsonApiException $second,
        string $class,
        int $status,
    ): void {
        $errors = self::errorList();

        $errors->add($first);
        $errors->add($second);
        $merged = $errors->exception();

        self::assertNotNull($merged);
        self::assertSame($class, $merged::class);
        self::assertSame($status, $merged->status());
        self::assertSame(['first', 'second'], array_map(
            static function (ErrorDescription $description): string|ErrorCode {
                return $description->code;
            },
            $merged->descriptions(),
        ));
        self::assertSame([null, null], self::statuses($merged));
    }

    /**
     * @return array<string, array{
     *     JsonApiException,
     *     JsonApiException,
     *     class-string<JsonApiException>,
     *     int,
     * }>
     */
    public static function requestErrors(): array
    {
        $first = [new ErrorDescription('first')];
        $second = [new ErrorDescription('second')];

        return [
            'status 400' => [
                new BadRequestException($first),
                new BadRequestException($second),
                BadRequestException::class,
                400,
            ],
            'status 403' => [
                new ForbiddenException($first),
                new ForbiddenException($second),
                ForbiddenException::class,
                403,
            ],
            'status 404' => [
                new NotFoundException($first),
                new NotFoundException($second),
                NotFoundException::class,
                404,
            ],
            'status 406' => [
                new NotAcceptableException($first),
                new NotAcceptableException($second),
                NotAcceptableException::class,
                406,
            ],
            'status 409' => [
                new ConflictException($first),
                new ConflictException($second),
                ConflictException::class,
                409,
            ],
            'status 412' => [
                new PreconditionFailedException($first),
                new PreconditionFailedException($second),
                PreconditionFailedException::class,
                412,
            ],
            'status 415' => [
                new UnsupportedMediaTypeException($first),
                new UnsupportedMediaTypeException($second),
                UnsupportedMediaTypeException::class,
                415,
            ],
            'status 422' => [
                new UnprocessableContentException($first),
                new UnprocessableContentException($second),
                UnprocessableContentException::class,
                422,
            ],
            'status 428' => [
                new PreconditionRequiredException($first),
                new PreconditionRequiredException($second),
                PreconditionRequiredException::class,
                428,
            ],
            'status 405, without a library class' => [
                new JsonApiException(405, $first),
                new JsonApiException(405, $second),
                JsonApiException::class,
                405,
            ],
            'status 418, of a project' => [
                self::exceptionOfAProject(418, $first),
                self::exceptionOfAProject(418, $second),
                JsonApiException::class,
                418,
            ],
            'status 499' => [
                new JsonApiException(499, $first),
                new JsonApiException(499, $second),
                JsonApiException::class,
                499,
            ],
        ];
    }

    public function testAStatusWithoutALibraryClassMergesWithOtherStatuses(): void
    {
        $errors = self::errorList();

        $errors->add(new JsonApiException(405, [new ErrorDescription('rest_no_route')]));
        $errors->add(new UnprocessableContentException([new ErrorDescription('invalid_attribute')]));
        $merged = $errors->exception();

        self::assertInstanceOf(BadRequestException::class, $merged);
        self::assertSame([405, 422], self::statuses($merged));
    }

    #[DataProvider('unauthorizedExceptions')]
    public function testA401IsRefused(JsonApiException $exception): void
    {
        $errors = self::errorList();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an error list refuses a 401: its challenges come from authorization, never'
            . ' from parsing');

        $errors->add($exception);
    }

    /**
     * @return array<string, array{JsonApiException}>
     */
    public static function unauthorizedExceptions(): array
    {
        return [
            'with a challenge' => [new UnauthorizedException(
                [new ErrorDescription(ErrorCode::Unauthorized)],
                [new Challenge('Basic', ['realm' => 'WordPress'])],
            )],
            'without a challenge, as ofStatus() builds it' => [
                JsonApiException::ofStatus(401, [new ErrorDescription('rest_not_logged_in')]),
            ],
        ];
    }

    #[DataProvider('statusesOfNoRequestError')]
    public function testAStatusOutsideTheClientErrorsIsRefused(JsonApiException $exception, int $status): void
    {
        $errors = self::errorList();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'an error list collects request errors, whose status is a 4xx other than 401, got ' . $status,
        );

        $errors->add($exception);
    }

    /**
     * @return array<string, array{JsonApiException, int}>
     */
    public static function statusesOfNoRequestError(): array
    {
        return [
            'a server error of the library' => [
                new InternalServerErrorException([new ErrorDescription(ErrorCode::InternalError)]),
                500,
            ],
            'a server error without a library class' => [
                new JsonApiException(503, [new ErrorDescription('unavailable')]),
                503,
            ],
            'a server error of a project' => [
                self::exceptionOfAProject(503, [new ErrorDescription('project_error')]),
                503,
            ],
            'the first server error' => [new JsonApiException(500, [new ErrorDescription('failed')]), 500],
        ];
    }

    public function testARefusedExceptionIsNotCollected(): void
    {
        $collected = new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
        $errors = self::errorList();
        $errors->add($collected);

        $refusals = [
            new InternalServerErrorException([new ErrorDescription(ErrorCode::InternalError)]),
            JsonApiException::ofStatus(401, [new ErrorDescription(ErrorCode::Unauthorized)]),
        ];

        foreach ($refusals as $refused) {
            try {
                $errors->add($refused);
                self::fail('the error list collected a ' . $refused->status());
            } catch (InvalidArgumentException) {
            }
        }

        self::assertSame($collected, $errors->exception());
    }

    #[DataProvider('statusesOfAProject')]
    public function testAnExceptionOfAProjectWithARequestErrorStatusIsCollected(int $status): void
    {
        $exception = self::exceptionOfAProject($status, [new ErrorDescription('project_error')]);
        $errors = self::errorList();

        $errors->add($exception);

        self::assertSame($exception, $errors->exception());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function statusesOfAProject(): array
    {
        return [
            'a status of a library class' => [404],
            'the first client error' => [400],
            'a status without a library class' => [418],
            'the last client error' => [499],
        ];
    }

    private static function errorList(): ErrorList
    {
        return new ErrorList(new StatusSelector());
    }

    /**
     * @return list<int|null>
     */
    private static function statuses(JsonApiException $exception): array
    {
        return array_map(static function (ErrorDescription $description): ?int {
            return $description->status;
        }, $exception->descriptions());
    }

    /**
     * @param list<ErrorDescription> $descriptions
     */
    private static function exceptionOfAProject(int $status, array $descriptions): JsonApiException
    {
        return new class ($status, $descriptions) extends JsonApiException {};
    }
}
