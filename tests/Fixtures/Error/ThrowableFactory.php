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

namespace Polunich\WpJsonApi\Tests\Fixtures\Error;

use Throwable;
use UnexpectedValueException;

/**
 * creates a throwable in a file and a function of its own,
 * so that its file and trace differ from those of a throwable the test creates
 *
 * @internal
 */
final class ThrowableFactory
{
    public static function create(string $message, ?Throwable $previous = null): UnexpectedValueException
    {
        return new UnexpectedValueException($message, 0, $previous);
    }
}
