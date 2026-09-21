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
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\WordPress\CurrentUserIdentity;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CurrentUserIdentity::class)]
final class CurrentUserIdentityTest extends TestCase
{
    public function testTheClassIsAFinalReadonlyInternalIdentity(): void
    {
        $class = new ReflectionClass(CurrentUserIdentity::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->implementsInterface(Identity::class));
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheCurrentUserIsAskedEachTimeAndNeverAhead(): void
    {
        $results = [true, false];
        $asked = 0;
        $identity = new CurrentUserIdentity(static function () use (&$results, &$asked): bool {
            ++$asked;

            return (bool) array_shift($results);
        });

        self::assertSame(0, $asked);
        self::assertTrue($identity->isAuthenticated());
        self::assertFalse($identity->isAuthenticated());
        self::assertSame(2, $asked);
    }
}
