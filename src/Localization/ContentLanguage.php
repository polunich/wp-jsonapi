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

namespace Polunich\WpJsonApi\Localization;

/**
 * the port a project implements to choose and apply the language of the response content
 */
interface ContentLanguage
{
    /**
     * resolves and applies the locale, and returns its tag for `Content-Language`;
     * null writes no such field, and an error response carries none whatever the tag.
     *
     * `LanguagePreferences::select()` picks a tag by the matching rules of HTTP;
     * with no match the preferences may be disregarded (RFC 9110 12.4.1).
     *
     * nothing checks the tag, so it must be an RFC 5646 language tag as returned:
     * it is the project's statement about its own content,
     * while a range read from the client is checked by `LanguageRange`
     */
    public function negotiate(LanguagePreferences $preferences): ?string;
}
