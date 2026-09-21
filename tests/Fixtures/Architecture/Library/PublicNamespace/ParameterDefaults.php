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
 * a public class whose parameter defaults write the names of classes that are not public API
 * (`publicApi.signature`): a new object, an enum case, and a class constant, alone and in an array,
 * whose type keeps no class.
 * the default of `fallback()` holds an internal enum case only through a private constant
 * of the class itself, so it names no class of the library and leaks nothing.
 * the defaults of `listed()` name a listed class, an enum of the public namespace
 * and a constant of the class itself, and leak nothing
 */
final class ParameterDefaults
{
    public const LIMIT = 1;

    private const FALLBACK = InternalState::Off;

    public function create(object $value = new InternalValue()): object
    {
        return $value;
    }

    public function state(object $state = InternalState::On): object
    {
        return $state;
    }

    public function fallback(object $state = self::FALLBACK): object
    {
        return $state;
    }

    public function limit(int $limit = InternalValue::LIMIT): int
    {
        return $limit;
    }

    /**
     * @param list<int> $limits
     *
     * @return list<int>
     */
    public function limits(array $limits = [InternalValue::LIMIT]): array
    {
        return $limits;
    }

    /**
     * @return list<mixed>
     */
    public function listed(
        string $class = ListedValue::class,
        object $mode = Mode::On,
        ?ListedValue $value = null,
        int $limit = self::LIMIT,
    ): array {
        return [$class, $mode, $value, $limit];
    }
}
