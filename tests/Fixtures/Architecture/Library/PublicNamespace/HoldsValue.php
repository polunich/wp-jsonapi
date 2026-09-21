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

/**
 * a generic trait of the public namespace; a user gives its type argument in the `@use` tag.
 * `held()` returns the argument type, so an internal argument would leak through it as well,
 * but the method is read on the trait, where it names only the template,
 * and the `@use` tag reports the leak once
 *
 * @template T of object
 */
trait HoldsValue
{
    /**
     * @var T|null
     */
    private ?object $held = null;

    /**
     * @param T $value
     */
    public function hold(object $value): void
    {
        $this->held = $value;
    }

    /**
     * @return T|null
     */
    public function held(): ?object
    {
        return $this->held;
    }
}
