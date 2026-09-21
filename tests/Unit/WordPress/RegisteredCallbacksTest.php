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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\WordPress\RegisteredCallbacks;
use ReflectionClass;
use ReflectionProperty;
use WeakMap;

/**
 * @internal
 */
#[CoversClass(RegisteredCallbacks::class)]
final class RegisteredCallbacksTest extends TestCase
{
    public function testTheClassIsFinalMutableAndInternal(): void
    {
        $class = new ReflectionClass(RegisteredCallbacks::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testRouteOfReturnsTheRouteOfARecordedCallback(): void
    {
        $callbacks = new RegisteredCallbacks();
        $collection = new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets']);
        $create = new OperationRoute(OperationKind::CreateResource, 'pets', null, null, ['pets']);
        $read = static function (): null {
            return null;
        };
        $write = static function (): null {
            return null;
        };

        $callbacks->record($read, $collection);
        $callbacks->record($write, $create);

        self::assertSame($collection, $callbacks->routeOf($read));
        self::assertSame($create, $callbacks->routeOf($write));
    }

    /**
     * a closure is known by identity: an equal closure is another callback
     */
    public function testRouteOfReturnsNoRouteForACallbackTheLibraryDidNotRecord(): void
    {
        $callbacks = new RegisteredCallbacks();
        $callbacks->record(static function (): null {
            return null;
        }, new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets']));

        self::assertNull($callbacks->routeOf(static function (): null {
            return null;
        }));
        self::assertNull($callbacks->routeOf('rest_handle_options_request'));
        self::assertNull($callbacks->routeOf(null));
    }

    public function testTheCallbacksOfAServerNoOneHoldsLeaveNoEntry(): void
    {
        $callbacks = new RegisteredCallbacks();
        $callback = static function (): null {
            return null;
        };
        $callbacks->record($callback, new OperationRoute(OperationKind::FetchCollection, 'pets', null, null, ['pets']));
        $routes = (new ReflectionProperty(RegisteredCallbacks::class, 'routes'))->getValue($callbacks);

        self::assertInstanceOf(WeakMap::class, $routes);
        self::assertCount(1, $routes);

        unset($callback);

        self::assertCount(0, $routes);
    }
}
