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
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(DigitsCodec::class)]
final class DigitsCodecTest extends TestCase
{
    private const PATTERN = '^(?:0|[1-9][0-9]*)$';

    #[DataProvider('numbers')]
    public function testANumberIsDecodedAsTheSameString(string $input): void
    {
        self::assertSame($input, (new DigitsCodec())->decode($input, Pointer::root()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function numbers(): iterable
    {
        yield 'zero' => ['0'];
        yield 'one digit' => ['7'];
        yield 'a zero after the first digit' => ['10'];
        yield 'the largest PHP int' => [(string) PHP_INT_MAX];
        yield 'a number above the largest PHP int' => ['92233720368547758070'];
    }

    #[DataProvider('stringsThatAreNoNumber')]
    public function testAStringThatIsNoNumberIsRefused(string $input): void
    {
        $violation = self::violation($input);

        self::assertSame('pattern_mismatch', $violation->code);
        self::assertSame(['pattern' => self::PATTERN], $violation->parameters);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringsThatAreNoNumber(): iterable
    {
        yield 'an empty string' => [''];
        yield 'a leading zero' => ['01'];
        yield 'two zeros' => ['00'];
        yield 'a sign' => ['-1'];
        yield 'a plus sign' => ['+1'];
        yield 'a fraction' => ['1.0'];
        yield 'an exponent' => ['1e3'];
        yield 'a trailing line feed' => ["1\n"];
        yield 'a leading space' => [' 1'];
        yield 'a trailing null byte' => ["1\0"];
        yield 'a percent-encoded digit' => ['%31'];
        yield 'a digit of another script' => ["\u{0661}"];
        yield 'a letter' => ['a1'];
    }

    #[DataProvider('valuesThatAreNoString')]
    public function testAValueThatIsNoStringIsRefused(mixed $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'string'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoString(): iterable
    {
        yield 'null' => [null];
        yield 'an integer' => [1];
        yield 'a float' => [1.0];
        yield 'a list' => [['1']];
        yield 'an object' => [(object) ['id' => '1']];
    }

    public function testTheSchemaNamesTheStringTypeAndThePatternDecodeEnforces(): void
    {
        self::assertSame(
            ['type' => 'string', 'pattern' => self::PATTERN],
            get_object_vars((new DigitsCodec())->schema()->toObject()),
        );
    }

    #[DataProvider('numbers')]
    public function testTheSegmentPatternAdmitsANumber(string $input): void
    {
        self::assertSame(1, preg_match(self::segment(), $input));
    }

    #[DataProvider('stringsThatAreNoNumber')]
    public function testTheSegmentPatternRefusesWhatDecodeRefuses(string $input): void
    {
        self::assertSame(0, preg_match(self::segment(), $input));
    }

    #[DataProvider('numbers')]
    public function testEncodeWritesANumberAsItIs(string $value): void
    {
        self::assertSame($value, (new DigitsCodec())->encode($value));
    }

    #[DataProvider('valuesEncodeRefuses')]
    public function testEncodeRefusesAValueThatIsNoNumber(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a string of decimal digits without a leading zero');

        (new DigitsCodec())->encode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesEncodeRefuses(): iterable
    {
        yield 'a leading zero' => ['01'];
        yield 'a trailing line feed' => ["1\n"];
        yield 'an integer' => [1];
        yield 'null' => [null];
    }

    /**
     * the segment pattern as a whole-string match that reads bytes, as a route pattern does
     */
    private static function segment(): string
    {
        return "\x07^(?:" . (new DigitsCodec())->segmentPattern() . ")$\x07D";
    }

    private static function violation(mixed $input): Violation
    {
        try {
            (new DigitsCodec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }
}
