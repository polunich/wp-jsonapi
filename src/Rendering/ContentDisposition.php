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

namespace Polunich\WpJsonApi\Rendering;

/**
 * the `Content-Disposition` of a content a client saves (RFC 6266), following its appendix D.
 * a name without a byte `PLAIN` matches goes into `filename` alone;
 * any other goes into `filename*` in UTF-8,
 * "because at least one existing implementation only implements that encoding",
 * after a `filename` fallback with `_` for each byte `PLAIN` matches,
 * since "filename" should occur first
 *
 * @internal
 */
final readonly class ContentDisposition
{
    /**
     * matches a byte a quoted `filename` does not carry safely (RFC 6266, appendix D):
     * one outside printable ASCII, `"` or `\`, whose escaping some recipients lack,
     * or `%`, which some read as an escape
     */
    private const PLAIN = '/[^\x20\x21\x23\x24\x26-\x5B\x5D-\x7E]/';

    /** the attr-char of RFC 8187: ALPHA, DIGIT and ``! # $ & + - . ^ _ ` | ~`` */
    private const ATTR_CHAR = '/[^A-Za-z0-9!#$&+\-.^_`|~]/';

    /**
     * @param string $name valid UTF-8, without control characters
     */
    public static function attachment(string $name): string
    {
        $fallback = (string) preg_replace(self::PLAIN, '_', $name);

        if ($fallback === $name) {
            return sprintf('attachment; filename="%s"', $name);
        }

        return sprintf(
            'attachment; filename="%s"; filename*=UTF-8\'\'%s',
            $fallback,
            preg_replace_callback(self::ATTR_CHAR, static function (array $match): string {
                return sprintf('%%%02X', ord($match[0]));
            }, $name),
        );
    }
}
