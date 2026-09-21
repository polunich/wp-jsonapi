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

namespace Polunich\WpJsonApi\Error;

use Throwable;

/**
 * maps exceptions of one type to the library exception a response is built from.
 *
 * a project registers a mapper for each exception of its own
 * that the library should not respond to with 500,
 * and the mapper of the nearest type wins.
 * `T` is covariant and `map()` takes `Throwable` rather than `T`,
 * so that mappers of different types fit in one list
 *
 * @template-covariant T of Throwable
 */
interface ExceptionMapper
{
    /**
     * a class or an interface; the mapper claims every instance of it
     *
     * @return class-string<T>
     */
    public function exceptionClass(): string;

    /**
     * receives only instances of `exceptionClass()`;
     * a mapper that needs their members narrows the parameter with `instanceof`.
     * a mapper returns rather than throws: the library responds to whatever it throws with 500,
     * as Jakarta RESTful Web Services 3.1 section 3.3.4 prescribes
     */
    public function map(Throwable $exception): JsonApiException;
}
