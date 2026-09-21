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
use Polunich\WpJsonApi\Http\BasicLanguageRange;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(BasicLanguageRange::class)]
final class BasicLanguageRangeTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(BasicLanguageRange::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('ranges')]
    public function testARangeIsOneToEightLettersThenSubtagsOfOneToEightLettersOrDigits(
        string $range,
        bool $valid,
    ): void {
        self::assertSame($valid, BasicLanguageRange::isValid($range));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function ranges(): iterable
    {
        yield 'a primary subtag' => ['uk', true];
        yield 'a region' => ['en-GB', true];
        yield 'a script and a region' => ['zh-Hant-TW', true];
        yield 'a subtag of digits' => ['es-419', true];
        yield 'eight letters' => ['abcdefgh', true];
        yield 'nine letters' => ['abcdefghi', false];
        yield 'a primary subtag of digits' => ['419', false];
        yield 'the wildcard' => ['*', false];
        yield 'the empty string' => ['', false];
        yield 'an empty subtag' => ['en--GB', false];
        yield 'a trailing hyphen' => ['en-', false];
        yield 'a low line' => ['en_GB', false];
        yield 'a trailing line feed' => ["uk\n", false];
        yield 'a space' => ['uk ', false];
    }

}
