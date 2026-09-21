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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\FirstViolation;

/**
 * @internal
 */
#[CoversClass(IntegerCodec::class)]
final class IntegerCodecTest extends TestCase
{
    public function testAnIntegerIsReturnedAsItIs(): void
    {
        self::assertSame(42, (new IntegerCodec())->decode(42, Pointer::root()));
    }

    #[DataProvider('floatsThatNameAnInteger')]
    public function testAFloatWithAZeroFractionalPartIsDecodedAsThatInteger(float $input, int $expected): void
    {
        self::assertSame($expected, (new IntegerCodec())->decode($input, Pointer::root()));
    }

    /**
     * @return iterable<string, array{float, int}>
     */
    public static function floatsThatNameAnInteger(): iterable
    {
        yield 'zero' => [0.0, 0];
        yield 'a small number' => [42.0, 42];
        yield 'a negative number' => [-42.0, -42];
        yield 'the largest float below 2^63' => [9.2233720368547748E+18, 9223372036854774784];
        yield 'PHP_INT_MIN, which a float holds exactly' => [-9.2233720368547758E+18, PHP_INT_MIN];
    }

    public function testTheLargestFloatThatNamesAnIntegerIsAccepted(): void
    {
        $largest = 9.2233720368547748E+18;

        self::assertSame('9223372036854774784', sprintf('%.0F', $largest));
        self::assertSame(9223372036854774784, (new IntegerCodec())->decode($largest, Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoInteger')]
    public function testAValueThatNamesNoIntegerIsRefused(mixed $input): void
    {
        $violation = FirstViolation::of(new IntegerCodec(), $input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => JsonType::Integer->value], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoInteger(): iterable
    {
        yield 'null' => [null];
        yield 'a string of digits' => ['1'];
        yield 'a boolean' => [true];
        yield 'a list' => [[1]];
        yield 'an object' => [(object) ['a' => 1]];
        yield 'a fractional number' => [1.5];
        yield 'not a number' => [NAN];
        yield 'infinity, the value of a JSON number above PHP_FLOAT_MAX' => [INF];
        yield 'negative infinity' => [-INF];
    }

    /**
     * @param mixed $input as `json_decode()` returns it
     */
    #[DataProvider('numbersOutsideThePlatformRange')]
    public function testAWholeNumberOutsideThePlatformRangeIsRefusedAsOutOfRange(
        mixed $input,
        string $code,
        string $parameter,
        int $bound,
    ): void {
        $violation = FirstViolation::of(new IntegerCodec(), $input);

        self::assertSame($code, $violation->code);
        self::assertSame([$parameter => $bound], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed, string, string, int}>
     */
    public static function numbersOutsideThePlatformRange(): iterable
    {
        yield 'a number above the largest integer a float names' => [
            json_decode('12345678901234567890', false, flags: JSON_THROW_ON_ERROR),
            'value_too_large',
            'maximum',
            9223372036854774784,
        ];
        yield '2^63, the first float above the largest integer a float names' => [
            9.2233720368547758E+18,
            'value_too_large',
            'maximum',
            9223372036854774784,
        ];
        yield 'an integer above the largest integer a float names' => [
            PHP_INT_MAX,
            'value_too_large',
            'maximum',
            9223372036854774784,
        ];
        yield 'a number below PHP_INT_MIN' => [
            json_decode('-12345678901234567890', false, flags: JSON_THROW_ON_ERROR),
            'value_too_small',
            'minimum',
            PHP_INT_MIN,
        ];
    }

    public function testTheSchemaCarriesThePlatformBoundsWhenTheDeclarationNamesNone(): void
    {
        self::assertSame(
            ['type' => 'integer', 'minimum' => PHP_INT_MIN, 'maximum' => 9223372036854774784],
            get_object_vars((new IntegerCodec())->schema()->toObject()),
        );
    }

    public function testAMaximumAboveTheLargestIntegerAFloatNamesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the maximum must not be above the largest integer a JSON number names exactly: 9223372036854774784',
        );

        new IntegerCodec(maximum: PHP_INT_MAX);
    }

    public function testAMinimumAboveTheLargestIntegerAFloatNamesIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the minimum must not be above the largest integer a JSON number names exactly: 9223372036854774784',
        );

        new IntegerCodec(minimum: PHP_INT_MAX);
    }

    public function testABoundAtTheLargestIntegerAFloatNamesIsAccepted(): void
    {
        $codec = new IntegerCodec(minimum: 9223372036854774784, maximum: 9223372036854774784);

        self::assertSame(9223372036854774784, $codec->decode(9223372036854774784, Pointer::root()));
    }

    public function testANumberBelowTheMinimumIsRefused(): void
    {
        $violation = FirstViolation::of(new IntegerCodec(minimum: 10), 9);

        self::assertSame('value_too_small', $violation->code);
        self::assertSame(['minimum' => 10], $violation->parameters);
    }

    public function testANumberAboveTheMaximumIsRefused(): void
    {
        $violation = FirstViolation::of(new IntegerCodec(maximum: 10), 11);

        self::assertSame('value_too_large', $violation->code);
        self::assertSame(['maximum' => 10], $violation->parameters);
    }

    public function testTheBoundsAreInclusive(): void
    {
        $codec = new IntegerCodec(minimum: 10, maximum: 10);

        self::assertSame(10, $codec->decode(10, Pointer::root()));
    }

    public function testAnEmptyRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the minimum must not be greater than the maximum');

        new IntegerCodec(minimum: 2, maximum: 1);
    }

    public function testTheSchemaCarriesEveryKeywordTheCodecEnforces(): void
    {
        $schema = (new IntegerCodec(minimum: 0, maximum: 10))->schema();

        self::assertSame(['type' => 'integer', 'minimum' => 0, 'maximum' => 10], get_object_vars($schema->toObject()));
    }

    public function testEncodeReturnsTheInteger(): void
    {
        self::assertSame(42, (new IntegerCodec())->encode(42));
    }

    /**
     * `decode()` accepts `42.0` but returns an int,
     * and `encode()` takes only what `decode()` returns
     */
    public function testEncodeRefusesAValueOfAnotherType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be an integer');

        (new IntegerCodec())->encode(42.0);
    }

    /**
     * @param non-empty-string $code
     */
    #[DataProvider('integersOutsideTheBounds')]
    public function testEncodeRefusesAnIntegerOutsideTheBounds(IntegerCodec $codec, int $value, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value is not one decode() returns, which refuses it: ' . $code . ' at ""');

        $codec->encode($value);
    }

    /**
     * @return iterable<string, array{IntegerCodec, int, non-empty-string}>
     */
    public static function integersOutsideTheBounds(): iterable
    {
        yield 'below the minimum' => [new IntegerCodec(0, 10), -1, 'value_too_small'];
        yield 'above the maximum' => [new IntegerCodec(0, 10), 11, 'value_too_large'];
        yield 'above the largest integer a JSON number names exactly' => [
            new IntegerCodec(),
            PHP_INT_MAX,
            'value_too_large',
        ];
    }
}
