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

namespace Polunich\WpJsonApi\Tests\Unit\Codec\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Schema\Format;

/**
 * @internal
 */
#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    public function testTheCasesAreDateDateTimeAndUuid(): void
    {
        self::assertSame(
            ['date', 'date-time', 'uuid'],
            array_map(static function (Format $format): string {
                return $format->value;
            }, Format::cases()),
        );
    }

    public function testEveryFormatIsFoundByItsWireValue(): void
    {
        foreach (Format::cases() as $format) {
            self::assertSame($format, Format::from($format->value));
        }
    }
}
