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
use Polunich\WpJsonApi\Codec\NumberCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\FirstViolation;

/**
 * @internal
 */
#[CoversClass(NumberCodec::class)]
final class NumberCodecTest extends TestCase
{
    public function testAFloatIsReturnedAsItIs(): void
    {
        self::assertSame(1.5, (new NumberCodec())->decode(1.5, Pointer::root()));
    }

    public function testAnIntegerIsDecodedAsAFloat(): void
    {
        self::assertSame(1.0, (new NumberCodec())->decode(1, Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoNumber')]
    public function testAValueOfAnotherTypeIsRefused(mixed $input): void
    {
        $violation = FirstViolation::of(new NumberCodec(), $input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => JsonType::Number->value], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoNumber(): iterable
    {
        yield 'null' => [null];
        yield 'a string of digits' => ['1.5'];
        yield 'a boolean' => [true];
        yield 'a list' => [[1.5]];
        yield 'an object' => [(object) ['a' => 1.5]];
        yield 'not a number' => [NAN];
    }

    /**
     * @param mixed $input as `json_decode()` returns it
     */
    #[DataProvider('numbersOutsideThePlatformRange')]
    public function testANumberOutsideThePlatformRangeIsRefusedAsOutOfRange(
        mixed $input,
        string $code,
        string $parameter,
        float $bound,
    ): void {
        $violation = FirstViolation::of(new NumberCodec(), $input);

        self::assertSame($code, $violation->code);
        self::assertSame([$parameter => $bound], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed, string, string, float}>
     */
    public static function numbersOutsideThePlatformRange(): iterable
    {
        yield 'a number above PHP_FLOAT_MAX' => [
            json_decode('1e400', false, flags: JSON_THROW_ON_ERROR),
            'value_too_large',
            'maximum',
            PHP_FLOAT_MAX,
        ];
        yield 'a number below -PHP_FLOAT_MAX' => [
            json_decode('-1e400', false, flags: JSON_THROW_ON_ERROR),
            'value_too_small',
            'minimum',
            -PHP_FLOAT_MAX,
        ];
    }

    public function testTheSchemaCarriesThePlatformBoundsWhenTheDeclarationNamesNone(): void
    {
        self::assertSame(
            ['type' => 'number', 'minimum' => -PHP_FLOAT_MAX, 'maximum' => PHP_FLOAT_MAX],
            get_object_vars((new NumberCodec())->schema()->toObject()),
        );
    }

    /**
     * `json_encode()` cannot write an infinite or NaN bound into a schema
     */
    #[DataProvider('boundsThatAreNoFiniteNumber')]
    public function testABoundThatIsNoFiniteNumberIsRefused(
        ?float $minimum,
        ?float $maximum,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new NumberCodec(minimum: $minimum, maximum: $maximum);
    }

    /**
     * @return iterable<string, array{?float, ?float, string}>
     */
    public static function boundsThatAreNoFiniteNumber(): iterable
    {
        yield 'an infinite minimum' => [-INF, null, 'the minimum must be a finite number'];
        yield 'a minimum that is not a number' => [NAN, null, 'the minimum must be a finite number'];
        yield 'an infinite maximum' => [null, INF, 'the maximum must be a finite number'];
        yield 'a maximum that is not a number' => [null, NAN, 'the maximum must be a finite number'];
    }

    public function testANumberBelowTheMinimumIsRefused(): void
    {
        $violation = FirstViolation::of(new NumberCodec(minimum: 1.5), 1.4);

        self::assertSame('value_too_small', $violation->code);
        self::assertSame(['minimum' => 1.5], $violation->parameters);
    }

    public function testANumberAboveTheMaximumIsRefused(): void
    {
        $violation = FirstViolation::of(new NumberCodec(maximum: 1.5), 1.6);

        self::assertSame('value_too_large', $violation->code);
        self::assertSame(['maximum' => 1.5], $violation->parameters);
    }

    public function testTheBoundsAreInclusive(): void
    {
        $codec = new NumberCodec(minimum: 1.5, maximum: 1.5);

        self::assertSame(1.5, $codec->decode(1.5, Pointer::root()));
    }

    public function testAnEmptyRangeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the minimum must not be greater than the maximum');

        new NumberCodec(minimum: 2.0, maximum: 1.0);
    }

    public function testTheSchemaCarriesEveryKeywordTheCodecEnforces(): void
    {
        $schema = (new NumberCodec(minimum: 0.0, maximum: 1.5))->schema();

        self::assertSame(
            ['type' => 'number', 'minimum' => 0.0, 'maximum' => 1.5],
            get_object_vars($schema->toObject()),
        );
    }

    public function testEncodeTurnsAnIntegerIntoAFloat(): void
    {
        self::assertSame(1.0, (new NumberCodec())->encode(1));
    }

    public function testEncodeReturnsTheFloat(): void
    {
        self::assertSame(1.5, (new NumberCodec())->encode(1.5));
    }

    #[DataProvider('valuesEncodeRefuses')]
    public function testEncodeRefusesAValueThatIsNoFiniteNumber(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a finite number');

        (new NumberCodec())->encode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesEncodeRefuses(): iterable
    {
        yield 'a string' => ['1.5'];
        yield 'not a number' => [NAN];
        yield 'infinity' => [INF];
    }

    /**
     * @param non-empty-string $code
     */
    #[DataProvider('numbersOutsideTheBounds')]
    public function testEncodeRefusesANumberOutsideTheBounds(int|float $value, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value is not one decode() returns, which refuses it: ' . $code . ' at ""');

        (new NumberCodec(0.5, 2.5))->encode($value);
    }

    /**
     * @return iterable<string, array{int|float, non-empty-string}>
     */
    public static function numbersOutsideTheBounds(): iterable
    {
        yield 'a float below the minimum' => [0.25, 'value_too_small'];
        yield 'an integer above the maximum' => [3, 'value_too_large'];
    }
}
