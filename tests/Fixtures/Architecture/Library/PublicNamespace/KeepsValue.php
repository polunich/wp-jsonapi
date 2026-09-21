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
 * a second generic trait of the public namespace,
 * whose type argument a class or a trait that uses it gives in its `@use` tag
 *
 * @template T of object
 */
trait KeepsValue
{
    /**
     * @var list<T>
     */
    private array $kept = [];

    /**
     * @param T $value
     */
    public function keep(object $value): void
    {
        $this->kept[] = $value;
    }

    /**
     * @return list<T>
     */
    public function kept(): array
    {
        return $this->kept;
    }
}
