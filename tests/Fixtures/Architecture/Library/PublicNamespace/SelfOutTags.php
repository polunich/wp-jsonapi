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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a public generic class whose `@phpstan-self-out` tag names a class that is not public API
 * (`publicApi.signature`); the tag of `holdListed()` names a listed class and leaks nothing
 *
 * @template T of object
 */
final class SelfOutTags
{
    /**
     * @var list<T>
     */
    private array $held = [];

    /**
     * @phpstan-self-out self<InternalValue>
     */
    public function holdInternal(): void
    {
        $this->held = [];
    }

    /**
     * @phpstan-self-out self<ListedValue>
     */
    public function holdListed(): void
    {
        $this->held = [];
    }

    public function count(): int
    {
        return count($this->held);
    }
}
