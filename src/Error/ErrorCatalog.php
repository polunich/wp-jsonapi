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

namespace Polunich\WpJsonApi\Error;

/**
 * words the title and the detail of an error object.
 *
 * the title depends on the code alone and the detail on the code and the values of one occurrence,
 * as a title "SHOULD NOT change from occurrence to occurrence of the problem,
 * except for purposes of localization" (JSON:API, Error Objects).
 *
 * null means the catalog has no text for the code, or for this occurrence of it,
 * and the lookup falls through to the next catalog; an empty string is a text.
 * the code is a string rather than an `ErrorCode`,
 * so that a catalog can word codes the library does not define
 */
interface ErrorCatalog
{
    public function title(string $code): ?string;

    /**
     * @param array<string, scalar> $parameters the values of the occurrence, by name
     */
    public function detail(string $code, array $parameters): ?string;
}
