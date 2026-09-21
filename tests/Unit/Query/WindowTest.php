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

namespace Polunich\WpJsonApi\Tests\Unit\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Window;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Window::class)]
final class WindowTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Window::class))->isReadOnly());
    }

    public function testTheLimitIsThePageSizePlusOne(): void
    {
        $window = new Window(40, 21);

        self::assertSame(40, $window->offset);
        self::assertSame(21, $window->limit);
    }

    public function testTheFirstWindowSkipsNoRecord(): void
    {
        self::assertSame(0, (new Window(0, 21))->offset);
    }

    public function testRefusesAWindowThatSkipsFewerRecordsThanNone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fewer than none');

        new Window(-1, 21);
    }

    #[DataProvider('emptyWindows')]
    public function testRefusesAWindowThatReadsNoRecord(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('it reads at least one');

        new Window(0, $limit);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function emptyWindows(): iterable
    {
        yield 'no record' => [0];
        yield 'fewer than none' => [-1];
    }

    public function testTheSmallestWindowReadsOneRecord(): void
    {
        self::assertSame(1, (new Window(0, 1))->limit);
    }
}
