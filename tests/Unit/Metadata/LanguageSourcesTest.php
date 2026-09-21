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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LanguageSources::class)]
final class LanguageSourcesTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(LanguageSources::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('repeatedSources')]
    public function testASourceStandsOnce(
        LanguageSourceMetadata $first,
        LanguageSourceMetadata $second,
        string $name,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('the language source "%s" is declared twice', $name));

        new LanguageSources([$first, LanguageSourceMetadata::queryParameter('lang-code'), $second]);
    }

    /**
     * @return iterable<string, array{LanguageSourceMetadata, LanguageSourceMetadata, string}>
     */
    public static function repeatedSources(): iterable
    {
        yield 'a parameter' => [
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::queryParameter('uiLocale'),
            'uiLocale',
        ];
        yield 'the field' => [
            LanguageSourceMetadata::acceptLanguage(),
            LanguageSourceMetadata::acceptLanguage(),
            'Accept-Language',
        ];
    }

    public function testTheFirstSourcePresentInTheRequestDecides(): void
    {
        $uiLocale = LanguageSourceMetadata::queryParameter('uiLocale');
        $field = LanguageSourceMetadata::acceptLanguage();
        $sources = new LanguageSources([$uiLocale, $field]);

        self::assertSame(
            $uiLocale,
            $sources->deciding(self::request(['uiLocale' => 'uk'], ['Accept-Language' => 'en'])),
        );
        self::assertSame($field, $sources->deciding(self::request([], ['Accept-Language' => 'en'])));
        self::assertNull($sources->deciding(self::request([], [])));
        self::assertNull((new LanguageSources([]))->deciding(self::request(
            ['uiLocale' => 'uk'],
            ['Accept-Language' => 'en'],
        )));
    }

    public function testTheQueryParametersAreTheNamesOfTheQuerySourcesInOrder(): void
    {
        $sources = new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
            LanguageSourceMetadata::queryParameter('lang-code'),
        ]);

        self::assertSame(['uiLocale', 'lang-code'], $sources->queryParameters());
        self::assertSame([], (new LanguageSources([LanguageSourceMetadata::acceptLanguage()]))->queryParameters());
    }

    /**
     * @param list<LanguageSourceMetadata> $sources
     * @param array<array-key, mixed>      $query
     * @param array<string, string>        $headers
     * @param list<string>                 $fields
     */
    #[DataProvider('varyingRequests')]
    public function testVaryNamesTheFieldWhereNoSourceBeforeItStandsInTheRequest(
        array $sources,
        array $query,
        array $headers,
        array $fields,
    ): void {
        self::assertSame($fields, (new LanguageSources($sources))->varyFields(self::request($query, $headers)));
    }

    /**
     * @return iterable<string, array{
     *     list<LanguageSourceMetadata>,
     *     array<array-key, mixed>,
     *     array<string, string>,
     *     list<string>,
     * }>
     */
    public static function varyingRequests(): iterable
    {
        $parameterFirst = [
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ];
        $fieldFirst = [LanguageSourceMetadata::acceptLanguage(), LanguageSourceMetadata::queryParameter('uiLocale')];

        yield 'the parameter decides' => [$parameterFirst, ['uiLocale' => 'uk'], ['Accept-Language' => 'en'], []];
        yield 'the field decides' => [$parameterFirst, [], ['Accept-Language' => 'en'], ['Accept-Language']];
        yield 'nothing decides' => [$parameterFirst, [], [], ['Accept-Language']];
        yield 'the field comes first and is absent' => [$fieldFirst, ['uiLocale' => 'uk'], [], ['Accept-Language']];
        yield 'no field is a source' => [
            [LanguageSourceMetadata::queryParameter('uiLocale')],
            [],
            ['Accept-Language' => 'en'],
            [],
        ];
        yield 'no source' => [[], [], ['Accept-Language' => 'en'], []];
    }

    public function testALinkCarriesTheDecidingParameterAsTheClientWroteItAndNothingElse(): void
    {
        $sources = new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
            LanguageSourceMetadata::queryParameter('lang-code'),
        ]);

        self::assertSame(
            ['uiLocale' => 'en-gb'],
            $sources->linkQuery(self::request(['include' => 'owner', 'uiLocale' => 'en-gb', 'lang-code' => 'uk'], [])),
        );
        self::assertSame([], $sources->linkQuery(self::request(['lang-code' => 'uk'], ['Accept-Language' => 'en'])));
        self::assertSame(['lang-code' => 'uk'], $sources->linkQuery(self::request(['lang-code' => 'uk'], [])));
        self::assertSame([], $sources->linkQuery(self::request(['include' => 'owner'], [])));
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    private static function request(array $query, array $headers): ServerRequest
    {
        return new ServerRequest('GET', new Headers($headers), $query, [], '');
    }
}
