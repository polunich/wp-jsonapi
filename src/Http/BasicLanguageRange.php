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
 * a basic language range without `*`: `1*8ALPHA *("-" 1*8alphanum)` (RFC 4647 2.1);
 * the one definition of that grammar in the library
 *
 * @internal
 */
final readonly class BasicLanguageRange
{
    /**
     * without delimiters and anchors, so another pattern can embed it;
     * it holds nothing PCRE and ECMA-262 read differently
     */
    public const GRAMMAR = '[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*';

    private const WHOLE = '/^' . self::GRAMMAR . '$/D';

    public static function isValid(string $range): bool
    {
        return preg_match(self::WHOLE, $range) === 1;
    }
}
