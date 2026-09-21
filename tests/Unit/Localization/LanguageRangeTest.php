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

namespace Polunich\WpJsonApi\Tests\Unit\Localization;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\FieldValueGrammar;
use Polunich\WpJsonApi\Localization\LanguageRange;

/**
 * @internal
 */
#[CoversClass(LanguageRange::class)]
final class LanguageRangeTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function wellFormedRanges(): iterable
    {
        yield 'one subtag' => ['en'];
        yield 'eight letters' => ['abcdefgh'];
        yield 'one letter' => ['i'];
        yield 'two subtags' => ['en-GB'];
        yield 'three subtags' => ['zh-Hant-TW'];
        yield 'digits in a later subtag' => ['de-419'];
        yield 'eight characters in a later subtag' => ['en-abcdefgh'];
        yield 'wildcard' => ['*'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedRanges(): iterable
    {
        yield 'empty' => [''];
        yield 'nine letters' => ['abcdefghi'];
        yield 'nine characters in a later subtag' => ['en-abcdefghi'];
        yield 'digits in the first subtag' => ['419'];
        yield 'leading hyphen' => ['-en'];
        yield 'trailing hyphen' => ['en-'];
        yield 'empty subtag' => ['en--GB'];
        yield 'underscore' => ['en_GB'];
        yield 'space' => ['en GB'];
        yield 'wildcard with a subtag' => ['*-GB'];
        yield 'two ranges' => ['en,de'];
        yield 'range with its weight' => ['en;q=0.5'];
        yield 'leading space' => [' en'];
        yield 'trailing space' => ['en '];
        yield 'trailing line feed' => ["en\n"];
        yield 'wildcard with a trailing line feed' => ["*\n"];
        yield 'trailing carriage return' => ["en\r"];
        yield 'inner line feed' => ["en\nde"];
        yield 'trailing null' => ["en\0"];
        yield 'trailing tab' => ["en\t"];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function wellFormedWeights(): iterable
    {
        yield 'not acceptable' => [0.0];
        yield 'least preferred' => [0.001];
        yield 'one decimal' => [0.5];
        yield 'three decimals' => [0.123];
        yield 'most preferred' => [1.0];
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function malformedWeights(): iterable
    {
        yield 'below zero' => [-0.001];
        yield 'above one' => [1.001];
        yield 'far above one' => [2.0];
        yield 'four decimals' => [0.1234];
        yield 'not representable with three decimals' => [0.1 + 0.2];
        yield 'not a number' => [NAN];
        yield 'infinite' => [INF];
    }

    /**
     * the three `de-de` rows are the examples of RFC 4647 3.3.1
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function basicFiltering(): iterable
    {
        yield 'equal' => ['de', 'de', true];
        yield 'equal, other case' => ['DE', 'de', true];
        yield 'equal, other case in the tag' => ['de', 'DE', true];
        yield 'prefix followed by a hyphen' => ['de-de', 'de-DE-1996', true];
        yield 'prefix of a longer subtag' => ['de-de', 'de-Deva', false];
        yield 'prefix in another position' => ['de-de', 'de-Latn-DE', false];
        yield 'prefix without a hyphen' => ['en', 'engb', false];
        yield 'shorter tag' => ['en-GB', 'en', false];
        yield 'other language' => ['en', 'de', false];
        yield 'wildcard' => ['*', 'de-DE-1996', true];
    }

    #[DataProvider('wellFormedRanges')]
    public function testKeepsAWellFormedRangeAsTheClientWroteIt(string $range): void
    {
        self::assertSame($range, (new LanguageRange($range))->range);
    }

    #[DataProvider('malformedRanges')]
    public function testRejectsAMalformedRange(string $range): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not follow the grammar of RFC 4647 2.1');

        new LanguageRange($range);
    }

    #[DataProvider('wellFormedWeights')]
    public function testKeepsAWeightBetweenZeroAndOne(float $weight): void
    {
        self::assertSame($weight, (new LanguageRange('en', $weight))->weight);
    }

    #[DataProvider('malformedWeights')]
    public function testRejectsAWeightOutsideTheQvalueGrammar(float $weight): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most three decimals');

        new LanguageRange('en', $weight);
    }

    /**
     * the literal default must stay equal to `FieldValueGrammar::DEFAULT_WEIGHT`
     */
    public function testWeighsARangeWithoutAQParameterAsOne(): void
    {
        self::assertSame(1.0, (new LanguageRange('en'))->weight);
        self::assertSame(FieldValueGrammar::DEFAULT_WEIGHT, (new LanguageRange('en'))->weight);
    }

    public function testReadsTheWildcard(): void
    {
        self::assertTrue((new LanguageRange('*'))->isWildcard());
        self::assertFalse((new LanguageRange('en'))->isWildcard());
    }

    public function testReadsAWeightOfZeroAsARejection(): void
    {
        self::assertTrue((new LanguageRange('en', 0.0))->isRejection());
        self::assertFalse((new LanguageRange('en', 0.001))->isRejection());
        self::assertFalse((new LanguageRange('en'))->isRejection());
    }

    #[DataProvider('basicFiltering')]
    public function testMatchesATagByBasicFiltering(string $range, string $languageTag, bool $matches): void
    {
        self::assertSame($matches, (new LanguageRange($range))->matches($languageTag));
    }

    public function testMatchesTheSameTagsWhateverTheWeight(): void
    {
        self::assertTrue((new LanguageRange('en', 0.0))->matches('en-GB'));
        self::assertTrue((new LanguageRange('*', 0.0))->matches('de'));
    }

    public function testARefusedRangeIsWrittenEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('language range "en\\nX" does not follow the grammar of RFC 4647 2.1');

        new LanguageRange("en\nX");
    }
}
