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
use Polunich\WpJsonApi\Metadata\CustomQueryParameterName;
use Polunich\WpJsonApi\Query\ParameterFamily;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CustomQueryParameterName::class)]
final class CustomQueryParameterNameTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(CustomQueryParameterName::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('admittedNames')]
    public function testALegalMemberNameOfNoFamilyAndNoWordPressParameterIsAdmitted(string $name): void
    {
        $this->expectNotToPerformAssertions();

        CustomQueryParameterName::check($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function admittedNames(): iterable
    {
        yield 'an upper-case letter' => ['uiLocale'];
        yield 'a hyphen inside' => ['ui-locale'];
        yield 'a digit' => ['locale2'];
        yield 'a-z alone, which the API decides on' => ['locale'];
    }

    #[DataProvider('refusedNames')]
    public function testANameJsonApiOrWordPressKeepsIsRefused(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        CustomQueryParameterName::check($name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        foreach (ParameterFamily::cases() as $family) {
            yield 'the family ' . $family->value => [
                $family->value,
                'the query parameter "' . $family->value . '" is a family JSON:API defines itself',
            ];
        }

        $rule = 'is no legal member name, which JSON:API asks of the base name of a custom query parameter';

        yield 'no legal member name' => ['created[after]', $rule];
        yield 'a leading low line' => ['_locale', $rule];
        yield 'a parameter WordPress reads' => [
            'rest_route',
            'is one WordPress reads itself before the library reads the request',
        ];
    }

    #[DataProvider('reservations')]
    public function testANameOfAToZAloneIsReservedByJsonApi(string $name, bool $reserved): void
    {
        self::assertSame($reserved, CustomQueryParameterName::isReservedByJsonApi($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function reservations(): iterable
    {
        yield 'a-z alone' => ['locale', true];
        yield 'a single letter' => ['l', true];
        yield 'an upper-case letter' => ['uiLocale', false];
        yield 'a hyphen inside' => ['ui-locale', false];
        yield 'a low line inside' => ['ui_locale', false];
        yield 'a digit' => ['locale2', false];
        yield 'a letter above U+007F' => ["locale\u{00E9}", false];
    }
}
