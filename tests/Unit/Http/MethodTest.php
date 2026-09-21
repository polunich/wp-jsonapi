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

namespace Polunich\WpJsonApi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Method;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Method::class)]
final class MethodTest extends TestCase
{
    public function testTheClassIsReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(Method::class);

        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param non-empty-list<string> $served
     */
    #[DataProvider('servedMethods')]
    public function testARouteOfGetRespondsToHeadAsWell(string $declared, array $served): void
    {
        self::assertSame($served, Method::served($declared));
    }

    /**
     * @return iterable<string, array{string, non-empty-list<string>}>
     */
    public static function servedMethods(): iterable
    {
        yield 'GET' => ['GET', ['GET', 'HEAD']];
        yield 'POST' => ['POST', ['POST']];
        yield 'DELETE' => ['DELETE', ['DELETE']];
    }

    #[DataProvider('methods')]
    public function testTellsWhichMethodsRetrieveAndWhichAreSafe(string $method, bool $retrieves, bool $safe): void
    {
        self::assertSame($retrieves, Method::retrieves($method));
        self::assertSame($safe, Method::isSafe($method));
    }

    /**
     * "the GET, HEAD, OPTIONS, and TRACE methods are defined to be safe" (RFC 9110 9.2.1);
     * a method token is case-sensitive (RFC 9110 9.1)
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET', true, true];
        yield 'HEAD' => ['HEAD', true, true];
        yield 'OPTIONS' => ['OPTIONS', false, true];
        yield 'TRACE' => ['TRACE', false, true];
        yield 'POST' => ['POST', false, false];
        yield 'PUT' => ['PUT', false, false];
        yield 'PATCH' => ['PATCH', false, false];
        yield 'DELETE' => ['DELETE', false, false];
        yield 'get in lower case' => ['get', false, false];
    }
}
