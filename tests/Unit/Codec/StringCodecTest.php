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
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\StringCodec;

/**
 * @internal
 */
#[CoversClass(StringCodec::class)]
final class StringCodecTest extends TestCase
{
    public function testAStringIsReturnedAsItIs(): void
    {
        self::assertSame('a name', (new StringCodec())->decode('a name', Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoString')]
    public function testAValueOfAnotherTypeIsRefused(mixed $input): void
    {
        $violation = self::violations(new StringCodec(), $input)[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => JsonType::String->value], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoString(): iterable
    {
        yield 'null' => [null];
        yield 'an integer' => [1];
        yield 'a float' => [1.5];
        yield 'a boolean' => [true];
        yield 'a list' => [['a']];
        yield 'an object' => [(object) ['a' => 'b']];
    }

    public function testTheLengthIsCountedInCodePoints(): void
    {
        $twoCodePoints = "\u{00e9}\u{00e9}";

        self::assertSame(4, strlen($twoCodePoints));
        self::assertSame($twoCodePoints, (new StringCodec(maxLength: 2))->decode($twoCodePoints, Pointer::root()));

        $violation = self::violations(new StringCodec(maxLength: 1), $twoCodePoints)[0];

        self::assertSame('value_too_long', $violation->code);
    }

    public function testAStringShorterThanTheMinimumIsRefused(): void
    {
        $violation = self::violations(new StringCodec(minLength: 3), 'ab')[0];

        self::assertSame('value_too_short', $violation->code);
        self::assertSame(['minLength' => 3], $violation->parameters);
    }

    public function testTheBoundsAreInclusive(): void
    {
        $codec = new StringCodec(minLength: 2, maxLength: 2);

        self::assertSame('ab', $codec->decode('ab', Pointer::root()));
    }

    public function testThePatternIsAnchoredAtTheEndOfTheSubject(): void
    {
        $violation = self::violations(new StringCodec(pattern: '^[a-z]+$'), "abc\n")[0];

        self::assertSame('pattern_mismatch', $violation->code);
        self::assertSame(['pattern' => '^[a-z]+$'], $violation->parameters);
    }

    /**
     * every other pattern here is anchored and would stay green
     * if patterns were wrapped in `\A` and `\z`; this case would fail
     */
    public function testAnUnanchoredPatternIsASearchAndNotAWholeMatch(): void
    {
        $codec = new StringCodec(pattern: 'b');

        self::assertSame('abc', $codec->decode('abc', Pointer::root()));
    }

    public function testThePatternCountsCodePoints(): void
    {
        $codec = new StringCodec(pattern: '^.$');

        self::assertSame("\u{00e9}", $codec->decode("\u{00e9}", Pointer::root()));
    }

    public function testEveryBrokenKeywordRaisesItsOwnViolation(): void
    {
        $violations = self::violations(new StringCodec(minLength: 5, pattern: '^[0-9]+$'), 'ab');

        self::assertSame(['value_too_short', 'pattern_mismatch'], array_column($violations, 'code'));
    }

    public function testTheViolationPointsAtTheValueItWasGiven(): void
    {
        $pointer = Pointer::root()->descend('data')->descend('attributes')->descend('name');

        $violation = self::violations(new StringCodec(), 1, $pointer)[0];

        self::assertSame('/data/attributes/name', $violation->pointer->toString());
    }

    #[DataProvider('refusedConstructions')]
    public function testAnImpossibleDeclarationIsRefused(
        ?int $minLength,
        ?int $maxLength,
        ?string $pattern,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new StringCodec($minLength, $maxLength, $pattern);
    }

    /**
     * @return iterable<string, array{?int, ?int, ?string, string}>
     */
    public static function refusedConstructions(): iterable
    {
        yield 'a negative minimum' => [-1, null, null, 'the minimum length must not be negative'];
        yield 'a negative maximum' => [null, -1, null, 'the maximum length must not be negative'];
        yield 'an empty range' => [3, 2, null, 'the minimum length must not be greater than the maximum length'];
        yield 'a broken pattern' => [null, null, '[', 'the pattern is not a valid regular expression: ['];
    }

    public function testTheSchemaCarriesEveryKeywordTheCodecEnforces(): void
    {
        $schema = (new StringCodec(minLength: 1, maxLength: 200, pattern: '^[a-z]+$'))->schema();

        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[a-z]+$'],
            get_object_vars($schema->toObject()),
        );
    }

    public function testTheSchemaOfAPlainStringNamesTheTypeOnly(): void
    {
        self::assertSame(['type' => 'string'], get_object_vars((new StringCodec())->schema()->toObject()));
    }

    public function testEncodeReturnsTheString(): void
    {
        self::assertSame('a name', (new StringCodec())->encode('a name'));
    }

    public function testEncodeRefusesAValueOfAnotherType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a string');

        (new StringCodec())->encode(1);
    }

    /**
     * @param non-empty-string $code
     */
    #[DataProvider('stringsOutsideTheRules')]
    public function testEncodeRefusesAStringDecodeRefuses(string $value, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value is not one decode() returns, which refuses it: ' . $code . ' at ""');

        (new StringCodec(2, 4, '^[a-z]+$'))->encode($value);
    }

    /**
     * @return iterable<string, array{string, non-empty-string}>
     */
    public static function stringsOutsideTheRules(): iterable
    {
        yield 'too short' => ['a', 'value_too_short'];
        yield 'too long' => ['abcde', 'value_too_long'];
        yield 'not matching the pattern' => ['ab1', 'pattern_mismatch'];
    }

    public function testEncodeReturnsAStringWithinTheRules(): void
    {
        self::assertSame('abc', (new StringCodec(2, 4, '^[a-z]+$'))->encode('abc'));
    }

    /**
     * @return non-empty-list<\Polunich\WpJsonApi\Codec\Violation>
     */
    private static function violations(StringCodec $codec, mixed $input, ?Pointer $pointer = null): array
    {
        try {
            $codec->decode($input, $pointer ?? Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations();
        }

        self::fail('the codec accepted the value');
    }

    public function testARefusedPatternIsWrittenEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the pattern is not a valid regular expression: (\\n');

        new StringCodec(pattern: "(\n");
    }
}
