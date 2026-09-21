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

use InvalidArgumentException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a public class whose assert tags of all three kinds name a class that is not public API
 * (`publicApi.signature`); the tag of `assertListed()` names a listed class and leaks nothing
 */
final class AssertTags
{
    /**
     * @phpstan-assert InternalValue $value
     */
    public function assertInternal(mixed $value): void
    {
        if (!$value instanceof InternalValue) {
            throw new InvalidArgumentException('not an internal value');
        }
    }

    /**
     * @phpstan-assert-if-true InternalValue $value
     */
    public function isInternal(mixed $value): bool
    {
        return $value instanceof InternalValue;
    }

    /**
     * @phpstan-assert-if-false InternalValue $value
     */
    public function isOther(mixed $value): bool
    {
        return !$value instanceof InternalValue;
    }

    /**
     * @phpstan-assert ListedValue $value
     */
    public function assertListed(mixed $value): void
    {
        if (!$value instanceof ListedValue) {
            throw new InvalidArgumentException('not a listed value');
        }
    }
}
