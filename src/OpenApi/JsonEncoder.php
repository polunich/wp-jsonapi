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

namespace Polunich\WpJsonApi\OpenApi;

use JsonException;
use stdClass;

/**
 * encodes the contract so that one declaration gives the same bytes on every machine;
 * `json_encode` keeps the member order the builders wrote
 *
 * @internal
 */
final readonly class JsonEncoder
{
    /**
     * unicode stays escaped as `\uXXXX` on purpose: the file stays US-ASCII,
     * so no editor or transfer can change its bytes
     */
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * `json_encode` formats a float by the `serialize_precision` ini setting of the machine;
     * `-1` writes the shortest text that reads back as the same double.
     * every number schema carries float bounds, `PHP_FLOAT_MAX` where none is declared
     */
    private const PRECISION = '-1';

    /**
     * @throws JsonException on a string that is not valid UTF-8, or nesting deeper than 512 levels
     */
    public function encode(stdClass $document): string
    {
        $previous = ini_set('serialize_precision', self::PRECISION);

        try {
            return json_encode($document, self::FLAGS) . "\n";
        } finally {
            if ($previous !== false) {
                ini_set('serialize_precision', $previous);
            }
        }
    }
}
