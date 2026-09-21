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

namespace Polunich\WpJsonApi\Tests\Unit\Authentication;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Identity;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class IdentityTest extends TestCase
{
    public function testThePortAsksForNothingAndReturnsABoolean(): void
    {
        $method = new ReflectionMethod(Identity::class, 'isAuthenticated');
        $type = $method->getReturnType();

        self::assertSame([], $method->getParameters());
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame('bool', $type->getName());
    }

    #[DataProvider('clients')]
    public function testThePortTellsWhetherTheClientIsAuthenticated(bool $loggedIn): void
    {
        $identity = new class ($loggedIn) implements Identity {
            public function __construct(private readonly bool $loggedIn) {}

            #[Override]
            public function isAuthenticated(): bool
            {
                return $this->loggedIn;
            }
        };

        self::assertSame($loggedIn, $identity->isAuthenticated());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function clients(): array
    {
        return [
            'a logged-in user' => [true],
            'a logged-out client' => [false],
        ];
    }

    public function testThePortCarriesOneMethodOnly(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(Identity::class))->getMethods(),
        );

        self::assertSame(['isAuthenticated'], $methods);
    }
}
