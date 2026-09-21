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
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;

/**
 * @internal
 */
#[CoversClass(LanguagePreferences::class)]
final class LanguagePreferencesTest extends TestCase
{
    /**
     * driven through the parser, so each field is written as a client sends it.
     *
     * every rule that decides between two covering ranges is read in both directions:
     * one direction alone passes with the wrong rule that takes the first rejection it finds.
     * the one-letter range `i` is as long as `*`, the only length at which a longest-match loop
     * that also walked the wildcard would let `*` decide a tag another range matches
     *
     * @return iterable<string, array{string|null, list<string>, string|null}>
     */
    public static function negotiations(): iterable
    {
        yield 'the only range' => ['de', ['de'], 'de'];
        yield 'the range of the highest weight' => ['en;q=0.7, de;q=0.9', ['en', 'de'], 'de'];
        yield 'equal weight keeps the order of the field' => ['de, en', ['en', 'de'], 'de'];
        yield 'a prefix of the tag' => ['en', ['de', 'en-GB'], 'en-GB'];
        yield 'the RFC 9110 example, Danish available' => [
            'da, en-gb;q=0.8, en;q=0.7',
            ['en-US', 'da', 'en-GB'],
            'da',
        ];
        yield 'the RFC 9110 example, English only' => [
            'da, en-gb;q=0.8, en;q=0.7',
            ['en-US', 'en-GB'],
            'en-GB',
        ];
        yield 'the RFC 9110 example, other English only' => [
            'da, en-gb;q=0.8, en;q=0.7',
            ['en-US'],
            'en-US',
        ];
        yield 'the wildcard covers a tag no other range matches' => ['*, en;q=0', ['en', 'de'], 'de'];
        yield 'the wildcard does not cover a tag another range matches' => ['*;q=0, en', ['fr', 'en'], 'en'];
        yield 'the wildcard rejects the rest' => ['*;q=0, en', ['fr'], null];
        yield 'the first wildcard of the list decides, the accepting one' => ['*;q=0.5, *;q=0', ['fr'], 'fr'];
        yield 'the first wildcard of the list decides, unweighted' => ['*, *;q=0', ['fr'], 'fr'];
        yield 'the first wildcard of the list decides, written last in the field' => ['*;q=0, *;q=0.5', ['fr'], 'fr'];
        yield 'the wildcard does not cover a tag a one-letter range matches' => ['*;q=0.5, i;q=0', ['i-default'], null];
        yield 'a one-letter range covers a tag the wildcard rejects' => ['*;q=0, i', ['i-default'], 'i-default'];
        yield 'a one-letter range decides against a stronger wildcard' => [
            '*;q=0.9, i;q=0.5',
            ['i-default', 'fr'],
            'fr',
        ];
        yield 'the longer range decides, the rejection' => ['en, en-GB;q=0', ['en-GB', 'en-US'], 'en-US'];
        yield 'the longer range decides, and leaves nothing' => ['en, en-GB;q=0', ['en-GB'], null];
        yield 'the longer range decides, the acceptance' => ['en-GB, en;q=0', ['en-GB', 'en-US'], 'en-GB'];
        yield 'the longer range decides although it stands lower' => ['de;q=0, de-DE;q=0.5', ['de-DE', 'fr'], 'de-DE'];
        yield 'the broader rejection reaches the other script' => [
            'zh-Hant, zh;q=0',
            ['zh-Hans', 'zh-Hant'],
            'zh-Hant',
        ];
        yield 'a rejection below the winner leaves it alone' => [
            'de-DE, de;q=0, fr;q=0.1',
            ['de-DE', 'fr'],
            'de-DE',
        ];
        yield 'the longer range decides the weight, not the stronger' => [
            'en-GB;q=0.5, en;q=0.9',
            ['en-GB', 'en-US'],
            'en-US',
        ];
        yield 'a repeated range is decided by its higher weight' => ['en;q=0.5, en;q=0', ['en'], 'en'];
        yield 'a repeated range is decided by its higher weight, rejection first' => [
            'en;q=0, en;q=0.5',
            ['en'],
            'en',
        ];
        yield 'a tag no range covers is unacceptable' => ['fr', ['en', 'de'], null];
        yield 'the wildcard outranks a weaker range' => ['*, de;q=0.5', ['de', 'fr'], 'fr'];
        yield 'no preference takes the first available tag' => [null, ['en', 'de'], 'en'];
        yield 'a field of malformed elements states no preference' => ['en_GB', ['de'], 'de'];
        yield 'no available tag' => ['en', [], null];
        yield 'no preference and no available tag' => [null, [], null];
        yield 'the tag keeps the case of the project' => ['en', ['EN-gb'], 'EN-gb'];
        yield 'the case of the range carries no meaning' => ['EN-GB', ['en-gb'], 'en-gb'];
        yield 'the same range prefers the tag the project named first' => ['en', ['en-US', 'en-GB'], 'en-US'];
    }

