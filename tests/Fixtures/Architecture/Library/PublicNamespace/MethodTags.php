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
 * a public class whose `@method` tags name a class that is not public API (`publicApi.signature`):
 * in a return type and in a parameter type;
 * in a parameter default, which reads a constant of the class alone, in an array and as a key;
 * in a template bound, which the parameter and the return type name through it;
 * and in a template default.
 * the default of `fallback()` holds an internal enum case only through a constant
 * of the class itself, so it names no class of the library and leaks nothing.
 * `listed()` names a listed class in each of these places and leaks nothing
 *
 * @method InternalValue first()
 * @method void accept(InternalValue $value)
 * @method int limit(int $limit = InternalValue::LIMIT)
 * @method int limits(list<int> $limits = [InternalValue::LIMIT])
 * @method int keyed(array<int, bool> $limits = [InternalValue::LIMIT => true])
 * @method object fallback(object $state = self::FALLBACK)
 * @method U same<U of InternalValue>(U $value)
 * @method V made<V = InternalValue>(V $value)
 * @method W listed<W of ListedValue = ListedValue>(W $value, string $name = ListedValue::NAME)
 */
final class MethodTags
{
    private const FALLBACK = InternalState::Off;

    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $arguments[0] ?? self::FALLBACK;
    }
}
