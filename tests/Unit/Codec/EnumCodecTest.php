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

use BackedEnum;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\EnumCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\FirstViolation;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\NoCases;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Priority;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Shade;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Unbacked;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(EnumCodec::class)]
final class EnumCodecTest extends TestCase
{
    public function testABackingValueIsDecodedAsItsCase(): void
    {
        self::assertSame(Priority::Low, (new EnumCodec(Priority::class))->decode(1, Pointer::root()));
        self::assertSame(Shade::Dark, (new EnumCodec(Shade::class))->decode('dark', Pointer::root()));
    }

    public function testAFloatThatNamesTheBackingIntegerIsDecodedAsItsCase(): void
    {
        self::assertSame(Priority::Low, (new EnumCodec(Priority::class))->decode(1.0, Pointer::root()));
    }

    public function testAValueThatIsNoCaseIsRefusedWithTheAllowedValues(): void
    {
        $violation = FirstViolation::of(new EnumCodec(Priority::class), 3);

        self::assertSame('value_not_allowed', $violation->code);
        self::assertSame(['allowed' => '1, 2'], $violation->parameters);
    }

    public function testAStringThatIsNoCaseIsRefusedWithTheAllowedValues(): void
    {
        $violation = FirstViolation::of(new EnumCodec(Shade::class), 'grey');

        self::assertSame('value_not_allowed', $violation->code);
        self::assertSame(['allowed' => 'light, dark'], $violation->parameters);
    }

    /**
     * @param class-string<BackedEnum> $enumClass
     */
    #[DataProvider('valuesOfTheWrongType')]
    public function testAValueOfAnotherTypeIsRefused(string $enumClass, mixed $input, string $expected): void
    {
        $violation = FirstViolation::of(new EnumCodec($enumClass), $input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => $expected], $violation->parameters);
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, mixed, string}>
     */
    public static function valuesOfTheWrongType(): iterable
    {
        yield 'a string for an int backed enum' => [Priority::class, '1', 'integer'];
        yield 'a fractional number for an int backed enum' => [Priority::class, 1.5, 'integer'];
        yield 'null for an int backed enum' => [Priority::class, null, 'integer'];
        yield 'a boolean for an int backed enum' => [Priority::class, true, 'integer'];
        yield 'an integer for a string backed enum' => [Shade::class, 1, 'string'];
        yield 'null for a string backed enum' => [Shade::class, null, 'string'];
        yield 'an object for a string backed enum' => [Shade::class, (object) ['a' => 'light'], 'string'];
    }

    public function testANumberOutsideTheRangeOfTheBackingCodecIsRefusedWithTheAllowedValues(): void
    {
        $violation = FirstViolation::of(
            new EnumCodec(Priority::class),
            json_decode('12345678901234567890', false, flags: JSON_THROW_ON_ERROR),
        );

        self::assertSame('value_not_allowed', $violation->code);
        self::assertSame(['allowed' => '1, 2'], $violation->parameters);
    }

    /**
     * `class-string<BackedEnum>` keeps a pure enum out of every analysed call,
     * so the guard is reached through reflection
     */
    public function testAPureEnumIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the enum must be a backed enum: ' . Unbacked::class);

        (new ReflectionClass(EnumCodec::class))->newInstance(Unbacked::class);
    }

    /**
     * PHP allows a backed enum without a case, which would leave nothing to decode or to list
     */
    public function testABackedEnumWithoutACaseIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the enum must declare at least one case');

        new EnumCodec(NoCases::class);
    }

    public function testTheSchemaOfAnIntBackedEnumNamesTheIntegerTypeAndTheCases(): void
    {
        self::assertSame(
            ['type' => 'integer', 'enum' => [1, 2]],
            get_object_vars((new EnumCodec(Priority::class))->schema()->toObject()),
        );
    }

    public function testTheSchemaOfAStringBackedEnumNamesTheStringTypeAndTheCases(): void
    {
        self::assertSame(
            ['type' => 'string', 'enum' => ['light', 'dark']],
            get_object_vars((new EnumCodec(Shade::class))->schema()->toObject()),
        );
    }

    public function testEncodeReturnsTheBackingValue(): void
    {
        self::assertSame(2, (new EnumCodec(Priority::class))->encode(Priority::High));
        self::assertSame('light', (new EnumCodec(Shade::class))->encode(Shade::Light));
    }

    #[DataProvider('valuesEncodeRefuses')]
    public function testEncodeRefusesAValueThatIsNoCaseOfTheEnum(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a case of the enum of the codec');

        (new EnumCodec(Priority::class))->encode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesEncodeRefuses(): iterable
    {
        yield 'the backing value itself' => [1];
        yield 'a case of another enum' => [Shade::Light];
        yield 'null' => [null];
    }
}
