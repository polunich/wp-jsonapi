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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;

/**
 * pins the signatures: projects implement the interface,
 * and parameter names are public API through named arguments
 *
 * @internal
 */
#[CoversClass(Codec::class)]
final class CodecTest extends TestCase
{
    /**
     * @param list<array{string, string, bool}> $expected parameter name, type and whether optional
     */
    #[DataProvider('signatures')]
    public function testTheInterfaceDeclaresTheSignatureOfItsMethod(
        string $method,
        array $expected,
        string $returnType,
    ): void {
        $reflection = new ReflectionMethod(Codec::class, $method);

        $actual = array_map(
            static function (ReflectionParameter $parameter): array {
                return [
                    $parameter->getName(),
                    self::typeName($parameter->getType()),
                    $parameter->isOptional(),
                ];
            },
            $reflection->getParameters(),
        );

        self::assertSame($expected, $actual);
        self::assertSame($returnType, self::typeName($reflection->getReturnType()));
        self::assertTrue($reflection->isPublic());
    }

    /**
     * @return iterable<string, array{string, list<array{string, string, bool}>, string}>
     */
    public static function signatures(): iterable
    {
        yield 'decode' => [
            'decode',
            [['input', 'mixed', false], ['pointer', Pointer::class, false]],
            'mixed',
        ];
        yield 'encode' => ['encode', [['value', 'mixed', false]], 'mixed'];
        yield 'schema' => ['schema', [], Schema::class];
    }

    /**
     * an added method would break every codec a project implements
     */
    public function testEveryMethodOfTheInterfaceIsDeclared(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(Codec::class))->getMethods(),
        );

        sort($methods);

        self::assertSame(['decode', 'encode', 'schema'], $methods);
    }

    private static function typeName(?ReflectionType $type): string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : '';
    }
}
