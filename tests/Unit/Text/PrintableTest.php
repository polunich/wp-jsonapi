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

namespace Polunich\WpJsonApi\Tests\Unit\Text;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Text\Printable;

/**
 * @internal
 */
#[CoversClass(Printable::class)]
final class PrintableTest extends TestCase
{
    #[DataProvider('texts')]
    public function testEveryControlCharacterIsWrittenAsItsEscapeAndNothingElseChanges(
        string $value,
        string $printable,
    ): void {
        self::assertSame($printable, Printable::of($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'a text without control characters' => ['pets "and" owners', 'pets "and" owners'];
        yield 'a line feed and a carriage return' => ["a\r\nb", 'a\r\nb'];
        yield 'NUL, the first control character' => ["a\0b", 'a\000b'];
        yield 'US, the last one below the space' => ["a\x1Fb", 'a\037b'];
        yield 'DEL' => ["a\x7Fb", 'a\177b'];
        yield 'a character above U+007F' => ["caf\u{e9}", "caf\u{e9}"];
    }
}
