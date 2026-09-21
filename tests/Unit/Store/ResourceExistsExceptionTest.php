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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * @internal
 */
#[CoversClass(ResourceExistsException::class)]
final class ResourceExistsExceptionTest extends TestCase
{
    /**
     * a `RuntimeException`, not a class of the Error module, which Store may not depend on
     */
    public function testTheClassIsAFinalRuntimeException(): void
    {
        $class = new ReflectionClass(ResourceExistsException::class);

        self::assertTrue($class->isFinal());
        self::assertSame(
            RuntimeException::class,
            $class->getParentClass() !== false ? $class->getParentClass()->getName() : null,
        );
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(ResourceExistsException::class))->getDocComment(),
        );
    }

    /**
     * the parameter name is public API
     */
    public function testTheConstructorTakesThePreviousThrowableAlone(): void
    {
        self::assertSame(
            [['previous', '?' . Throwable::class, true, null]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [
                        $parameter->getName(),
                        (string) $parameter->getType(),
                        $parameter->isOptional(),
                        $parameter->isOptional() ? $parameter->getDefaultValue() : null,
                    ];
                },
                (new ReflectionMethod(ResourceExistsException::class, '__construct'))->getParameters(),
            ),
        );
    }

    public function testTheMessageDescribesTheFailure(): void
    {
        $exception = new ResourceExistsException();

        self::assertSame(
            'a resource of the type already exists with the client-generated id of the create',
            $exception->getMessage(),
        );
        self::assertSame(0, $exception->getCode());
        self::assertNull($exception->getPrevious());
    }

    public function testThePreviousThrowableIsCarried(): void
    {
        $previous = new RuntimeException('the store said no');

        self::assertSame($previous, (new ResourceExistsException(previous: $previous))->getPrevious());
    }
}
