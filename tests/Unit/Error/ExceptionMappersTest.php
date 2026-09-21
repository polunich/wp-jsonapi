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

use Countable;
use Exception;
use InvalidArgumentException;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\DefaultExceptionMapper;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\ExceptionMappers;
use Polunich\WpJsonApi\Error\InternalServerErrorException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordFailureException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordLockedException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordMissingException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordNotFoundException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordStaleException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RetryableException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\StoreException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\StoreFailureException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;
use TypeError;
use UnexpectedValueException;

/**
 * each mapper under test returns a `ConflictException`
 * whose code is the name the test gave the mapper, so the code tells which mapper won
 *
 * @internal
 */
#[CoversClass(ExceptionMappers::class)]
final class ExceptionMappersTest extends TestCase
{
    public function testTheMappersAreAnInternalReadonlyValue(): void
    {
        $reflection = new ReflectionClass(ExceptionMappers::class);

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isFinal());
        self::assertStringContainsString('@internal', (string) $reflection->getDocComment());
    }

    /**
     * the mappers of `Throwable` and `RuntimeException` would claim it, and neither is asked
     */
    #[DataProvider('libraryExceptions')]
    public function testAJsonApiExceptionMapsToItself(JsonApiException $exception): void
    {
        $mappers = new ExceptionMappers(
            [self::mapper(Throwable::class, 'throwable'), self::mapper(RuntimeException::class, 'runtime')],
            new DefaultExceptionMapper(),
        );

        self::assertSame($exception, $mappers->map($exception));
    }

    /**
     * @return array<string, array{JsonApiException}>
     */
    public static function libraryExceptions(): array
    {
        return [
            'a 404' => [new NotFoundException([new ErrorDescription(ErrorCode::NotFound)])],
            'a 401, which the denial policy rewrites later' => [
                new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)]),
            ],
            'a 500' => [new InternalServerErrorException([new ErrorDescription(ErrorCode::InternalError)])],
            'a status without a library class' => [new JsonApiException(410, [new ErrorDescription('gone')])],
            'an exception of a project' => [
                new class (418, [new ErrorDescription('teapot')]) extends JsonApiException {},
            ],
        ];
    }

    /**
     * @param list<class-string<Throwable>> $types
     */
    #[DataProvider('unclaimedThrowables')]
    public function testAThrowableNoMapperClaimsBecomesA500(array $types, Throwable $throwable): void
    {
        $mappers = new ExceptionMappers(
            array_map(static function (string $type): ExceptionMapper {
                return self::mapper($type, $type);
            }, $types),
            new DefaultExceptionMapper(),
        );

        $mapped = $mappers->map($throwable);

        self::assertInstanceOf(InternalServerErrorException::class, $mapped);
        self::assertSame($throwable, $mapped->getPrevious());
        self::assertSame(ErrorCode::InternalError, $mapped->descriptions()[0]->code);
    }

    /**
     * @return array<string, array{list<class-string<Throwable>>, Throwable}>
     */
    public static function unclaimedThrowables(): array
    {
        return [
            'no mapper at all' => [[], new RuntimeException('failed')],
            'a mapper of an unrelated class' => [[LogicException::class], new RuntimeException('failed')],
            'a mapper of a subclass' => [[UnexpectedValueException::class], new RuntimeException('failed')],
            'an error of the engine and a mapper of exceptions' => [[Exception::class], new TypeError('failed')],
            'a mapper of an interface the throwable does not implement' => [
                [StoreException::class],
                new RuntimeException('failed'),
            ],
        ];
    }

    /**
     * every order of registration is tried:
     * for types of different levels or related types, the order must not decide
     *
     * @param list<class-string<Throwable>> $types
     */
    #[DataProvider('nearestTypes')]
    public function testTheMapperOfTheNearestTypeWinsInEveryOrder(
        array $types,
        Throwable $throwable,
        string $nearest,
    ): void {
        foreach (self::orders($types) as $order) {
            $mappers = new ExceptionMappers(
                array_map(static function (string $type): ExceptionMapper {
                    return self::mapper($type, $type);
                }, $order),
                new DefaultExceptionMapper(),
            );

            self::assertSame($nearest, self::winner($mappers->map($throwable)), implode(', ', $order));
        }
    }

    /**
     * @return array<string, array{list<class-string<Throwable>>, Throwable, string}>
     */
    public static function nearestTypes(): array
    {
        $retryable = new class ('failed') extends RuntimeException implements RetryableException {};

        return [
            'the class itself' => [
                [RuntimeException::class, UnexpectedValueException::class],
                new UnexpectedValueException('failed'),
                UnexpectedValueException::class,
            ],
            'the nearest superclass' => [
                [Exception::class, RuntimeException::class, Throwable::class],
                new UnexpectedValueException('failed'),
                RuntimeException::class,
            ],
            'the root interface for an error of the engine' => [
                [Exception::class, Throwable::class],
                new TypeError('failed'),
                Throwable::class,
            ],
            'an interface before the interface it extends, one level' => [
                [StoreException::class, RecordMissingException::class],
                new RecordNotFoundException('failed'),
                RecordMissingException::class,
            ],
            'a class before an interface it implements, one level' => [
                [StoreException::class, RecordNotFoundException::class],
                new RecordNotFoundException('failed'),
                RecordNotFoundException::class,
            ],
            'the root class before the root interface it implements, one level' => [
                [Throwable::class, Exception::class],
                new RuntimeException('failed'),
                Exception::class,
            ],
            'an interface before the root interface' => [
                [Throwable::class, StoreException::class],
                new RecordNotFoundException('failed'),
                StoreException::class,
            ],
            'a superclass before the root interface' => [
                [Throwable::class, RuntimeException::class],
                new RecordNotFoundException('failed'),
                RuntimeException::class,
            ],
            'an interface of the class before its superclass' => [
                [RuntimeException::class, RecordMissingException::class],
                new RecordNotFoundException('failed'),
                RecordMissingException::class,
            ],
            'an interface of an anonymous class before its superclass' => [
                [RuntimeException::class, RetryableException::class],
                $retryable,
                RetryableException::class,
            ],
            'an interface of the class before a farther superclass' => [
                [Exception::class, RetryableException::class],
                $retryable,
                RetryableException::class,
            ],
            'an interface of the class before two superclasses' => [
                [Exception::class, RuntimeException::class, RetryableException::class],
                $retryable,
                RetryableException::class,
            ],
            'the parent class before an interface the grandparent brings in' => [
                [RetryableException::class, RecordFailureException::class],
                new RecordLockedException('failed'),
                RecordFailureException::class,
            ],
            'the class that brings an interface in before the interface a nearer class declares again' => [
                [RetryableException::class, StoreFailureException::class],
                new RecordStaleException('failed'),
                StoreFailureException::class,
            ],
        ];
    }

    /**
     * @param list<class-string<Throwable>> $types
     */
    #[DataProvider('equallyNearTypes')]
    public function testOfEquallyNearTypesTheMapperRegisteredFirstWins(
        array $types,
        Throwable $throwable,
        string $winner,
    ): void {
        $mappers = new ExceptionMappers(
            array_map(static function (string $type): ExceptionMapper {
                return self::mapper($type, $type);
            }, $types),
            new DefaultExceptionMapper(),
        );

        self::assertSame($winner, self::winner($mappers->map($throwable)));
    }

    /**
     * @return array<string, array{list<class-string<Throwable>>, Throwable, string}>
     */
    public static function equallyNearTypes(): array
    {
        $twoInterfaces = new class extends RuntimeException implements RecordMissingException, RetryableException {};

        return [
            'two unrelated interfaces' => [
                [RetryableException::class, RecordMissingException::class],
                $twoInterfaces,
                RetryableException::class,
            ],
            'two unrelated interfaces, the other order' => [
                [RecordMissingException::class, RetryableException::class],
                $twoInterfaces,
                RecordMissingException::class,
            ],
            'a farther type registered first does not decide between the nearest' => [
                [Exception::class, RuntimeException::class, RetryableException::class, RecordMissingException::class],
                $twoInterfaces,
                RetryableException::class,
            ],
            'a farther type registered first, the other order of the nearest' => [
                [Exception::class, RuntimeException::class, RecordMissingException::class, RetryableException::class],
                $twoInterfaces,
                RecordMissingException::class,
            ],
        ];
    }

    public function testTheMapperReceivesTheThrowable(): void
    {
        $throwable = new class ('failed') extends LogicException implements StoreException {};
        $mappers = new ExceptionMappers([self::mapper(StoreException::class, 'store')], new DefaultExceptionMapper());

        $mapped = $mappers->map($throwable);

        self::assertSame('store', self::winner($mapped));
        self::assertSame($throwable, $mapped->getPrevious());
    }

    public function testAMapperDeclaredForANarrowerTypeJoinsTheList(): void
    {
        /** @implements ExceptionMapper<RecordNotFoundException> */
        $records = new class implements ExceptionMapper {
            #[Override]
            public function exceptionClass(): string
            {
                return RecordNotFoundException::class;
            }

            #[Override]
            public function map(Throwable $exception): JsonApiException
            {
                $detail = $exception instanceof RecordNotFoundException ? $exception->getMessage() : null;

                return new NotFoundException([new ErrorDescription(ErrorCode::NotFound, detail: $detail)], $exception);
            }
        };

        $mappers = new ExceptionMappers(
            [$records, self::mapper(RuntimeException::class, 'runtime')],
            new DefaultExceptionMapper(),
        );
        $mapped = $mappers->map(new RecordNotFoundException('pet 7 does not exist'));

        self::assertInstanceOf(NotFoundException::class, $mapped);
        self::assertSame('pet 7 does not exist', $mapped->descriptions()[0]->detail);
    }

    /**
     * the `Throwable` mapper must not get what the nearer mapper threw, even a `JsonApiException`
     */
    #[DataProvider('failures')]
    public function testAThrowableAMapperThrowsBecomesA500(Throwable $failure): void
    {
        $mappers = new ExceptionMappers(
            [self::mapper(Throwable::class, 'throwable'), self::failingMapper(RuntimeException::class, $failure)],
            new DefaultExceptionMapper(),
        );

        $mapped = $mappers->map(new UnexpectedValueException('the store is closed'));

        self::assertInstanceOf(InternalServerErrorException::class, $mapped);

        $context = $mapped->getPrevious();

        self::assertInstanceOf(LogicException::class, $context);
        self::assertSame(
            'the exception mapper of ' . RuntimeException::class . ' threw while it mapped '
            . UnexpectedValueException::class . ' "the store is closed"',
            $context->getMessage(),
        );
        self::assertSame($failure, $context->getPrevious());
    }

    /**
     * @return array<string, array{Throwable}>
     */
    public static function failures(): array
    {
        return [
            'an exception' => [new RuntimeException('the mapper failed')],
            'an error of the engine' => [new TypeError('the mapper failed')],
            'a library exception, which a mapper returns and does not throw' => [
                new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]),
            ],
        ];
    }

    public function testTheMessageOfTheMappedThrowableIsEscaped(): void
    {
        $mappers = new ExceptionMappers(
            [self::failingMapper(RuntimeException::class, new RuntimeException('failed'))],
            new DefaultExceptionMapper(),
        );

        $context = $mappers->map(new RuntimeException("line one\r\nline two\x7f"))->getPrevious();

        self::assertInstanceOf(LogicException::class, $context);
        self::assertSame(
            'the exception mapper of ' . RuntimeException::class . ' threw while it mapped '
            . RuntimeException::class . ' "line one\r\nline two\177"',
            $context->getMessage(),
        );
    }

    #[DataProvider('spellings')]
    public function testTheMessageNamesTheTypeOfTheMapperAsDeclared(string $spelling): void
    {
        $mappers = new ExceptionMappers(
            [self::failingMapperOfAnyString($spelling, new RuntimeException('failed'))],
            new DefaultExceptionMapper(),
        );

        $context = $mappers->map(new RuntimeException('the store is closed'))->getPrevious();

        self::assertInstanceOf(LogicException::class, $context);
        self::assertSame(
            'the exception mapper of ' . RuntimeException::class . ' threw while it mapped '
            . RuntimeException::class . ' "the store is closed"',
            $context->getMessage(),
        );
    }

    /**
     * the name of an anonymous class holds a NUL byte
     */
    public function testTheClassNamesOfTheMessageAreEscaped(): void
    {
        $throwable = new class ('failed') extends RuntimeException {};
        $mappers = new ExceptionMappers(
            [self::failingMapper($throwable::class, new RuntimeException('the mapper failed'))],
            new DefaultExceptionMapper(),
        );

        $context = $mappers->map($throwable)->getPrevious();

        self::assertInstanceOf(LogicException::class, $context);
        self::assertStringContainsString("\0", $throwable::class);
        self::assertSame(
            'the exception mapper of ' . self::escaped($throwable::class) . ' threw while it mapped '
            . self::escaped($throwable::class) . ' "failed"',
            $context->getMessage(),
        );
        self::assertStringNotContainsString("\0", $context->getMessage());
    }

    /**
     * PHP resolves a class name regardless of case and of a leading backslash
     */
    #[DataProvider('spellings')]
    public function testATypeInAnotherSpellingClaimsItsThrowables(string $spelling): void
    {
        $mappers = new ExceptionMappers(
            [self::mapperOfAnyString($spelling, 'spelled'), self::mapper(Exception::class, 'exception')],
            new DefaultExceptionMapper(),
        );

        self::assertSame('spelled', self::winner($mappers->map(new UnexpectedValueException('failed'))));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function spellings(): array
    {
        return [
            'lower case' => [strtolower(RuntimeException::class)],
            'a leading backslash' => ['\\' . RuntimeException::class],
        ];
    }

    #[DataProvider('refusedTypes')]
    public function testAMapperThatCouldNeverBeChosenIsRefused(string $type, string $message): void
    {
        try {
            new ExceptionMappers([self::mapperOfAnyString($type, 'refused')], new DefaultExceptionMapper());
        } catch (InvalidArgumentException $exception) {
            self::assertSame($message, $exception->getMessage());
            self::assertStringNotContainsString("\0", $exception->getMessage());

            return;
        }

        self::fail('the mappers were built');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedTypes(): array
    {
        $anonymousClass = (new class {})::class;
        $anonymousException = (new class (418, [new ErrorDescription('teapot')]) extends JsonApiException {})::class;

        return [
            'no type at all' => [
                'no_such_type',
                'an exception mapper maps a class or an interface, got "no_such_type"',
            ],
            'the empty string' => ['', 'an exception mapper maps a class or an interface, got ""'],
            'a name holding a line break and a DEL' => [
                "No\nSuch\x7f",
                'an exception mapper maps a class or an interface, got "No\\nSuch\\177"',
            ],
            'a class that is no throwable' => [
                stdClass::class,
                'an exception mapper maps a throwable, got ' . stdClass::class,
            ],
            'an interface that is no throwable' => [
                Countable::class,
                'an exception mapper maps a throwable, got ' . Countable::class,
            ],
            'an enum' => [ErrorCode::class, 'an exception mapper maps a throwable, got ' . ErrorCode::class],
            'an anonymous class that is no throwable' => [
                $anonymousClass,
                'an exception mapper maps a throwable, got ' . self::escaped($anonymousClass),
            ],
            'the base of the library exceptions' => [
                JsonApiException::class,
                'an exception mapper cannot map ' . JsonApiException::class . ': a JSON:API exception maps to itself',
            ],
            'a library exception' => [
                NotFoundException::class,
                'an exception mapper cannot map ' . NotFoundException::class . ': a JSON:API exception maps to itself',
            ],
            'an anonymous JSON:API exception' => [
                $anonymousException,
                'an exception mapper cannot map '
                    . self::escaped($anonymousException)
                    . ': a JSON:API exception maps to itself',
            ],
        ];
    }

    #[DataProvider('secondSpellings')]
    public function testTwoMappersOfOneTypeAreRefused(string $second): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'two exception mappers map ' . RuntimeException::class . ', and only the first could ever be chosen',
        );

        new ExceptionMappers(
            [
                self::mapper(RuntimeException::class, 'first'),
                self::mapper(LogicException::class, 'between'),
                self::mapperOfAnyString($second, 'second'),
            ],
            new DefaultExceptionMapper(),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secondSpellings(): array
    {
        return [
            'the same spelling' => [RuntimeException::class],
            'lower case' => [strtolower(RuntimeException::class)],
            'a leading backslash' => ['\\' . RuntimeException::class],
        ];
    }

    public function testTwoMappersOfOneAnonymousClassAreRefusedWithTheNameEscaped(): void
    {
        $type = (new class ('failed') extends RuntimeException {})::class;

        try {
            new ExceptionMappers(
                [self::mapper($type, 'first'), self::mapper($type, 'second')],
                new DefaultExceptionMapper(),
            );
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'two exception mappers map ' . self::escaped($type) . ', and only the first could ever be chosen',
                $exception->getMessage(),
            );
            self::assertStringNotContainsString("\0", $exception->getMessage());

            return;
        }

        self::fail('the mappers were built');
    }

    /**
     * only the given mapper has debug output enabled, so `meta.exceptions` proves it was used
     */
    public function testTheDefaultMapperGivenIsTheOneUsed(): void
    {
        $mappers = new ExceptionMappers([], new DefaultExceptionMapper(true));

        self::assertArrayHasKey('exceptions', $mappers->map(new RuntimeException('failed'))->descriptions()[0]->meta);
    }

    /**
     * every permutation, the given order first
     *
     * @param list<class-string<Throwable>> $types
     *
     * @return list<list<class-string<Throwable>>>
     */
    private static function orders(array $types): array
    {
        if (count($types) <= 1) {
            return [$types];
        }

        $orders = [];

        foreach ($types as $index => $first) {
            $rest = $types;
            unset($rest[$index]);

            foreach (self::orders(array_values($rest)) as $order) {
                $orders[] = [$first, ...$order];
            }
        }

        return $orders;
    }

    /**
     * a class name as the message writes it: the NUL byte of an anonymous class as `\000`
     */
    private static function escaped(string $name): string
    {
        return str_replace("\0", '\\000', $name);
    }

    private static function winner(JsonApiException $mapped): string
    {
        $code = $mapped->descriptions()[0]->code;

        return $code instanceof ErrorCode ? $code->value : $code;
    }

    /**
     * @param class-string<Throwable> $type
     *
     * @return ExceptionMapper<Throwable>
     */
    private static function mapper(string $type, string $name): ExceptionMapper
    {
        /** @implements ExceptionMapper<Throwable> */
        return new class ($type, $name) implements ExceptionMapper {
            /**
             * @param class-string<Throwable> $type
             */
            public function __construct(private string $type, private readonly string $name) {}

            /**
             * @return class-string<Throwable>
             */
            #[Override]
            public function exceptionClass(): string
            {
                return $this->type;
            }

            #[Override]
            public function map(Throwable $exception): JsonApiException
            {
                return new ConflictException([new ErrorDescription($this->name)], $exception);
            }
        };
    }

    /**
     * PHP does not enforce `class-string`, so a project mapper may return any string;
     * the value is injected by reflection
     *
     * @return ExceptionMapper<Throwable>
     */
    private static function mapperOfAnyString(string $type, string $name): ExceptionMapper
    {
        $mapper = self::mapper(Throwable::class, $name);

        (new ReflectionProperty($mapper, 'type'))->setValue($mapper, $type);

        return $mapper;
    }

    /**
     * @param class-string<Throwable> $type
     *
     * @return ExceptionMapper<Throwable>
     */
    private static function failingMapper(string $type, Throwable $failure): ExceptionMapper
    {
        /** @implements ExceptionMapper<Throwable> */
        return new class ($type, $failure) implements ExceptionMapper {
            /**
             * @param class-string<Throwable> $type
             */
            public function __construct(private string $type, private readonly Throwable $failure) {}

            /**
             * @return class-string<Throwable>
             */
            #[Override]
            public function exceptionClass(): string
            {
                return $this->type;
            }

            #[Override]
            public function map(Throwable $exception): JsonApiException
            {
                throw $this->failure;
            }
        };
    }

    /**
     * @return ExceptionMapper<Throwable>
     */
    private static function failingMapperOfAnyString(string $type, Throwable $failure): ExceptionMapper
    {
        $mapper = self::failingMapper(Throwable::class, $failure);

        (new ReflectionProperty($mapper, 'type'))->setValue($mapper, $type);

        return $mapper;
    }
}
