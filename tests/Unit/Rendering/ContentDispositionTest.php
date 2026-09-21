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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Rendering\ContentDisposition;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ContentDisposition::class)]
final class ContentDispositionTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ContentDisposition::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('names')]
    public function testAnAttachmentCarriesItsNameAsRfc6266Advises(string $name, string $expected): void
    {
        self::assertSame($expected, ContentDisposition::attachment($name));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'printable ASCII' => ['pets-2026.csv', 'attachment; filename="pets-2026.csv"'];
        yield 'a space' => ['pet list.csv', 'attachment; filename="pet list.csv"'];
        yield 'a character beyond ASCII, the example of the RFC' => [
            "\u{20AC} rates",
            'attachment; filename="___ rates"; filename*=UTF-8\'\'%E2%82%AC%20rates',
        ];
        yield 'a quote, a backslash and a percent sign' => [
            'a"b\\c%41.csv',
            'attachment; filename="a_b_c_41.csv"; filename*=UTF-8\'\'a%22b%5Cc%2541.csv',
        ];
    }
}
