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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Metadata\SecuritySchemes;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SecuritySchemes::class)]
final class SecuritySchemesTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(SecuritySchemes::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testDistinctNamesAndNoSchemePass(): void
    {
        SecuritySchemes::checkDistinct([SecurityScheme::restNonce(), SecurityScheme::applicationPasswords()], 'x');
        SecuritySchemes::checkDistinct([], 'x');

        $this->addToAssertionCount(1);
    }

    public function testANameListedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" declares the security scheme "restNonce" twice');

        SecuritySchemes::checkDistinct(
            [SecurityScheme::restNonce(), new SecurityScheme('restNonce', ['type' => 'apiKey'])],
            'the endpoint "adoptPet"',
        );
    }
}
