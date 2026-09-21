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
 * the target or the parent of a write, loaded before the write, is gone by the time it runs;
 * the write gets the same 404 as a record missing before the write.
 *
 * throw it whatever the precondition, If-Match `*` included,
 * and never `VersionMismatchException` for a record that is gone:
 * the spec makes a request to modify a missing resource a 404
 */
final class ResourceNotFoundException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('the resource the write targets, or whose relationship it writes, is gone', 0, $previous);
    }
}
