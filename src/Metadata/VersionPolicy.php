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

use Closure;
use UnexpectedValueException;

/**
 * a version is the opaque string the strong entity tag of a record is built from.
 * the project's accessor must return a new value whenever a document of the record would change,
 * and never one the record had before. any string is a version, the empty one included
 *
 * @internal
 */
final readonly class VersionPolicy
{
    /**
     * @param bool $preconditionsRequired whether a conditional write without `If-Match` gets 428
     */
    public function __construct(
        private Closure $accessor,
        public bool $preconditionsRequired,
    ) {}

    /**
     * @throws UnexpectedValueException when the accessor returns what is no string
     */
    public function versionOf(object $record): string
    {
        $version = ($this->accessor)($record);

        if (!is_string($version)) {
            throw new UnexpectedValueException(sprintf(
                'the version accessor returned %s for a record of class %s, and a version is a string',
                get_debug_type($version),
                get_debug_type($record),
            ));
        }

        return $version;
    }
}
