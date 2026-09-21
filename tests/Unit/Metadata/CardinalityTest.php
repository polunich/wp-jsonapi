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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\Cardinality;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(Cardinality::class)]
final class CardinalityTest extends TestCase
{
    public function testTheCasesAreToOneAndToMany(): void
    {
        self::assertSame(
            ['ToOne' => 'to_one', 'ToMany' => 'to_many'],
            array_combine(
                array_map(static function (Cardinality $cardinality): string {
                    return $cardinality->name;
                }, Cardinality::cases()),
                array_map(static function (Cardinality $cardinality): string {
                    return $cardinality->value;
                }, Cardinality::cases()),
            ),
        );
    }

    public function testTheEnumIsInternal(): void
    {
        self::assertStringContainsString(
            '@internal',
            (string) (new ReflectionEnum(Cardinality::class))->getDocComment(),
        );
    }
}
