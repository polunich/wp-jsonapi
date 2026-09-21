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

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Greeter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\PoliteGreeter;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;
use UnexpectedValueException;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ImplementationResolverTest extends TestCase
{
    public function testTheInterfaceLivesInMetadataAndIsInternal(): void
    {
        $interface = new ReflectionClass(ImplementationResolver::class);

        self::assertTrue($interface->isInterface());
        self::assertSame(
            (new ReflectionClass(ImplementationReference::class))->getNamespaceName(),
            $interface->getNamespaceName(),
        );
        self::assertStringContainsString('@internal', (string) $interface->getDocComment());
    }

    public function testResolveTakesAReferenceAndAPortAndReturnsAnObject(): void
    {
        $method = new ReflectionMethod(ImplementationResolver::class, 'resolve');

        self::assertSame(
            [['reference', ImplementationReference::class], ['port', 'string']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $method->getParameters(),
            ),
        );
        self::assertSame('object', (string) $method->getReturnType());
    }

    /**
     * the template is what types the result as the port the caller asked for
     */
    public function testDeclaresThatItReturnsAnInstanceOfThePort(): void
    {
        $declaration = (string) (new ReflectionMethod(ImplementationResolver::class, 'resolve'))->getDocComment();

        self::assertMatchesRegularExpression('/@template\s+T\s+of\s+object/', $declaration);
        self::assertMatchesRegularExpression('/@param\s+class-string<T>\s+\$port/', $declaration);
        self::assertMatchesRegularExpression('/@return\s+T\b/', $declaration);
    }

    #[DataProvider('references')]
    public function testAnImplementationResolvesEveryReferenceToThePort(ImplementationReference $reference): void
    {
        $greeter = self::resolver(['greeter' => new PoliteGreeter('good morning')])->resolve(
            $reference,
            Greeter::class,
        );

        self::assertStringEndsWith(', ada', $greeter->greet('ada'));
    }

    /**
     * @return iterable<string, array{ImplementationReference}>
     */
    public static function references(): iterable
    {
        yield 'a container entry id' => [ImplementationReference::service('greeter')];
        yield 'an instance' => [ImplementationReference::instance(new PoliteGreeter())];
        yield 'a factory closure' => [ImplementationReference::factory(static function (): Greeter {
            return new PoliteGreeter('hi');
        })];
    }

    public function testAnImplementationRefusesAnObjectThatIsNoInstanceOfThePort(): void
    {
        $this->expectException(UnexpectedValueException::class);

        self::resolver([])->resolve(ImplementationReference::instance(new stdClass()), Greeter::class);
    }

    /**
     * @param array<string, object> $services
     */
    private static function resolver(array $services): ImplementationResolver
    {
        return new class ($services) implements ImplementationResolver {
            /**
             * @param array<string, object> $services
             */
            public function __construct(private readonly array $services) {}

            /**
             * @template T of object
             *
             * @param class-string<T> $port
             *
             * @return T
             */
            #[Override]
            public function resolve(ImplementationReference $reference, string $port): object
            {
                $implementation = match (true) {
                    $reference->entryId !== null => $this->services[$reference->entryId] ?? null,
                    $reference->factory !== null => ($reference->factory)(),
                    default => $reference->instance,
                };

                if (!$implementation instanceof $port) {
                    throw new UnexpectedValueException('the implementation is no instance of the port');
                }

                return $implementation;
            }
        };
    }
}
