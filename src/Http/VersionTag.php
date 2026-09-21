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

namespace Polunich\WpJsonApi\Http;

/**
 * the entity tag of one record version under one negotiation, and the version a tag stands for.
 *
 * a strong validator changes whenever the content of a 200 would (RFC 9110 8.8.1),
 * and for one URI that content varies with the version, the content language
 * and the applied ext and profile URIs, so the tag carries all four.
 * it is reversible, not a digest, so a precondition can be checked against a stored version,
 * and not the version verbatim, which may hold SP or DQUOTE.
 *
 * the opaque tag is `pct(version)`, then `'l` and `pct(language)` when there is a language,
 * then `'e` and `pct(uri)` per extension and `'p` and `pct(uri)` per profile, in order.
 * `pct()` keeps an attr-char (RFC 8187 3.2.1)
 * and writes any other octet as `%` and two upper-case hex digits (RFC 3986 2.1).
 * it never writes `'`, so the parts split apart again,
 * and never a backslash, which servers ought to avoid (RFC 9110 8.8.3).
 * examples: `""` under `uk` is `'luk`;
 * `2026-09-16 10:00:00` without a language is `2026-09-16%2010%3A00%3A00`
 *
 * @internal
 */
final readonly class VersionTag
{
    private const SEPARATOR = "'";

    private const LANGUAGE = self::SEPARATOR . 'l';

    private const EXTENSION = self::SEPARATOR . 'e';

    private const PROFILE = self::SEPARATOR . 'p';

    /** the token characters that are no attr-char (RFC 8187 3.2.1) */
    private const TOKEN_OCTETS_TO_ENCODE = "*'%";

    /**
     * @param string $version any octets, the empty string included
     */
    public static function encode(string $version, ?string $contentLanguage, MediaType $contentType): EntityTag
    {
        $opaque = self::pct($version);

        if ($contentLanguage !== null) {
            $opaque .= self::LANGUAGE . self::pct($contentLanguage);
        }

        foreach ($contentType->ext() as $uri) {
            $opaque .= self::EXTENSION . self::pct($uri);
        }

        foreach ($contentType->profile() as $uri) {
            $opaque .= self::PROFILE . self::pct($uri);
        }

        return new EntityTag($opaque);
    }

    /**
     * the version a tag stands for, or null unless `encode()` wrote exactly this tag
     * for this negotiation; a weak tag stands for none.
     *
     * `rawurldecode()` is lenient (lower-case hex, a raw `:` or `+`, an escaped attr-char),
     * so the version is encoded again and compared: one tag per version and negotiation
     */
    public static function decode(EntityTag $tag, ?string $contentLanguage, MediaType $contentType): ?string
    {
        if ($tag->weak) {
            return null;
        }

        $end = strpos($tag->value, self::SEPARATOR);
        $version = rawurldecode($end === false ? $tag->value : substr($tag->value, 0, $end));

        return self::encode($version, $contentLanguage, $contentType)->value === $tag->value ? $version : null;
    }

    private static function pct(string $value): string
    {
        $encoded = '';
        $length = strlen($value);

        for ($index = 0; $index < $length; ++$index) {
            $octet = $value[$index];
            $encoded .= self::isAttrChar($octet) ? $octet : sprintf('%%%02X', ord($octet));
        }

        return $encoded;
    }

    private static function isAttrChar(string $octet): bool
    {
        return FieldValueGrammar::isToken($octet) && !str_contains(self::TOKEN_OCTETS_TO_ENCODE, $octet);
    }
}
