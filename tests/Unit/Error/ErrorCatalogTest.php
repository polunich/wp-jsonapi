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

namespace Polunich\WpJsonApi\Tests\Unit\Error;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ErrorCatalogTest extends TestCase
{
    /**
     * a project implements the port, so its signature, the parameter names included, is public API
     */
    public function testThePortDeclaresTheTitleOfACodeAndTheDetailOfACodeAndItsParameters(): void
    {
        $title = new ReflectionMethod(ErrorCatalog::class, 'title');
        $detail = new ReflectionMethod(ErrorCatalog::class, 'detail');

        self::assertSame(['code'], self::parameterNames($title));
        self::assertSame(['code', 'parameters'], self::parameterNames($detail));
        self::assertSame('?string', (string) $title->getReturnType());
        self::assertSame('?string', (string) $detail->getReturnType());
        self::assertSame('string', (string) $title->getParameters()[0]->getType());
        self::assertSame('string', (string) $detail->getParameters()[0]->getType());
        self::assertSame('array', (string) $detail->getParameters()[1]->getType());
    }

    public function testThePortIsPublicApi(): void
    {
        $declaration = (new ReflectionClass(ErrorCatalog::class))->getDocComment();

        self::assertIsString($declaration);
        self::assertStringNotContainsString('@internal', $declaration);
    }

    /**
     * the native type is only `array`, so the map type lives in the docblock
     */
    public function testThePortDeclaresAMapOfScalarParameters(): void
    {
        $declaration = (new ReflectionMethod(ErrorCatalog::class, 'detail'))->getDocComment();

        self::assertIsString($declaration);
        self::assertMatchesRegularExpression('/@param\s+array<string, scalar>\s+\$parameters/', $declaration);
    }

    public function testAProjectWordsTheTextsFromTheCodeAndTheParameters(): void
    {
        $catalog = self::projectCatalog();

        self::assertSame('the page is too large', $catalog->title('page_too_large'));
        self::assertSame('the page size is at most 100', $catalog->detail('page_too_large', ['maxSize' => 100]));
    }

    public function testAProjectReturnsNullForACodeItDoesNotWord(): void
    {
        $catalog = self::projectCatalog();

        self::assertNull($catalog->title('rest_no_route'));
        self::assertNull($catalog->detail('rest_no_route', []));
        self::assertSame('', $catalog->title('silent'));
        self::assertSame('', $catalog->detail('silent', []));
    }

    private static function projectCatalog(): ErrorCatalog
    {
        return new class implements ErrorCatalog {
            #[Override]
            public function title(string $code): ?string
            {
                return match ($code) {
                    'page_too_large' => 'the page is too large',
                    'silent' => '',
                    default => null,
                };
            }

            /**
             * @param array<string, scalar> $parameters
             */
            #[Override]
            public function detail(string $code, array $parameters): ?string
            {
                return match ($code) {
                    'page_too_large' => 'the page size is at most ' . (string) ($parameters['maxSize'] ?? ''),
                    'silent' => '',
                    default => null,
                };
            }
        };
    }

    /**
     * @return list<string>
     */
    private static function parameterNames(ReflectionMethod $method): array
    {
        return array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $method->getParameters(),
        );
    }
}
