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
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\FirstViolation;

/**
 * @internal
 */
#[CoversClass(ConstCodec::class)]
final class ConstCodecTest extends TestCase
{
    public function testTheConstantIsReturned(): void
    {
        self::assertSame('pets', (new ConstCodec('pets'))->decode('pets', Pointer::root()));
    }

    public function testAnotherValueIsRefused(): void
    {
        $violation = FirstViolation::of(new ConstCodec('pets'), 'customers');

        self::assertSame('invalid_value', $violation->code);
        self::assertSame(['expected' => 'pets'], $violation->parameters);
    }

    #[DataProvider('numbersThatAreTheSameValue')]
    public function testANumberIsComparedByItsValue(int|float $value, int|float $input): void
    {
        $codec = new ConstCodec($value);

        self::assertSame($value, $codec->decode($input, Pointer::root()));
    }

    /**
     * @return iterable<string, array{int|float, int|float}>
     */
    public static function numbersThatAreTheSameValue(): iterable
    {
        yield 'an integer constant and a float instance' => [1, 1.0];
        yield 'a float constant and an integer instance' => [1.0, 1];
        yield 'an integer constant and an integer instance' => [1, 1];
        yield 'a float constant and a float instance' => [1.5, 1.5];
        yield 'zero and negative zero' => [0, -0.0];
    }

    #[DataProvider('valuesThatAreNotTheConstant')]
    public function testAValueOfAnotherTypeIsRefused(
        string|int|float|bool|null $value,
        mixed $input,
        string $code,
    ): void {
        self::assertSame($code, FirstViolation::of(new ConstCodec($value), $input)->code);
    }

    /**
     * @return iterable<string, array{string|int|float|bool|null, mixed, string}>
     */
    public static function valuesThatAreNotTheConstant(): iterable
    {
        yield 'a boolean for an integer constant' => [1, true, 'invalid_value'];
        yield 'an integer for a boolean constant' => [true, 1, 'invalid_value'];
        yield 'a string for an integer constant' => [1, '1', 'invalid_value'];
        yield 'an integer for a string constant' => ['1', 1, 'invalid_value'];
        yield 'null for an integer constant' => [1, null, 'invalid_value'];
        yield 'an integer for a null constant' => [null, 1, 'invalid_type'];
        yield 'a fractional number for an integer constant' => [1, 1.5, 'invalid_value'];
        yield 'a list for a string constant' => ['pets', ['pets'], 'invalid_value'];
        yield 'an object for a string constant' => ['pets', (object) ['a' => 'pets'], 'invalid_value'];
    }

    public function testANullConstantIsDescribedAndReportedAsTheNullType(): void
    {
        $codec = new ConstCodec(null);

        self::assertNull(self::decode($codec, null));
        self::assertSame(['type' => 'null'], get_object_vars($codec->schema()->toObject()));

        $violation = FirstViolation::of($codec, 'pets');

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'null'], $violation->parameters);
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[DataProvider('schemasOfAConstant')]
    public function testTheSchemaNamesTheTypeOfTheConstant(string|int|float|bool $value, array $expected): void
    {
        self::assertSame($expected, get_object_vars((new ConstCodec($value))->schema()->toObject()));
    }

    /**
     * @return iterable<string, array{string|int|float|bool, array<string, mixed>}>
     */
    public static function schemasOfAConstant(): iterable
    {
        yield 'a string' => ['pets', ['type' => 'string', 'const' => 'pets']];
        yield 'an integer' => [1, ['type' => 'integer', 'const' => 1]];
        yield 'a float' => [1.5, ['type' => 'number', 'const' => 1.5]];
        yield 'a boolean' => [true, ['type' => 'boolean', 'const' => true]];
    }

    public function testEncodeReturnsTheConstantInTheFormItWasDeclared(): void
    {
        $codec = new ConstCodec(1);

        self::assertSame(1, $codec->encode(1));
        self::assertSame(1, $codec->encode(1.0));
    }

    public function testEncodeRefusesAnotherValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be the constant of the codec');

        (new ConstCodec('pets'))->encode('customers');
    }

    /**
     * @param Codec<mixed> $codec
     */
    private static function decode(Codec $codec, mixed $input): mixed
    {
        return $codec->decode($input, Pointer::root());
    }
}
