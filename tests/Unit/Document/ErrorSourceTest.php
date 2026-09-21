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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\ErrorSource;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ErrorSource::class)]
final class ErrorSourceTest extends TestCase
{
    #[DataProvider('sources')]
    public function testExactlyOneMemberIsSet(
        ErrorSource $source,
        ?string $pointer,
        ?string $parameter,
        ?string $header,
    ): void {
        self::assertSame($pointer, $source->pointer);
        self::assertSame($parameter, $source->parameter);
        self::assertSame($header, $source->header);
    }

    /**
     * @return iterable<string, array{ErrorSource, string|null, string|null, string|null}>
     */
    public static function sources(): iterable
    {
        yield 'pointer' => [ErrorSource::pointer('/data/attributes/title'), '/data/attributes/title', null, null];
        yield 'parameter' => [ErrorSource::parameter('filter[city][@eq]'), null, 'filter[city][@eq]', null];
        yield 'header' => [ErrorSource::header('If-Match'), null, null, 'If-Match'];
    }

    public function testTheConstructorIsPrivate(): void
    {
        $constructor = (new ReflectionClass(ErrorSource::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testAPointerOfAnOperationOfAnAtomicRequestIsAPointer(): void
    {
        self::assertSame('/atomic:operations/1', ErrorSource::pointer('/atomic:operations/1')->pointer);
    }
}
