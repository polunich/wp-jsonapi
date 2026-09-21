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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Greeter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\PoliteGreeter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(ImplementationReference::class)]
final class ImplementationReferenceTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ImplementationReference::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheThreeFactoriesAndTheirPropertiesKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(ImplementationReference::class);
        $signature = static function (string $method) use ($class): array {
            return array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod($method)->getParameters(),
            );
        };
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertSame(['factory', 'instance', 'service'], $methods);
        self::assertSame([['entryId', 'string']], $signature('service'));
        self::assertSame([['implementation', 'object']], $signature('instance'));
        self::assertSame([['factory', Closure::class]], $signature('factory'));
        self::assertSame(
            [['entryId', '?string', true], ['instance', '?object', true], ['factory', '?Closure', true]],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [
                        $property->getName(),
                        (string) $property->getType(),
                        $property->isPublic(),
                    ];
                },
                $class->getProperties(),
            ),
        );
    }

    public function testNamesAServiceByItsEntryIdAlone(): void
    {
        $reference = ImplementationReference::service(Greeter::class);

        self::assertSame(Greeter::class, $reference->entryId);
        self::assertNull($reference->instance);
        self::assertNull($reference->factory);
    }

    public function testRefusesAnEmptyEntryId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an implementation is looked up in the container by its entry id, which is'
            . ' never empty');

        ImplementationReference::service('');
    }

    public function testHoldsAnInstanceAlone(): void
    {
        $implementation = new PoliteGreeter();
        $reference = ImplementationReference::instance($implementation);

        self::assertNull($reference->entryId);
        self::assertSame($implementation, $reference->instance);
        self::assertNull($reference->factory);
    }

    public function testHoldsAFactoryAloneAndDoesNotCallIt(): void
    {
        $calls = 0;
        $factory = static function () use (&$calls): Greeter {
            ++$calls;

            return new PoliteGreeter();
        };
        $reference = ImplementationReference::factory($factory);

        self::assertNull($reference->entryId);
        self::assertNull($reference->instance);
        self::assertSame($factory, $reference->factory);
        self::assertSame(0, $calls);
    }
}
