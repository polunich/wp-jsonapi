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

namespace Polunich\WpJsonApi\Document;

/**
 * the `source` member of an error object.
 * it names exactly one of pointer, parameter and header, so only the three factories build one
 *
 * @internal
 */
final readonly class ErrorSource
{
    private function __construct(
        public ?string $pointer,
        public ?string $parameter,
        public ?string $header,
    ) {}

    /**
     * @param string $pointer a JSON Pointer (RFC 6901) into the request document,
     *                        such as `/data/attributes/name`
     */
    public static function pointer(string $pointer): self
    {
        return new self($pointer, null, null);
    }

    public static function parameter(string $parameter): self
    {
        return new self(null, $parameter, null);
    }

    public static function header(string $header): self
    {
        return new self(null, null, $header);
    }
}
