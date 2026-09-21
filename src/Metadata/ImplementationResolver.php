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

namespace Polunich\WpJsonApi\Metadata;

/**
 * it lies beside `ImplementationReference`, so a module reading the declarations
 * resolves an implementation without depending on the container behind it.
 * it is internal: publishing it would publish `ImplementationReference` too
 *
 * @internal
 */
interface ImplementationResolver
{
    /**
     * @template T of object
     *
     * @param class-string<T> $port the interface the implementation is bound as
     *
     * @return T
     */
    public function resolve(ImplementationReference $reference, string $port): object;
}
