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
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Metadata\AtomicRouteMetadata;
use Polunich\WpJsonApi\Routing\PathTemplate;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(AtomicRouteMetadata::class)]
final class AtomicRouteMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(AtomicRouteMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $template = PathTemplate::written('/pets/operations', [], 'the atomic route "petOperations"');
        $route = new AtomicRouteMetadata('petOperations', $template, ['pets', 'orders'], 'bulk', 'on pets');

        self::assertSame('petOperations', $route->name);
        self::assertSame($template, $route->template);
        self::assertSame(['pets', 'orders'], $route->types);
        self::assertSame('bulk', $route->tag);
        self::assertSame('on pets', $route->description);
    }

    public function testAcceptsTheTypesItTakesAlone(): void
    {
        $route = Samples::atomicRoute(['pets', 'orders'], 'petOperations', '/pets/operations');

        self::assertTrue($route->accepts('pets'));
        self::assertTrue($route->accepts('orders'));
        self::assertFalse($route->accepts('customers'));
        self::assertFalse($route->accepts('Pets'));
    }

    public function testANameOutsideTheConventionsIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic route is named "pet operations", and its name is its operationId');

        Samples::atomicRoute(name: 'pet operations');
    }

    public function testATemplateWithAParameterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "petOperations" lies at "/pets/{id}/operations", which'
            . ' holds a parameter, and an atomic route takes none: each operation names its target in the'
            . ' document');

        new AtomicRouteMetadata(
            'petOperations',
            PathTemplate::written('/pets/{id}/operations', ['id' => new DigitsCodec()], 'a route'),
            ['pets'],
            null,
            null,
        );
    }

    public function testARouteOfNoTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "atomicOperations" takes no type, so no operation could run'
            . ' on it');

        Samples::atomicRoute([]);
    }

    public function testASecuritySchemeNamedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the atomic route "atomicOperations" declares the security scheme "restNonce" twice',
        );

        Samples::atomicRoute(securitySchemes: [SecurityScheme::restNonce(), SecurityScheme::restNonce()]);
    }

    public function testATypeNamedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "atomicOperations" names type "pets" twice');

        Samples::atomicRoute(['pets', 'orders', 'pets']);
    }

    #[DataProvider('emptyTexts')]
    public function testAnEmptyTextIsRefused(?string $tag, ?string $description, string $part): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the atomic route "atomicOperations" declares an empty %s, and a declared text is never empty',
            $part,
        ));

        Samples::atomicRoute(tag: $tag, description: $description);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function emptyTexts(): iterable
    {
        yield 'the tag' => ['', null, 'tag'];
        yield 'the description' => [null, '', 'description'];
    }
}
