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

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Tests\Fixtures\Error\RecordNotFoundException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Throwable;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ExceptionMapperTest extends TestCase
{
    /**
     * a project implements the port, so its signature, the parameter name included, is public API
     */
    public function testThePortIsAPublicInterfaceOfExceptionClassAndMap(): void
    {
        $exceptionClass = new ReflectionMethod(ExceptionMapper::class, 'exceptionClass');
        $map = new ReflectionMethod(ExceptionMapper::class, 'map');
        $parameter = $map->getParameters()[0] ?? null;

        self::assertSame([], $exceptionClass->getParameters());
        self::assertSame('string', (string) $exceptionClass->getReturnType());
        self::assertInstanceOf(ReflectionParameter::class, $parameter);
        self::assertCount(1, $map->getParameters());
        self::assertSame('exception', $parameter->getName());
        self::assertSame(Throwable::class, (string) $parameter->getType());
        self::assertSame(JsonApiException::class, (string) $map->getReturnType());
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(ExceptionMapper::class))->getDocComment(),
        );
    }

    public function testTheTypeIsACovariantTemplate(): void
    {
        $interface = (string) (new ReflectionClass(ExceptionMapper::class))->getDocComment();
        $exceptionClass = (string) (new ReflectionMethod(ExceptionMapper::class, 'exceptionClass'))->getDocComment();

        self::assertMatchesRegularExpression(
            '/@template-covariant\s+T\s+of\s+' . preg_quote(Throwable::class, '/') . '$/m',
            $interface,
        );
        self::assertMatchesRegularExpression('/@return\s+class-string<T>/', $exceptionClass);
    }

    public function testAProjectMapsItsOwnException(): void
    {
        /** @implements ExceptionMapper<RecordNotFoundException> */
        $mapper = new class implements ExceptionMapper {
            #[Override]
            public function exceptionClass(): string
            {
                return RecordNotFoundException::class;
            }

            #[Override]
            public function map(Throwable $exception): JsonApiException
            {
                return new NotFoundException([new ErrorDescription(ErrorCode::NotFound)], $exception);
            }
        };

        $record = new RecordNotFoundException('pet 7 does not exist');
        $mapped = $mapper->map($record);

        self::assertSame(RecordNotFoundException::class, $mapper->exceptionClass());
        self::assertSame(404, $mapped->status());
        self::assertSame($record, $mapped->getPrevious());
    }
}
