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
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;
use ReflectionMethod;

/**
 * `CoversClass` on an interface warns once coverage is collected:
 * php-code-coverage maps no interface as a class target
 *
 * @internal
 */
#[CoversNothing]
final class ResourceLoaderTest extends TestCase
{
    public function testThePortIsAPublicInterface(): void
    {
        $interface = new ReflectionClass(ResourceLoader::class);

        self::assertTrue($interface->isInterface());
        self::assertStringNotContainsString('@internal', (string) $interface->getDocComment());
    }

    /**
     * the parameter name is public API
     */
    public function testThePortDeclaresLoadAlone(): void
    {
        self::assertSame(
            ['load' => [['request:' . LoadRequest::class], 'array']],
            PortSignatureReader::methods(ResourceLoader::class),
        );
    }

    public function testReturnsAListOfRecordsOrNullOfATemplateOfObject(): void
    {
        $load = new ReflectionMethod(ResourceLoader::class, 'load');

        self::assertSame(
            ['TRecord of object'],
            PortSignatureReader::tags(new ReflectionClass(ResourceLoader::class), 'template', true),
        );
        self::assertSame(['list<TRecord|null>'], PortSignatureReader::tags($load, 'return'));
        self::assertSame([], PortSignatureReader::throws($load));
    }
}
