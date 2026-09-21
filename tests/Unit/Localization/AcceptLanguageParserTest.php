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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\LanguageRange;

/**
 * @internal
 */
#[CoversClass(AcceptLanguageParser::class)]
final class AcceptLanguageParserTest extends TestCase
{
    /**
     * `qvalue = ( "0" [ "." 0*3DIGIT ] ) / ( "1" [ "." 0*3("0") ] )` (RFC 9110 12.4.2)
     *
     * @return iterable<string, array{string, float}>
     */
    public static function wellFormedWeights(): iterable
    {
        yield 'zero' => ['en;q=0', 0.0];
        yield 'zero with a dot' => ['en;q=0.', 0.0];
        yield 'one decimal' => ['en;q=0.5', 0.5];
        yield 'three decimals' => ['en;q=0.123', 0.123];
        yield 'one' => ['en;q=1', 1.0];
        yield 'one with a dot' => ['en;q=1.', 1.0];
        yield 'one with three zeros' => ['en;q=1.000', 1.0];
        yield 'upper case parameter name' => ['en;Q=0.5', 0.5];
        yield 'optional whitespace before the semicolon' => ['en ;q=0.5', 0.5];
        yield 'optional whitespace after the semicolon' => ['en; q=0.5', 0.5];
        yield 'horizontal tab as optional whitespace' => ["en\t;\tq=0.5", 0.5];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparsableElements(): iterable
    {
        yield 'malformed range' => ['en_GB'];
        yield 'range with a subtag of nine characters' => ['en-abcdefghi'];
        yield 'weight above one' => ['en;q=1.5'];
        yield 'weight with four decimals' => ['en;q=0.1234'];
        yield 'weight without a value' => ['en;q='];
        yield 'unknown parameter' => ['en;level=1'];
        yield 'two semicolons' => ['en;;q=0.5'];
        yield 'trailing line feed' => ["de\n"];
        yield 'weight with a trailing line feed' => ["en;q=0.5\n"];
        yield 'trailing carriage return' => ["de\r"];
        yield 'trailing null' => ["de\0"];
        yield 'leading line feed' => ["\nde"];
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function fieldsWithoutAPreference(): iterable
    {
        yield 'no field' => [null];
        yield 'empty field' => [''];
        yield 'whitespace' => [" \t"];
        yield 'commas only' => [',,'];
        yield 'commas and whitespace' => [', ,'];
    }

    /**
     * the example of RFC 9110 12.5.4
     */
    public function testReadsTheRangesAndWeightsOfTheField(): void
    {
        $ranges = (new AcceptLanguageParser())->parse('da, en-gb;q=0.8, en;q=0.7')->ranges();

        self::assertSame(['da', 'en-gb', 'en'], array_map(
            static function (LanguageRange $range): string {
                return $range->range;
            },
            $ranges,
        ));
        self::assertSame([1.0, 0.8, 0.7], array_map(
            static function (LanguageRange $range): float {
                return $range->weight;
            },
            $ranges,
        ));
    }

    #[DataProvider('wellFormedWeights')]
    public function testReadsTheWeightOfAnElement(string $field, float $weight): void
    {
        $ranges = (new AcceptLanguageParser())->parse($field)->ranges();

        self::assertCount(1, $ranges);
        self::assertSame('en', $ranges[0]->range);
        self::assertSame($weight, $ranges[0]->weight);
    }

    #[DataProvider('unparsableElements')]
    public function testLeavesOutAnUnparsableElement(string $element): void
    {
        self::assertSame([], (new AcceptLanguageParser())->parse($element)->ranges());
    }

    public function testKeepsTheWellFormedElementsOfAFieldThatCarriesAnUnparsableOne(): void
    {
        $ranges = (new AcceptLanguageParser())->parse("de, en_GB, fr\n, en")->ranges();

        self::assertSame(['de', 'en'], array_map(
            static function (LanguageRange $range): string {
                return $range->range;
            },
            $ranges,
        ));
    }

    /**
     * a client that writes the decimal comma of its locale sends the element `en;q=0`,
     * a rejection, and the malformed element `5`
     */
    public function testSplitsTheFieldOnCommasBeforeReadingAnElement(): void
    {
        $ranges = (new AcceptLanguageParser())->parse('en;q=0,5')->ranges();

        self::assertCount(1, $ranges);
        self::assertSame('en', $ranges[0]->range);
        self::assertTrue($ranges[0]->isRejection());
    }

    public function testIgnoresEmptyElements(): void
    {
        $ranges = (new AcceptLanguageParser())->parse('de, ,en,')->ranges();

        self::assertSame(['de', 'en'], array_map(
            static function (LanguageRange $range): string {
                return $range->range;
            },
            $ranges,
        ));
    }

    /**
     * a bound on empty elements would drop the `en` after them from a field the server accepted
     */
    public function testIgnoresEveryEmptyElementOfTheField(): void
    {
        $field = 'de' . str_repeat(',', 10000) . 'en';

        $ranges = (new AcceptLanguageParser())->parse($field)->ranges();

        self::assertSame(['de', 'en'], array_map(
            static function (LanguageRange $range): string {
                return $range->range;
            },
            $ranges,
        ));
    }

    #[DataProvider('fieldsWithoutAPreference')]
    public function testReadsAFieldWithoutAnElementAsNoPreference(?string $acceptLanguage): void
    {
        $preferences = (new AcceptLanguageParser())->parse($acceptLanguage);

        self::assertFalse($preferences->hasPreference());
        self::assertSame([], $preferences->ranges());
    }

    public function testKeepsARejection(): void
    {
        $ranges = (new AcceptLanguageParser())->parse('*, en;q=0')->ranges();

        self::assertCount(2, $ranges);
        self::assertTrue($ranges[0]->isWildcard());
        self::assertSame('en', $ranges[1]->range);
        self::assertTrue($ranges[1]->isRejection());
    }

    public function testOrdersTheRangesByDescendingWeight(): void
    {
        $ranges = (new AcceptLanguageParser())->parse('en;q=0.2, de;q=0.9, fr')->ranges();

        self::assertSame(['fr', 'de', 'en'], array_map(
            static function (LanguageRange $range): string {
                return $range->range;
            },
            $ranges,
        ));
    }
}
