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

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class WriteRule
{
    /**
     * @throws InvalidArgumentException when the field is required on create and not creatable
     */
    public function __construct(
        public bool $creatable,
        public bool $updatable,
        public bool $required,
    ) {
        if ($required && !$creatable) {
            throw new InvalidArgumentException(
                'a field required on create is creatable, since a create that sends a field that is not creatable'
                . ' is refused and so would every create',
            );
        }
    }

    public static function readOnly(): self
    {
        return new self(false, false, false);
    }
}
