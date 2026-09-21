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

namespace Polunich\WpJsonApi\Tests\Fixtures\OpenApi;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * a member of a schema object, asserted to be there and of its kind
 *
 * @internal
 */
final readonly class SchemaMembers
{
    public static function text(stdClass $object, string $name): string
    {
        $members = get_object_vars($object);

        Assert::assertArrayHasKey($name, $members);
        Assert::assertIsString($members[$name]);

        return $members[$name];
    }

    /**
     * @param array<array-key, mixed>|stdClass $holder
     */
    public static function object(array|stdClass $holder, string $name): stdClass
    {
        $members = is_array($holder) ? $holder : get_object_vars($holder);

        Assert::assertArrayHasKey($name, $members);
        Assert::assertInstanceOf(stdClass::class, $members[$name]);

        return $members[$name];
    }
}
