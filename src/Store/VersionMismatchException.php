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

namespace Polunich\WpJsonApi\Store;

use RuntimeException;
use Throwable;

/**
 * the stored version of a record that exists is not one `$operation->precondition` admits;
 * the write gets the same 412 as an If-Match that fails before the write.
 *
 * the precondition held for the record loaded before the write;
 * the store checks it again inside the write, since only the write is atomic:
 * make it a condition of the write itself, such as `WHERE version IN (...)`.
 * a null precondition asks for an unconditional write, and `If-Match: *` admits every version,
 * so this exception thrown for either fails the request with 500
 */
final class VersionMismatchException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(
            'the stored version of the resource is not one the precondition of the write admits',
            0,
            $previous,
        );
    }
}
