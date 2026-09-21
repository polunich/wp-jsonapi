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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace;

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a public class whose property default reads a constant of a class that is not public API,
 * with a type that keeps no class (`publicApi.signature`).
 * the default of `$listed` names a listed class and leaks nothing.
 * the default of `$fallback` holds an internal enum case only through a private constant
 * of the class itself, so it names no class of the library and leaks nothing.
 * the promoted `$promoted` has no default of its own:
 * the default belongs to the constructor parameter and is reported there once
 */
final class PropertyDefaults
{
    private const FALLBACK = InternalState::Off;

    public int $limit = InternalValue::LIMIT;

    public string $listed = ListedValue::class;

    public object $fallback = self::FALLBACK;

    public function __construct(
        public int $promoted = InternalValue::LIMIT,
    ) {}
}
