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

namespace Polunich\WpJsonApi\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Routing\UrlGenerator;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class UrlGeneratorTest extends TestCase
{
    public function testThePortIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(UrlGenerator::class))->getDocComment(),
        );
    }

    public function testThePortDeclaresOneMethodOfTheSegmentsAndAnOptionalQuery(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(UrlGenerator::class))->getMethods(),
        );
        $url = new ReflectionMethod(UrlGenerator::class, 'url');

        self::assertSame(['url'], $methods);
        self::assertSame(
            [['segments', 'array', false], ['query', 'array', true]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), self::typeName($parameter->getType()), $parameter->isOptional()];
                },
                $url->getParameters(),
            ),
        );
        self::assertSame('string', self::typeName($url->getReturnType()));
    }

    /**
     * a PHPDoc type is not checked at runtime, so the test reads the declaration
     */
    public function testThePortDeclaresTheKeyTypeTheQueryCanGive(): void
    {
        $declaration = (new ReflectionMethod(UrlGenerator::class, 'url'))->getDocComment();

        self::assertIsString($declaration);
        self::assertMatchesRegularExpression('/@param\s+array<array-key, mixed>\s+\$query\b/', $declaration);
        self::assertMatchesRegularExpression('/@param\s+non-empty-list<string>\s+\$segments\b/', $declaration);
    }

    /**
     * a type name of digits is a legal member name, so `fields[12]` is a real query
     */
    public function testALinkCarriesAQueryParameterNamedWithDigits(): void
    {
        $read = [];
        parse_str('0=x&fields[12]=name', $read);

        self::assertSame(
            'https://a.example/wp-json/ns/pets?0=x&fields%5B12%5D=name',
            PlainUrls::httpQuery('https://a.example/wp-json/ns')->url(['pets'], $read),
        );
    }

    /**
     * the links inside a resource object carry no query
     */
    public function testTheQueryMayBeLeftOut(): void
    {
        self::assertSame(
            'https://a.example/wp-json/ns/pets/1/relationships/tags',
            PlainUrls::httpQuery('https://a.example/wp-json/ns')->url(['pets', '1', 'relationships', 'tags']),
        );
    }

    private static function typeName(?ReflectionType $type): string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : '';
    }
}
