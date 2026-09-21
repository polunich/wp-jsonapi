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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class TransactionBoundaryTest extends TestCase
{
    public function testThePortIsAPublicInterface(): void
    {
        $interface = new ReflectionClass(TransactionBoundary::class);

        self::assertTrue($interface->isInterface());
        self::assertStringNotContainsString('@internal', (string) $interface->getDocComment());
    }

    public function testThePortDeclaresBeginCommitAndRollBack(): void
    {
        self::assertSame(
            ['begin' => [[], 'void'], 'commit' => [[], 'void'], 'rollBack' => [[], 'void']],
            PortSignatureReader::methods(TransactionBoundary::class),
        );
    }
}
