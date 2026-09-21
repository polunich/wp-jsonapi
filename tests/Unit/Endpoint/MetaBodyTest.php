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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Endpoint\MetaBody;
use ReflectionClass;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(MetaBody::class)]
final class MetaBodyTest extends TestCase
{
    public function testTheValueIsPublicFinalAndReadonly(): void
    {
        $class = new ReflectionClass(MetaBody::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/^\s*\*\s*@internal\b/m', (string) $class->getDocComment());
    }

    public function testTheValueCarriesWhatAHandlerReads(): void
    {
        self::assertSame(
            [['value', 'mixed']],
            array_map(static function (ReflectionProperty $property): array {
                return [$property->getName(), (string) $property->getType()];
            }, (new ReflectionClass(MetaBody::class))->getProperties(ReflectionProperty::IS_PUBLIC)),
        );
    }
}
