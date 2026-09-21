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

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Localization\LanguagePreferences;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ContentLanguageTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function fields(): iterable
    {
        yield 'a language the project serves' => ['de', 'de-DE'];
        yield 'a prefix of a tag the project serves' => ['de;q=0.5, en;q=0.9', 'en-GB'];
        yield 'the wildcard skips a rejected language' => ['*, en;q=0', 'de-DE'];
        yield 'the language the client named, under a broader rejection' => ['en-GB, en;q=0', 'en-GB'];
        yield 'the language the client rejected, under a broader acceptance' => ['en, en-GB;q=0', null];
        yield 'no language the project serves' => ['fr', null];
        yield 'every language rejected' => ['*;q=0', null];
        yield 'no preference' => [null, 'en-GB'];
    }

    #[DataProvider('fields')]
    public function testReturnsTheTagOfTheNegotiatedLanguage(?string $acceptLanguage, ?string $expected): void
    {
        $contentLanguage = self::project(['en-GB', 'de-DE']);

        $tag = $contentLanguage->negotiate((new AcceptLanguageParser())->parse($acceptLanguage));

        self::assertSame($expected, $tag);
    }

    public function testMayDisregardThePreferences(): void
    {
        $contentLanguage = new class implements ContentLanguage {
            #[Override]
            public function negotiate(LanguagePreferences $preferences): string
            {
                return 'en-GB';
            }
        };

        self::assertSame('en-GB', $contentLanguage->negotiate((new AcceptLanguageParser())->parse('fr')));
    }

    /**
     * @param list<string> $availableTags
     */
    private static function project(array $availableTags): ContentLanguage
    {
        return new class ($availableTags) implements ContentLanguage {
            /**
             * @param list<string> $availableTags
             */
            public function __construct(private readonly array $availableTags) {}

            #[Override]
            public function negotiate(LanguagePreferences $preferences): ?string
            {
                return $preferences->select($this->availableTags);
            }
        };
    }
}
