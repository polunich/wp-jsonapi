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
 * the rules `SegmentCodec` sets for a segment pattern that a program can check.
 * whether a pattern never matches `/` it cannot: that is a question about every string.
 *
 * the pattern is compiled without flags, as a route pattern reads bytes.
 * it is compiled on its own first: inside a group, `a)|(b` would compile and change the group,
 * and a verb such as `(*UTF)` compiles only at the start of a whole pattern.
 * then it is compiled inside `@` delimiters, as WordPress compiles a route pattern.
 * PHP ends a pattern at the first delimiter no backslash escapes,
 * and the two compilations differ in the delimiter alone,
 * so a pattern that passes the first and fails the second holds an `@` without a backslash.
 * a capturing group is found by matching the group or the empty string against the empty string:
 * with `PREG_UNMATCHED_AS_NULL`, `preg_match()` reports every group, matched or not,
 * a named one under its name as well
 *
 * @internal
 */
final readonly class SegmentPattern
{
    /**
     * BEL as the delimiter leaves every printable character of a pattern unescaped
     */
    private const DELIMITER = "\x07";

    /**
     * the delimiter of the route pattern WordPress compiles
     */
    private const ROUTE_DELIMITER = '@';

    /**
     * @param string $parameter what the codec reads, for the message,
     *                          such as `the id of type "pets"`
     *
     * @throws InvalidArgumentException when the pattern does not compile,
     *                                  holds an `@` without a backslash, matches the empty segment,
     *                                  or holds a capturing group
     */
    public static function check(SegmentCodec $codec, string $parameter): void
    {
        $pattern = $codec->segmentPattern();

        if (
            @preg_match(self::DELIMITER . $pattern . self::DELIMITER, '') === false
            || @preg_match(self::anchored($pattern, self::DELIMITER), '') === false
        ) {
            throw new InvalidArgumentException(self::message(
                $parameter,
                $codec,
                $pattern,
                'is no pattern PCRE compiles as one path segment',
            ));
        }

        if (@preg_match(self::anchored($pattern, self::ROUTE_DELIMITER), '') === false) {
            throw new InvalidArgumentException(self::message(
                $parameter,
                $codec,
                $pattern,
                'holds an "@" without a backslash, and WordPress delimits a route pattern with "@", so the route'
                . ' pattern would end there: write "\@" or "\x40"',
            ));
        }

        if (preg_match(self::anchored($pattern, self::DELIMITER), '') === 1) {
            throw new InvalidArgumentException(self::message(
                $parameter,
                $codec,
                $pattern,
                'matches the empty segment, and a path parameter is never empty',
            ));
        }

        preg_match(self::DELIMITER . '(?:' . $pattern . ')|' . self::DELIMITER, '', $groups, PREG_UNMATCHED_AS_NULL);

        foreach (array_keys($groups) as $group) {
            if (is_string($group)) {
                throw new InvalidArgumentException(self::message(
                    $parameter,
                    $codec,
                    $pattern,
                    sprintf(
                        'holds the named group "%s", and WordPress hands every named group of a route over as a'
                        . ' route parameter',
                        $group,
                    ),
                ));
            }
        }

        // the whole match is group 0; any other group is one the pattern captures
        if (count($groups) > 1) {
            throw new InvalidArgumentException(self::message(
                $parameter,
                $codec,
                $pattern,
                'holds a capturing group, and inside a route pattern a group is numbered after the groups before'
                . ' it, so a reference to it would name another group: write a non-capturing group "(?:...)"',
            ));
        }
    }

    private static function anchored(string $pattern, string $delimiter): string
    {
        return $delimiter . '^(?:' . $pattern . ')$' . $delimiter . 'D';
    }

    private static function message(string $parameter, SegmentCodec $codec, string $pattern, string $reason): string
    {
        return sprintf(
            '%s is read by %s, whose segment pattern "%s" %s',
            $parameter,
            get_debug_type($codec),
            Printable::of($pattern),
            $reason,
        );
    }
}
