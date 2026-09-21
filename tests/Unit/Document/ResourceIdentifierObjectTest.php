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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ResourceIdentifierObject::class)]
final class ResourceIdentifierObjectTest extends TestCase
{
    public function testTypeAndIdIdentifyTheResource(): void
    {
        $identifier = new ResourceIdentifierObject('customers', '9');

        self::assertSame('customers', $identifier->type);
        self::assertSame('9', $identifier->id);
        self::assertNull($identifier->meta);
    }

    public function testMetaIsOptional(): void
    {
        $identifier = new ResourceIdentifierObject('customers', '9', ['role' => 'owner']);

        self::assertSame(['role' => 'owner'], $identifier->meta);
    }

    public function testTheObjectHasTypeIdAndMetaAndNothingElse(): void
    {
        $constructor = (new ReflectionClass(ResourceIdentifierObject::class))->getConstructor();

        self::assertNotNull($constructor);

        $names = array_map(
            static function (ReflectionParameter $parameter): string {
                return $parameter->getName();
            },
            $constructor->getParameters(),
        );

        self::assertSame(['type', 'id', 'meta'], $names);
    }
}
