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

namespace Polunich\WpJsonApi\Tests\Fixtures\Error;

/**
 * a backed enum whose value holds `\xe9`, a Latin-1 byte of no UTF-8 sequence:
 * `json_encode()` writes a case as its value,
 * and `JSON_INVALID_UTF8_SUBSTITUTE` turns that byte into U+FFFD
 *
 * @internal
 */
enum LegacyLabel: string
{
    case Cafe = "caf\xe9";
}
