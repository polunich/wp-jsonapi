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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Pointer;

/**
 * @internal
 */
#[CoversClass(Pointer::class)]
final class PointerTest extends TestCase
{
    public function testRootIsTheEmptyString(): void
    {
        self::assertSame('', Pointer::root()->toString());
    }

    public function testDescendPrefixesEveryTokenWithASlash(): void
    {
        $pointer = Pointer::root()->descend('data')->descend('attributes')->descend('name');

        self::assertSame('/data/attributes/name', $pointer->toString());
    }

    #[DataProvider('escapedTokens')]
    public function testDescendEscapesTheReferenceToken(string $token, string $expected): void
    {
        self::assertSame($expected, Pointer::root()->descend($token)->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function escapedTokens(): iterable
    {
        yield 'plain' => ['name', '/name'];
        yield 'empty' => ['', '/'];
        yield 'digits' => ['0', '/0'];
        yield 'slash' => ['a/b', '/a~1b'];
        yield 'tilde' => ['a~b', '/a~0b'];
        yield 'both' => ['a/b~c', '/a~1b~0c'];
        yield 'an escape of the pointer syntax' => ['~1', '/~01'];
        yield 'an escape of the pointer syntax, the other way' => ['~0', '/~00'];
    }

    public function testEscapingIsReversible(): void
    {
        $pointer = Pointer::root()->descend('~1');

        self::assertSame('/~01', $pointer->toString());
        self::assertNotSame('//', $pointer->toString());
    }

    public function testDescendLeavesThePointerItAscendsFromUnchanged(): void
    {
        $root = Pointer::root();
        $data = $root->descend('data');
        $id = $data->descend('id');

        self::assertSame('', $root->toString());
        self::assertSame('/data', $data->toString());
        self::assertSame('/data/id', $id->toString());
    }

    public function testStringCastGivesTheSameStringAsToString(): void
    {
        $pointer = Pointer::root()->descend('data')->descend('0');

        self::assertSame($pointer->toString(), (string) $pointer);
    }
}
