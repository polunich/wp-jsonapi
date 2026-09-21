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

namespace Polunich\WpJsonApi\Routing;

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a parameter segment of a path template: the name WordPress hands the segment over under,
 * and the codec whose pattern stands in the route pattern and which reads the segment.
 *
 * the name is a named group of the WordPress route pattern,
 * so it follows the PCRE rule for a group name: ASCII letters, digits and `_`, the first no digit.
 * PCRE2 compiles such a name up to 32 characters before 10.44 and up to 128 from it,
 * and PHP 8.3 carries 10.42,
 * so a name holds at most 32: one rule on every PHP the library supports.
 *
 * the pattern of the codec is checked by `SegmentPattern`, not here
 *
 * @internal
 */
final readonly class PathParameter
{
    private const NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,31}$/D';

    /**
     * @throws InvalidArgumentException when the name breaks the rule of a group name
     */
    public function __construct(
        public string $name,
        public SegmentCodec $codec,
    ) {
        self::checkName($name, 'a path parameter');
    }

    /**
     * @param string $parameter what carries the name, for the message,
     *                          such as `the id parameter of type "pets"`
     *
     * @throws InvalidArgumentException when the name breaks the rule of a group name
     */
    public static function checkName(string $name, string $parameter): void
    {
        if (preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException(sprintf(
                '%s is named "%s", and a path parameter is named by a letter or "_" and at most 31 more letters,'
                . ' digits or "_": WordPress hands it over as a named group of its route pattern, and PCRE2 before'
                . ' 10.44, which PHP 8.3 carries, compiles a group name of at most 32 characters',
                $parameter,
                Printable::of($name),
            ));
        }
    }
}
