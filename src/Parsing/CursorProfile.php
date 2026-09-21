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

namespace Polunich\WpJsonApi\Parsing;

/**
 * the URIs of the cursor pagination profile, each spelled as the profile spells it:
 * the profile states itself with `http`, while the type links of its errors are `https`
 *
 * @internal
 */
final readonly class CursorProfile
{
    /**
     * the URI the profile states for itself, the spelling a response writes
     */
    public const URI = 'http://jsonapi.org/profiles/ethanresnick/cursor-pagination/';

    /**
     * the page of the profile, which the type links of its errors extend
     */
    private const PAGE = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination';

    /**
     * the spellings a request may name the profile in:
     * its own URI, the one of the jsonapi.org extensions list and the address of its page
     */
    public const SPELLINGS = [self::URI, self::PAGE, self::PAGE . '/'];

    public const MAX_SIZE_EXCEEDED = self::PAGE . '/max-size-exceeded';

    public const UNSUPPORTED_SORT = self::PAGE . '/unsupported-sort';
}
