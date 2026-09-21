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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\VersionTag;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FieldValueGrammar::class)]
final class FieldValueGrammarTest extends TestCase
{
    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(FieldValueGrammar::class))->getDocComment(),
        );
    }

    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FieldValueGrammar::class))->isReadOnly());
    }

    #[DataProvider('tokens')]
    public function testATokenIsOneOrMoreTokenCharacters(string $value): void
    {
        self::assertTrue(FieldValueGrammar::isToken($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tokens(): array
    {
        return [
            'the fifteen marks tchar names' => ['!#$%&\'*+-.^_`|~'],
            'DIGIT' => ['0123456789'],
            'ALPHA in lower case' => ['abcdefghijklmnopqrstuvwxyz'],
            'ALPHA in upper case' => ['ABCDEFGHIJKLMNOPQRSTUVWXYZ'],
            'a single character' => ['a'],
            'a field name' => ['Content-Type'],
            'a media type parameter name' => ['charset'],
            'an auth-param name' => ['realm'],
        ];
    }

    /**
     * the delimiters of the provider are the set of RFC 9110 5.6.2; tchar is any VCHAR except them
     */
    #[DataProvider('valuesThatAreNoToken')]
    public function testAValueThatIsNoTokenIsRefusedAsOne(string $value): void
    {
        self::assertFalse(FieldValueGrammar::isToken($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesThatAreNoToken(): array
    {
        $cases = ['an empty value' => [''], 'a value holding a space' => ['a b']];

        foreach (str_split('"(),/:;<=>?@[\]{}') as $delimiter) {
            $cases['the delimiter ' . $delimiter] = ['a' . $delimiter . 'b'];
        }

        $cases['a horizontal tab'] = ["a\tb"];
        $cases['obs-text'] = ["a\x80b"];
        $cases['a control character'] = ["a\x01b"];
        $cases['the delete character'] = ["a\x7fb"];

        return $cases;
    }

    #[DataProvider('tokenLengths')]
    public function testTheTokenLengthIsCountedFromTheOffset(string $value, int $offset, int $expected): void
    {
        self::assertSame($expected, FieldValueGrammar::tokenLength($value, $offset));
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function tokenLengths(): array
    {
        return [
            'the whole value' => ['application', 0, 11],
            'up to the delimiter that ends the token' => ['application/json', 0, 11],
            'behind the delimiter' => ['application/json', 12, 4],
            'standing on a delimiter' => ['application/json', 11, 0],
            'standing on the end of the value' => ['a', 1, 0],
            'behind the end of the value' => ['a', 5, 0],
            'an empty value' => ['', 0, 0],
        ];
    }

    #[DataProvider('valuesAFieldMayCarry')]
    public function testAValueOfFieldCharactersIsText(string $value): void
    {
        self::assertTrue(FieldValueGrammar::isText($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesAFieldMayCarry(): array
    {
        return [
            'an empty value' => [''],
            'a visible character' => ['x'],
            'the first and the last VCHAR' => ["\x21\x7e"],
            'a space' => ['a b'],
            'a horizontal tab' => ["a\tb"],
            'a delimiter' => ['a,b'],
            'the first and the last obs-text octet' => ["\x80\xff"],
        ];
    }

    #[DataProvider('valuesNoFieldMayCarry')]
    public function testAValueHoldingAControlCharacterIsNoText(string $value): void
    {
        self::assertFalse(FieldValueGrammar::isText($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesNoFieldMayCarry(): array
    {
        return [
            'a carriage return' => ["a\rb"],
            'a line feed' => ["a\nb"],
            'a CRLF' => ["a\r\nb"],
            'a NUL' => ["a\0b"],
            'a control character' => ["a\x01b"],
            'the delete character' => ["a\x7fb"],
            'a value that is one control character' => ["\x1b"],
        ];
    }

    #[DataProvider('valuesToQuote')]
    public function testQuoteWritesAQuotedString(string $value, string $expected): void
    {
        self::assertSame($expected, FieldValueGrammar::quote($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesToQuote(): array
    {
        return [
            'an empty value' => ['', '""'],
            'a token' => ['utf-8', '"utf-8"'],
            'a value holding a space' => ['x y', '"x y"'],
            'a value holding a comma' => ['a,b', '"a,b"'],
            'a value holding a tab' => ["a\tb", "\"a\tb\""],
            'a value holding a double quote' => ['x"y', '"x\\"y"'],
            'a value holding a backslash' => ['x\\y', '"x\\\\y"'],
            'a value holding both' => ['\\"', '"\\\\\\""'],
            'an ext list of two URIs' => [
                'https://a.example/e https://b.example/e',
                '"https://a.example/e https://b.example/e"',
            ],
        ];
    }

    #[DataProvider('valuesNoFieldMayCarry')]
    public function testQuoteRefusesAValueNoFieldMayCarry(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        FieldValueGrammar::quote($value);
    }

    public function testTheRefusalCarriesNoLineBreakOfTheValueItNames(): void
    {
        try {
            FieldValueGrammar::quote("a\r\nb");
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'a header field value may carry HTAB, SP, a visible character or obs-text, got "a\\r\\nb"',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('a value no field may carry was quoted');
    }

    /**
     * the empty value is no token: written bare, it would leave the parameter without a value
     */
    #[DataProvider('valuesToRender')]
    public function testRenderWritesATokenBareAndEverythingElseQuoted(string $value, string $expected): void
    {
        self::assertSame($expected, FieldValueGrammar::render($value));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesToRender(): array
    {
        return [
            'a token' => ['utf-8', 'utf-8'],
            'a token of digits' => ['8', '8'],
            'an empty value' => ['', '""'],
            'a value holding a space' => ['x y', '"x y"'],
            'a value holding a delimiter' => ['a/b', '"a/b"'],
            'a value holding a double quote' => ['x"y', '"x\\"y"'],
            'a value holding obs-text' => ["\x80", "\"\x80\""],
        ];
    }

    public function testRenderRefusesAValueNoFieldMayCarry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FieldValueGrammar::render("a\rb");
    }

    public function testTheWeightIsNamedQAndDefaultsToOne(): void
    {
        self::assertSame(1.0, FieldValueGrammar::DEFAULT_WEIGHT);
        self::assertSame('q', FieldValueGrammar::WEIGHT_PARAMETER);
    }

    #[DataProvider('qvalues')]
    public function testWeightReadsEveryQvalue(string $qvalue, float $weight): void
    {
        self::assertSame($weight, FieldValueGrammar::weight($qvalue));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function qvalues(): array
    {
        return [
            'not acceptable' => ['0', 0.0],
            'zero with a point' => ['0.', 0.0],
            'the least preferred' => ['0.001', 0.001],
            'a weight with digits' => ['0.5', 0.5],
            'the most preferred' => ['1', 1.0],
            'one with three zeros' => ['1.000', 1.0],
        ];
    }

    #[DataProvider('valuesThatAreNoQvalue')]
    public function testWeightRefusesAValueThatIsNoQvalue(string $value): void
    {
        self::assertNull(FieldValueGrammar::weight($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function valuesThatAreNoQvalue(): array
    {
        return [
            'four digits' => ['0.0001'],
            'above one' => ['1.001'],
            'two' => ['2'],
            'a sign' => ['-0'],
            'a leading point' => ['.5'],
            'a comma' => ['0,5'],
            'the empty value' => [''],
            'a leading space' => [' 0.5'],
            'a trailing line feed' => ["0.5\n"],
        ];
    }

    #[DataProvider('numbers')]
    public function testIsWeightTellsWhetherAQvalueCanWriteTheNumber(float $number, bool $expected): void
    {
        self::assertSame($expected, FieldValueGrammar::isWeight($number));
    }

    /**
     * @return array<string, array{float, bool}>
     */
    public static function numbers(): array
    {
        return [
            'zero' => [0.0, true],
            'the least preferred' => [0.001, true],
            'one' => [1.0, true],
            'four digits' => [0.0005, false],
            'above one' => [1.001, false],
            'below zero' => [-0.1, false],
            'not a number' => [NAN, false],
            'infinity' => [INF, false],
        ];
    }

    /**
     * a copy of the tchar alphabet would pass every behaviour test until the copies drift apart,
     * so the test looks for one among the constants
     *
     * @param class-string $class
     */
    #[DataProvider('classesThatAskForTheGrammar')]
    public function testNoOtherClassOfTheModuleKeepsAnAlphabetOfItsOwn(string $class): void
    {
        $own = array_filter(
            (new ReflectionClass($class))->getConstants(),
            static function (mixed $value): bool {
                return is_string($value) && str_contains($value, 'abcdefghijklmnopqrstuvwxyz');
            },
        );

        self::assertSame([], $own, $class . ' keeps an alphabet of its own');
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function classesThatAskForTheGrammar(): array
    {
        return [
            'the map of header fields' => [Headers::class],
            'the media type' => [MediaType::class],
            'the entity tag of a version' => [VersionTag::class],
        ];
    }
}
