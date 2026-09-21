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

namespace Polunich\WpJsonApi\Tests\Unit\Endpoint;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response;
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
final class HandlerTest extends TestCase
{
    /**
     * the parameter name is public API: PHP matches named arguments against it
     */
    public function testThePortDeclaresOneMethod(): void
    {
        $port = new ReflectionClass(Handler::class);
        $method = new ReflectionMethod(Handler::class, 'handle');
        $type = $method->getReturnType();

        self::assertTrue($port->isInterface());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $port->getDocComment());
        self::assertCount(1, $port->getMethods());
        self::assertSame('request', $method->getParameters()[0]->getName());
        self::assertSame(Request::class, (string) $method->getParameters()[0]->getType());
        self::assertInstanceOf(ReflectionNamedType::class, $type);
        self::assertSame(Response::class, $type->getName());
    }
}
