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

namespace Polunich\WpJsonApi\Tests\Unit\Access;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use ReflectionClass;
use ReflectionMethod;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class EndpointPermissionTest extends TestCase
{
    /**
     * the parameter name is public API: PHP matches named arguments against it
     */
    public function testThePortDeclaresOneMethod(): void
    {
        $port = new ReflectionClass(EndpointPermission::class);
        $method = new ReflectionMethod(EndpointPermission::class, 'allows');

        self::assertTrue($port->isInterface());
        self::assertSame('Polunich\WpJsonApi\Access', $port->getNamespaceName());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $port->getDocComment());
        self::assertCount(1, $port->getMethods());
        self::assertSame('attempt', $method->getParameters()[0]->getName());
        self::assertSame(EndpointAttempt::class, (string) $method->getParameters()[0]->getType());
        self::assertSame('bool', (string) $method->getReturnType());
    }
}
