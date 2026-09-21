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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Declaration\AtomicRoute;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AtomicRoute::class)]
final class AtomicRouteTest extends TestCase
{
    public function testTheBuilderIsPublicFinalAndMutable(): void
    {
        $class = new ReflectionClass(AtomicRoute::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getConstructor()?->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getMethod('build')->getDocComment());
    }

    public function testADeclarationBuildsItsMetadata(): void
    {
        $route = (new AtomicRoute('/pets/operations'))
            ->name('petOperations')
            ->types('pets', 'orders')
            ->tag('bulk')
            ->description('the operations on pets and orders')
            ->securitySchemes(SecurityScheme::applicationPasswords())
            ->build(self::registry());

        self::assertSame('petOperations', $route->name);
        self::assertSame('/pets/operations', $route->template->path);
        self::assertSame(['pets', 'orders'], $route->types);
        self::assertSame('bulk', $route->tag);
        self::assertSame('the operations on pets and orders', $route->description);
        self::assertEquals([SecurityScheme::applicationPasswords()], $route->securitySchemes);
    }

    public function testWithoutTypesTheRouteTakesEveryTypeInDeclarationOrder(): void
    {
        $route = (new AtomicRoute('/operations'))->name('atomicOperations')->build(self::registry());

        self::assertSame(['pets', 'customers', 'orders'], $route->types);
        self::assertNull($route->tag);
        self::assertNull($route->description);
        self::assertNull($route->securitySchemes);
    }

    public function testTheMetadataIsReadWhenItIsBuilt(): void
    {
        $declaration = (new AtomicRoute('/operations'))->name('atomicOperations');
        $route = $declaration->build(self::registry());

        $declaration->types('pets');

        self::assertSame(['pets', 'customers', 'orders'], $route->types);
    }

    /**
     * @param callable(AtomicRoute): mixed $second
     */
    #[DataProvider('partsWrittenTwice')]
    public function testAPartWrittenTwiceIsRefused(callable $second, string $part): void
    {
        $route = (new AtomicRoute('/pets/operations'))
            ->name('petOperations')
            ->types('pets')
            ->tag('bulk')
            ->description('the operations on pets')
            ->securitySchemes();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the atomic route "petOperations" declares %s twice, and each part of a declaration is written once',
            $part,
        ));

        $second($route);
    }

    /**
     * @return iterable<string, array{callable(AtomicRoute): mixed, string}>
     */
    public static function partsWrittenTwice(): iterable
    {
        yield 'the name' => [static function (AtomicRoute $route): mixed {
            return $route->name('again');
        }, 'a name'];
        yield 'the types' => [static function (AtomicRoute $route): mixed {
            return $route->types('orders');
        }, 'its types'];
        yield 'the tag' => [static function (AtomicRoute $route): mixed {
            return $route->tag('again');
        }, 'a tag'];
        yield 'the description' => [static function (AtomicRoute $route): mixed {
            return $route->description('again');
        }, 'a description'];
        yield 'the security schemes' => [static function (AtomicRoute $route): mixed {
            return $route->securitySchemes(SecurityScheme::restNonce());
        }, 'its security schemes'];
    }

    public function testAPartWrittenTwiceBeforeTheNameNamesThePath(): void
    {
        $route = (new AtomicRoute('/pets/operations'))->tag('bulk');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route /pets/operations declares a tag twice');

        $route->tag('again');
    }

    public function testTypesNamesOneTypeAtLeast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the atomic route /operations names no type in types(), so it would accept no operation',
        );

        (new AtomicRoute('/operations'))->types();
    }

    public function testARouteWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the atomic route /operations declares no name, and its name is the operationId of the contract',
        );

        (new AtomicRoute('/operations'))->build(self::registry());
    }

    public function testATypeThatIsNotDeclaredIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "petOperations" names type "tags", which is not declared');

        (new AtomicRoute('/pets/operations'))
            ->name('petOperations')
            ->types('pets', 'tags')
            ->build(self::registry());
    }

    public function testATypeNamedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "petOperations" names type "pets" twice');

        (new AtomicRoute('/pets/operations'))
            ->name('petOperations')
            ->types('pets', 'pets')
            ->build(self::registry());
    }

    #[DataProvider('pathsWithAParameter')]
    public function testAPathWithAParameterIsRefused(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the atomic route "petOperations" writes the path "%s" with a parameter, and an atomic route takes'
            . ' none: each operation names its target in the document',
            $path,
        ));

        (new AtomicRoute($path))->name('petOperations')->build(self::registry());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsWithAParameter(): iterable
    {
        yield 'a parameter' => ['/pets/{id}/operations'];
        yield 'a brace alone' => ['/pets/{operations'];
    }

    public function testAPathAnEndpointCouldNotWriteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the atomic route "petOperations" writes the segment "pet.operations" in its path',
        );

        (new AtomicRoute('/pet.operations'))->name('petOperations')->build(self::registry());
    }

    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource('pets'),
            Samples::resource('customers'),
            Samples::resource('orders'),
        ]);
    }
}
