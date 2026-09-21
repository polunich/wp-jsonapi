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

namespace Polunich\WpJsonApi\Codec;

/**
 * `$pointer` is relative to the value `decode()` was given:
 * it locates the member inside that value, not where the request carried it.
 *
 * `$code` is a stable lower_snake code that clients match on, so changing one breaks clients.
 * it is a string, not a `ViolationCode`:
 * a project raises codes of its own through `RejectedValueException`
 */
final readonly class Violation
{
    /**
     * @param array<string, scalar> $parameters the values the message of the code names
     */
    public function __construct(
        public Pointer $pointer,
        public string $code,
        public array $parameters = [],
    ) {}
}
