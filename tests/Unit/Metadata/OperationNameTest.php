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
use Polunich\WpJsonApi\Metadata\OperationName;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(OperationName::class)]
final class OperationNameTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(OperationName::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('acceptedNames')]
    public function testANameOfTheConventionsPasses(string $name): void
    {
        OperationName::check($name, 'an endpoint');

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedNames(): iterable
    {
        yield 'camel case' => ['adoptPet'];
        yield 'snake case' => ['adopt_pet'];
        yield 'a leading underscore' => ['_adopt'];
        yield 'digits after the first character' => ['adopt2'];
        yield 'the former fixed name of the atomic route' => ['atomic_operations'];
    }

    #[DataProvider('refusedNames')]
    public function testANameOutsideTheConventionsIsRefusedWithItsOwner(string $name, string $shown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'an atomic route is named "%s", and its name is its operationId, which OpenAPI recommends to "follow'
            . ' common programming naming conventions": a letter or "_", then letters, digits and "_"',
            $shown,
        ));

        OperationName::check($name, 'an atomic route');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'a leading digit' => ['1adopt', '1adopt'];
        yield 'a dot, which every generated operationId holds' => ['fetch_resource.pets', 'fetch_resource.pets'];
        yield 'a hyphen' => ['adopt-pet', 'adopt-pet'];
        yield 'a control character, written escaped' => ["adopt\npet", 'adopt\npet'];
    }
}
