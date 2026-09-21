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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Operator;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(Operator::class)]
final class OperatorTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndPublicApi(): void
    {
        $class = new ReflectionClass(Operator::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testCarriesTheNameAndTheValueCodecOfTheProject(): void
    {
        $codec = new IntegerCodec();
        $operator = Operator::make('@nearby', $codec)->build();

        self::assertSame('@nearby', $operator->name);
        self::assertTrue($operator->isCustom());
        self::assertSame($codec, $operator->valueCodec(new StringCodec()));
    }

    #[DataProvider('impossibleNames')]
    public function testRefusesANameNoCustomOperatorCarriesWhereItIsWritten(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Operator::make($name, new StringCodec());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleNames(): iterable
    {
        yield 'no @' => ['nearby', 'custom operator "nearby" is no "@" followed by a member name'];
        yield 'the empty name' => ['', 'custom operator "" is no "@" followed by a member name'];
        yield 'a reserved character' => ['@near.by', 'custom operator "@near.by" is no "@" followed by a member name'];
        yield 'a space at the end' => ['@nearby ', 'custom operator "@nearby " is no "@" followed by a member name'];
        yield 'a built-in operator' => [
            '@eq',
            'custom operator "@eq" collides with the built-in operator of that name',
        ];
        yield 'a built-in text operator' => [
            '@containsi',
            'custom operator "@containsi" collides with the built-in operator of that name',
        ];
    }

    public function testReturnsTheSameOperatorEveryTime(): void
    {
        $operator = Operator::make('@nearby', new IntegerCodec());

        self::assertSame($operator->build(), $operator->build());
    }
}