    /**
     * ties keep their order only because `usort()` is stable
     */
    public function testOrdersTheRangesByDescendingWeightAndKeepsTheFieldOrderOnTies(): void
    {
        $preferences = new LanguagePreferences([
            new LanguageRange('en', 0.5),
            new LanguageRange('de'),
            new LanguageRange('fr', 0.5),
            new LanguageRange('it'),
        ]);

        self::assertSame(['de', 'it', 'en', 'fr'], self::names($preferences->ranges()));
    }

    public function testReadsAnEmptyFieldAsNoPreference(): void
    {
        self::assertFalse((new LanguagePreferences([]))->hasPreference());
        self::assertTrue((new LanguagePreferences([new LanguageRange('en', 0.0)]))->hasPreference());
    }

    public function testKeepsEveryRangeOfTheFieldInOneList(): void
    {
        $preferences = new LanguagePreferences([
            new LanguageRange('en', 0.0),
            new LanguageRange('de', 0.5),
            new LanguageRange('fr', 0.0),
            new LanguageRange('it'),
        ]);

        self::assertSame(['it', 'de', 'en', 'fr'], self::names($preferences->ranges()));
        self::assertSame([0, 1, 2, 3], array_keys($preferences->ranges()));
    }

    public function testPublishesTheWholeFieldAndTheResultOverIt(): void
    {
        $methods = get_class_methods(LanguagePreferences::class);
        sort($methods);

        self::assertSame(['__construct', 'hasPreference', 'ranges', 'select'], $methods);
    }

    /**
     * @param list<string> $availableTags
     */
    #[DataProvider('negotiations')]
    public function testPicksTheTagTheClientPrefers(
        ?string $acceptLanguage,
        array $availableTags,
        ?string $expected,
    ): void {
        $preferences = (new AcceptLanguageParser())->parse($acceptLanguage);

        self::assertSame($expected, $preferences->select($availableTags));
    }

    /**
     * `*` matches `en` on its own; only the whole list keeps it from serving the rejected `en`
     */
    public function testDoesNotPickATagThatTheDecidingRangeRejects(): void
    {
        $preferences = (new AcceptLanguageParser())->parse('*, en;q=0');

        self::assertTrue($preferences->ranges()[0]->matches('en'));
        self::assertSame('de', $preferences->select(['en', 'de']));
    }

    /**
     * the rejecting `*` matches `en-GB` too, and decides nothing, since `en` covers it
     */
    public function testPicksATagThatARejectionOfTheFieldMatches(): void
    {
        $preferences = (new AcceptLanguageParser())->parse('*;q=0, en');
        $wildcard = $preferences->ranges()[1];

        self::assertTrue($wildcard->isRejection());
        self::assertTrue($wildcard->matches('en-GB'));
        self::assertSame('en-GB', $preferences->select(['en-GB']));
    }

    /**
     * an empty list states no preference, not that every language is unacceptable
     */
    public function testTreatsAFieldOfMalformedElementsAsNoPreference(): void
    {
        $preferences = (new AcceptLanguageParser())->parse('en_GB, de_DE');

        self::assertSame([], $preferences->ranges());
        self::assertFalse($preferences->hasPreference());
        self::assertSame('de', $preferences->select(['de', 'en']));
    }

    /**
     * @param list<LanguageRange> $ranges
     *
     * @return list<string>
     */
    private static function names(array $ranges): array
    {
        return array_map(static function (LanguageRange $range): string {
            return $range->range;
        }, $ranges);
    }
}
