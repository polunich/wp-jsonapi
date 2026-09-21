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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Endpoint\ResponseKind;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(ResponseKind::class)]
final class ResponseKindTest extends TestCase
{
    public function testTheEnumIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionEnum(ResponseKind::class))->getDocComment(),
        );
    }

    #[DataProvider('statuses')]
    public function testEachKindHasItsStatus(ResponseKind $kind, int $status): void
    {
        self::assertSame($status, $kind->status());
    }

    /**
     * @return iterable<string, array{ResponseKind, int}>
     */
    public static function statuses(): iterable
    {
        yield 'a resource' => [ResponseKind::Resource, 200];
        yield 'a created resource' => [ResponseKind::Created, 201];
        yield 'a collection' => [ResponseKind::Collection, 200];
        yield 'a linkage' => [ResponseKind::Linkage, 200];
        yield 'meta' => [ResponseKind::Meta, 200];
        yield 'a monitor' => [ResponseKind::Accepted, 202];
        yield 'no content' => [ResponseKind::NoContent, 204];
        yield 'a content' => [ResponseKind::Content, 200];
        yield 'a JSON value' => [ResponseKind::Json, 200];
    }
}
