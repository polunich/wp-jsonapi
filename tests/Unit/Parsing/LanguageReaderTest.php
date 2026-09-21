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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Parsing\LanguageReader;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LanguageReader::class)]
final class LanguageReaderTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(LanguageReader::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheParameterGivesOneRangeOfWeightOneAsTheClientWroteIt(): void
    {
        $preferences = self::reader()->read(self::request(
            ['uiLocale' => 'en-GB'],
            ['Accept-Language' => 'uk, de;q=0.5'],
        ));

        self::assertSame([['en-GB', 1.0]], self::ranges($preferences));
    }

    public function testTheFieldDecidesWhereTheParameterIsAbsent(): void
    {
        $preferences = self::reader()->read(self::request([], ['Accept-Language' => 'uk, de;q=0.5, en_GB']));

        self::assertSame([['uk', 1.0], ['de', 0.5]], self::ranges($preferences));
    }

    public function testWithoutASourceInTheRequestThereIsNoPreference(): void
    {
        self::assertFalse(self::reader()->read(self::request(['include' => 'owner'], []))->hasPreference());
    }

    public function testASourceAfterTheDecidingOneIsNotRead(): void
    {
        $reader = new LanguageReader(
            new LanguageSources([
                LanguageSourceMetadata::acceptLanguage(),
                LanguageSourceMetadata::queryParameter('uiLocale'),
            ]),
            new AcceptLanguageParser(),
        );

        // the malformed parameter is not read, so the request gets no 400
        $preferences = $reader->read(self::request(['uiLocale' => 'en_GB'], ['Accept-Language' => 'uk']));

        self::assertSame([['uk', 1.0]], self::ranges($preferences));
    }

    public function testAnApiWithoutSourcesReadsNoLanguage(): void
    {
        $reader = new LanguageReader(new LanguageSources([]), new AcceptLanguageParser());

        self::assertFalse($reader->read(self::request(
            ['uiLocale' => 'de'],
            ['Accept-Language' => 'uk'],
        ))->hasPreference());
    }

    #[DataProvider('refusedValues')]
    public function testADecidingValueThatIsNoBasicRangeIsABadRequestNamingTheParameter(mixed $value): void
    {
        try {
            self::reader()->read(self::request(['uiLocale' => $value], ['Accept-Language' => 'uk']));
            self::fail('a bad value of the language parameter is refused');
        } catch (BadRequestException $refusal) {
            $descriptions = $refusal->descriptions();

            self::assertCount(1, $descriptions);
            self::assertSame(ErrorCode::InvalidQueryParameter, $descriptions[0]->code);
            self::assertSame(['name' => 'uiLocale'], $descriptions[0]->parameters);
            self::assertSame('uiLocale', $descriptions[0]->parameter);
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function refusedValues(): iterable
    {
        yield 'the empty value' => [''];
        yield 'the wildcard' => ['*'];
        yield 'a low line' => ['en_GB'];
        yield 'a weight' => ['uk;q=0.5'];
        yield 'a list' => ['uk,en'];
        yield 'a subtag longer than eight' => ['en-abcdefghi'];
        yield 'a trailing line feed' => ["uk\n"];
        yield 'brackets' => [['uk']];
        yield 'a map' => [['lang' => 'uk']];
    }

    private static function reader(): LanguageReader
    {
        return new LanguageReader(
            new LanguageSources([
                LanguageSourceMetadata::queryParameter('uiLocale'),
                LanguageSourceMetadata::acceptLanguage(),
            ]),
            new AcceptLanguageParser(),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    private static function request(array $query, array $headers): ServerRequest
    {
        return new ServerRequest('GET', new Headers($headers), $query, [], '');
    }

    /**
     * @return list<array{string, float}>
     */
    private static function ranges(LanguagePreferences $preferences): array
    {
        return array_map(static function (LanguageRange $range): array {
            return [$range->range, $range->weight];
        }, $preferences->ranges());
    }
}
