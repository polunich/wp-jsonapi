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
 * the codec of a path parameter, such as the id of a resource type:
 * a codec of strings that also gives the pattern of one path segment.
 * `DigitsCodec` and `UuidCodec` are built in; a project implements this interface for other ids.
 *
 * the pattern stands in the route pattern of the parameter and tells routes of one shape apart:
 * WordPress picks the first registered route whose pattern matches the path,
 * so a segment the pattern refuses matches no route of that shape, and the client gets a 404.
 * `decode()` reads the percent-decoded segment and has the last word:
 * it may refuse a segment the pattern admits.
 *
 * the pattern is PCRE without delimiters, anchors or flags.
 * it sees the segment in one form, whatever the client sent and whichever permalinks the site uses:
 * every unreserved character of RFC 3986 as itself,
 * every other octet percent-encoded with upper-case hex digits, as `rawurlencode()` writes it.
 * so `%31` reaches the pattern as `1`,
 * and a codec of values with a reserved or a non-ASCII character matches its percent-encoded form.
 * WordPress matches a route pattern without the `u` flag and ignoring case,
 * so the pattern reads bytes and cannot tell letter case apart.
 *
 * the pattern compiles on its own, matches no empty segment and never matches `/`.
 * it holds no capturing group: WordPress hands a named group over as a route parameter,
 * and inside a route pattern a group is numbered after the groups before it,
 * so a reference such as `\1` would name another group.
 * it holds no `@` without a backslash: WordPress delimits a route pattern with `@`,
 * so `\@` or `\x40` match the character.
 * every rule but the `/` one is checked when a declaration that uses the codec is built
 *
 * @extends Codec<string>
 */
interface SegmentCodec extends Codec
{
    /**
     * @return non-empty-string such as `0|[1-9][0-9]*`
     */
    public function segmentPattern(): string;
}
