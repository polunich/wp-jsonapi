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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResponseShape::class)]
final class ResponseShapeTest extends TestCase
{
    public function testTheShapeIsAPublicFinalReadonlyValue(): void
    {
        $class = new ReflectionClass(ResponseShape::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
    }

    #[DataProvider('statuses')]
    public function testEachShapeOfAResponseHasItsStatus(ResponseShape $shape, int $status): void
    {
        self::assertSame($status, $shape->status());
    }

    /**
     * @return iterable<string, array{ResponseShape, int}>
     */
    public static function statuses(): iterable
    {
        yield 'a resource' => [ResponseShape::resource('pets'), 200];
        yield 'a record' => [ResponseShape::record('id'), 200];
        yield 'a created resource' => [ResponseShape::created('pets'), 201];
        yield 'a collection' => [ResponseShape::collection('pets'), 200];
        yield 'a page' => [ResponseShape::page('pets'), 200];
        yield 'related resources' => [ResponseShape::related('id', 'owner'), 200];
        yield 'a linkage' => [ResponseShape::linkage('id', 'owner'), 200];
        yield 'meta' => [ResponseShape::meta(new IntegerCodec()), 200];
        yield 'a monitor' => [ResponseShape::accepted('jobs'), 202];
        yield 'no content' => [ResponseShape::noContent(), 204];
    }
}
