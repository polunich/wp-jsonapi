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
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(LanguageSourceMetadata::class)]
final class LanguageSourceMetadataTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(LanguageSourceMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testASourceIsAQueryParameterOrTheField(): void
    {
        self::assertSame('uiLocale', LanguageSourceMetadata::queryParameter('uiLocale')->queryParameter);
        self::assertSame('uiLocale', LanguageSourceMetadata::queryParameter('uiLocale')->name());
        self::assertNull(LanguageSourceMetadata::acceptLanguage()->queryParameter);
        self::assertSame('Accept-Language', LanguageSourceMetadata::acceptLanguage()->name());
    }

    public function testTheNameOfTheParameterFollowsTheRuleOfAParameterOfTheImplementation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the query parameter "_locale" is no legal member name');

        LanguageSourceMetadata::queryParameter('_locale');
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    #[DataProvider('presences')]
    public function testASourceIsPresentWhereTheRequestCarriesItWhateverTheValue(
        LanguageSourceMetadata $source,
        array $query,
        array $headers,
        bool $present,
    ): void {
        self::assertSame($present, $source->isPresentIn(self::request($query, $headers)));
    }

    /**
     * @return iterable<string, array{
     *     LanguageSourceMetadata,
     *     array<array-key, mixed>,
     *     array<string, string>,
     *     bool,
     * }>
     */
    public static function presences(): iterable
    {
        yield 'the parameter' => [LanguageSourceMetadata::queryParameter('uiLocale'), ['uiLocale' => 'uk'], [], true];
        yield 'the empty parameter' => [
            LanguageSourceMetadata::queryParameter('uiLocale'),
            ['uiLocale' => ''],
            [],
            true,
        ];
        yield 'the parameter in brackets' => [
            LanguageSourceMetadata::queryParameter('uiLocale'),
            ['uiLocale' => ['uk']],
            [],
            true,
        ];
        yield 'another parameter' => [
            LanguageSourceMetadata::queryParameter('uiLocale'),
            ['locale' => 'uk'],
            [],
            false,
        ];
        yield 'a parameter of digits' => [LanguageSourceMetadata::queryParameter('123'), [123 => 'uk'], [], true];
        yield 'the field' => [LanguageSourceMetadata::acceptLanguage(), [], ['accept-language' => 'uk'], true];
        yield 'the empty field' => [LanguageSourceMetadata::acceptLanguage(), [], ['Accept-Language' => ''], true];
        yield 'no field' => [LanguageSourceMetadata::acceptLanguage(), ['uiLocale' => 'uk'], [], false];
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
