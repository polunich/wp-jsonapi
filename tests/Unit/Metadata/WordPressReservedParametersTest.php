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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\WordPressReservedParameters;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(WordPressReservedParameters::class)]
final class WordPressReservedParametersTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function consumedParameters(): iterable
    {
        yield 'the route of a request without pretty permalinks' => ['rest_route'];
        yield 'the method override' => ['_method'];
        yield 'the nonce of cookie authentication' => ['_wpnonce'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedParameters(): iterable
    {
        yield 'the field filter of the core' => ['_fields'];
        yield 'the embedding of linked resources' => ['_embed'];
        yield 'the envelope' => ['_envelope'];
        yield 'JSONP' => ['_jsonp'];
        yield 'the locale of the user' => ['_locale'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherParameters(): iterable
    {
        yield 'a family of the specification' => ['filter'];
        yield 'a parameter of the specification with a bracket' => ['fields[pets]'];
        yield 'a name of a plugin' => ['context'];
        yield 'a name that is no reserved parameter of WordPress but looks like one' => ['_field'];
        yield 'the same name in another case' => ['_Fields'];
        yield 'the empty name' => [''];
    }

    public function testTheClassIsFinalAndInternal(): void
    {
        $class = new ReflectionClass(WordPressReservedParameters::class);

        self::assertTrue($class->isFinal());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    #[DataProvider('consumedParameters')]
    public function testWordPressConsumesTheseParametersItself(string $name): void
    {
        self::assertTrue(WordPressReservedParameters::isConsumed($name));
        self::assertFalse(WordPressReservedParameters::isRefused($name));
        self::assertTrue(WordPressReservedParameters::isReserved($name));
    }

    #[DataProvider('refusedParameters')]
    public function testTheLibraryRefusesTheseParameters(string $name): void
    {
        self::assertTrue(WordPressReservedParameters::isRefused($name));
        self::assertFalse(WordPressReservedParameters::isConsumed($name));
        self::assertTrue(WordPressReservedParameters::isReserved($name));
    }

    #[DataProvider('otherParameters')]
    public function testEveryOtherNameIsNoReservedParameter(string $name): void
    {
        self::assertFalse(WordPressReservedParameters::isConsumed($name));
        self::assertFalse(WordPressReservedParameters::isRefused($name));
        self::assertFalse(WordPressReservedParameters::isReserved($name));
    }
}
